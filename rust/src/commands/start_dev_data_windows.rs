use super::{check_tree, destination, refused};
use std::ffi::{c_void, OsStr};
use std::fs::{File, OpenOptions};
use std::io;
use std::mem::size_of;
use std::os::windows::ffi::OsStrExt;
use std::os::windows::fs::OpenOptionsExt;
use std::os::windows::io::AsRawHandle;
use std::path::{Component, Path, PathBuf};
use std::ptr::null_mut;
use windows_sys::Win32::Foundation::{CloseHandle, LocalFree, HANDLE};
use windows_sys::Win32::Security::Authorization::{
    ConvertSidToStringSidW, ConvertStringSecurityDescriptorToSecurityDescriptorW, GetSecurityInfo,
    SDDL_REVISION_1, SE_FILE_OBJECT,
};
use windows_sys::Win32::Security::{
    GetAce, GetTokenInformation, TokenUser, ACCESS_ALLOWED_ACE, ACE_HEADER, ACL,
    DACL_SECURITY_INFORMATION, OWNER_SECURITY_INFORMATION, PSID, SECURITY_ATTRIBUTES, TOKEN_QUERY,
    TOKEN_USER,
};
use windows_sys::Win32::Storage::FileSystem::{
    CreateDirectoryW, GetFileInformationByHandle, BY_HANDLE_FILE_INFORMATION,
    FILE_ATTRIBUTE_DIRECTORY, FILE_ATTRIBUTE_REPARSE_POINT, FILE_FLAG_BACKUP_SEMANTICS,
    FILE_FLAG_OPEN_REPARSE_POINT, FILE_SHARE_READ, FILE_SHARE_WRITE,
};
use windows_sys::Win32::System::Threading::{GetCurrentProcess, OpenProcessToken};

pub struct Directory {
    pub path: PathBuf,
    handle: File,
    // Denying DELETE sharing pins every directory component. Windows refuses
    // parent/root renames while SQLite and relative payload state use them.
    _parents: Vec<File>,
}

struct Local(*mut c_void);
impl Drop for Local {
    fn drop(&mut self) {
        unsafe {
            LocalFree(self.0);
        }
    }
}
struct Token(HANDLE);
impl Drop for Token {
    fn drop(&mut self) {
        unsafe {
            CloseHandle(self.0);
        }
    }
}

fn sid_text(sid: PSID) -> io::Result<String> {
    let mut text = null_mut();
    if unsafe { ConvertSidToStringSidW(sid, &mut text) } == 0 {
        return Err(io::Error::last_os_error());
    }
    let _allocation = Local(text.cast());
    let mut count = 0;
    unsafe {
        while *text.add(count) != 0 {
            count += 1;
        }
        String::from_utf16(std::slice::from_raw_parts(text, count)).map_err(|_| refused())
    }
}

fn current_user() -> io::Result<String> {
    let mut token = null_mut();
    if unsafe { OpenProcessToken(GetCurrentProcess(), TOKEN_QUERY, &mut token) } == 0 {
        return Err(io::Error::last_os_error());
    }
    let token = Token(token);
    let mut size = 0;
    unsafe {
        GetTokenInformation(token.0, TokenUser, null_mut(), 0, &mut size);
    }
    if size == 0 {
        return Err(io::Error::last_os_error());
    }
    let mut buffer = vec![0_usize; (size as usize).div_ceil(size_of::<usize>())];
    if unsafe {
        GetTokenInformation(
            token.0,
            TokenUser,
            buffer.as_mut_ptr().cast(),
            size,
            &mut size,
        )
    } == 0
    {
        return Err(io::Error::last_os_error());
    }
    let user = unsafe { &*buffer.as_ptr().cast::<TOKEN_USER>() };
    sid_text(user.User.Sid)
}

fn create_private(path: &Path) -> io::Result<()> {
    let user = current_user()?;
    // Protected, inheritable ACL: only this account and SYSTEM can access the
    // new directory or native-created descendants. Never repair an existing ACL.
    let sddl: Vec<u16> = format!("O:{user}D:P(A;OICI;FA;;;{user})(A;OICI;FA;;;SY)")
        .encode_utf16()
        .chain([0])
        .collect();
    let mut descriptor = null_mut();
    if unsafe {
        ConvertStringSecurityDescriptorToSecurityDescriptorW(
            sddl.as_ptr(),
            SDDL_REVISION_1,
            &mut descriptor,
            null_mut(),
        )
    } == 0
    {
        return Err(io::Error::last_os_error());
    }
    let _allocation = Local(descriptor);
    let attributes = SECURITY_ATTRIBUTES {
        nLength: size_of::<SECURITY_ATTRIBUTES>() as u32,
        lpSecurityDescriptor: descriptor,
        bInheritHandle: 0,
    };
    let path: Vec<u16> = path.as_os_str().encode_wide().chain([0]).collect();
    if unsafe { CreateDirectoryW(path.as_ptr(), &attributes) } == 0 {
        let error = io::Error::last_os_error();
        if error.kind() != io::ErrorKind::AlreadyExists {
            return Err(error);
        }
    }
    Ok(())
}

fn info(file: &File) -> io::Result<BY_HANDLE_FILE_INFORMATION> {
    let mut info: BY_HANDLE_FILE_INFORMATION = unsafe { std::mem::zeroed() };
    if unsafe { GetFileInformationByHandle(file.as_raw_handle(), &mut info) } == 0 {
        return Err(io::Error::last_os_error());
    }
    if info.dwFileAttributes & FILE_ATTRIBUTE_REPARSE_POINT != 0 {
        return Err(refused());
    }
    Ok(info)
}

fn same(left: &File, right: &File) -> io::Result<()> {
    let left = info(left)?;
    let right = info(right)?;
    if (
        left.dwVolumeSerialNumber,
        left.nFileIndexHigh,
        left.nFileIndexLow,
    ) != (
        right.dwVolumeSerialNumber,
        right.nFileIndexHigh,
        right.nFileIndexLow,
    ) {
        return Err(refused());
    }
    Ok(())
}

fn private(file: &File, directory: bool) -> io::Result<()> {
    let info = info(file)?;
    if (info.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY != 0) != directory
        || (!directory && info.nNumberOfLinks != 1)
    {
        return Err(refused());
    }
    let user = current_user()?;
    let mut owner = null_mut();
    let mut acl: *mut ACL = null_mut();
    let mut descriptor = null_mut();
    let status = unsafe {
        GetSecurityInfo(
            file.as_raw_handle(),
            SE_FILE_OBJECT,
            OWNER_SECURITY_INFORMATION | DACL_SECURITY_INFORMATION,
            &mut owner,
            null_mut(),
            &mut acl,
            null_mut(),
            &mut descriptor,
        )
    };
    if status != 0 {
        return Err(io::Error::from_raw_os_error(status as i32));
    }
    let _allocation = Local(descriptor);
    if owner.is_null() || sid_text(owner)? != user || acl.is_null() {
        return Err(refused());
    }
    for index in 0..unsafe { (*acl).AceCount } {
        let mut ace = null_mut();
        if unsafe { GetAce(acl, u32::from(index), &mut ace) } == 0 {
            return Err(io::Error::last_os_error());
        }
        let header = unsafe { &*ace.cast::<ACE_HEADER>() };
        match header.AceType {
            0 => {
                // ACCESS_ALLOWED_ACE_TYPE
                let allowed = unsafe { &*ace.cast::<ACCESS_ALLOWED_ACE>() };
                let sid = sid_text((&allowed.SidStart as *const u32).cast_mut().cast())?;
                if sid != user && sid != "S-1-5-18" && sid != "S-1-5-32-544" {
                    return Err(refused());
                }
            }
            1 => {} // A deny ACE cannot expand access.
            _ => return Err(refused()),
        }
    }
    Ok(())
}

fn open_directory(path: &Path) -> io::Result<File> {
    let file = OpenOptions::new()
        .read(true)
        .share_mode(FILE_SHARE_READ | FILE_SHARE_WRITE)
        .custom_flags(FILE_FLAG_OPEN_REPARSE_POINT | FILE_FLAG_BACKUP_SEMANTICS)
        .open(path)?;
    if info(&file)?.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY == 0 {
        return Err(refused());
    }
    Ok(file)
}

impl Directory {
    pub fn prepare(path: &Path) -> io::Result<Self> {
        let path = destination(path)?;
        let mut prefix = PathBuf::new();
        let mut parents = Vec::new();
        let components: Vec<_> = path.components().collect();
        for (index, component) in components.iter().enumerate() {
            prefix.push(component.as_os_str());
            if matches!(component, Component::Prefix(_)) {
                continue;
            }
            if !prefix.exists() {
                create_private(&prefix)?;
            }
            let file = open_directory(&prefix)?;
            if index + 1 == components.len() {
                private(&file, true)?;
                return Ok(Self {
                    path,
                    handle: file,
                    _parents: parents,
                });
            }
            parents.push(file);
        }
        Err(refused())
    }

    pub fn verify(&self) -> io::Result<()> {
        private(&self.handle, true)?;
        same(&self.handle, &open_directory(&self.path)?)
    }

    pub fn enter(&self) -> io::Result<()> {
        self.verify()?;
        std::env::set_current_dir(&self.path)?;
        same(&self.handle, &open_directory(Path::new("."))?)
    }

    pub fn file(&self, name: &OsStr, create: bool) -> io::Result<File> {
        self.verify()?;
        let file = OpenOptions::new()
            .read(true)
            .write(true)
            .create(create)
            .truncate(false)
            .share_mode(FILE_SHARE_READ | FILE_SHARE_WRITE)
            .custom_flags(FILE_FLAG_OPEN_REPARSE_POINT)
            .open(self.path.join(name))?;
        private(&file, false)?;
        Ok(file)
    }

    pub fn same_file(&self, name: &OsStr, held: &File) -> io::Result<()> {
        private(held, false)?;
        same(held, &self.file(name, false)?)
    }

    pub fn child(&self, name: &OsStr) -> io::Result<Self> {
        self.verify()?;
        let path = self.path.join(name);
        let handle = open_directory(&path)?;
        private(&handle, true)?;
        Ok(Self {
            path,
            handle,
            _parents: vec![],
        })
    }

    pub fn check_tree(&self) -> io::Result<()> {
        check_tree(self)
    }
}
