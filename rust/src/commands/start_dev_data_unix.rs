use super::{check_tree, destination, refused};
use std::ffi::{CString, OsStr};
use std::fs::{File, OpenOptions};
use std::io;
use std::os::fd::{AsRawFd, FromRawFd};
use std::os::unix::ffi::OsStrExt;
use std::os::unix::fs::{MetadataExt, OpenOptionsExt};
use std::path::{Component, Path, PathBuf};

pub struct Directory {
    pub path: PathBuf,
    handle: File,
    parents: Vec<(PathBuf, File)>,
}

fn open_directory(path: &Path) -> io::Result<File> {
    OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_DIRECTORY | libc::O_NOFOLLOW | libc::O_CLOEXEC)
        .open(path)
}

fn private(file: &File, directory: bool) -> io::Result<()> {
    let metadata = file.metadata()?;
    if metadata.uid() != unsafe { libc::geteuid() }
        || metadata.mode() & 0o077 != 0
        || if directory {
            !metadata.is_dir()
        } else {
            !metadata.is_file() || metadata.nlink() != 1
        }
    {
        return Err(refused());
    }
    Ok(())
}

fn same(left: &File, right: &File) -> io::Result<()> {
    let left = left.metadata()?;
    let right = right.metadata()?;
    if (left.dev(), left.ino()) != (right.dev(), right.ino()) {
        return Err(refused());
    }
    Ok(())
}

fn ancestor(file: &File) -> io::Result<()> {
    let metadata = file.metadata()?;
    // Sticky system temporary directories permit traversal without allowing a
    // different account to rename this user's entries. Other ancestors must
    // not grant untrusted writers authority over the selected directory.
    if !metadata.is_dir()
        || (metadata.uid() != 0 && metadata.uid() != unsafe { libc::geteuid() })
        || (metadata.mode() & 0o022 != 0 && metadata.mode() & 0o1000 == 0)
    {
        return Err(refused());
    }
    Ok(())
}

fn at(directory: &File, name: &OsStr, flags: i32) -> io::Result<File> {
    let name = CString::new(name.as_bytes()).map_err(|_| refused())?;
    let fd = unsafe {
        libc::openat(
            directory.as_raw_fd(),
            name.as_ptr(),
            flags | libc::O_NOFOLLOW | libc::O_NONBLOCK | libc::O_CLOEXEC,
            0o600,
        )
    };
    if fd < 0 {
        return Err(io::Error::last_os_error());
    }
    Ok(unsafe { File::from_raw_fd(fd) })
}

impl Directory {
    pub fn prepare(path: &Path) -> io::Result<Self> {
        // Called before Tokio/threads. Native-created WAL/payload files and
        // directories inherit the same private policy as the precreated DB.
        unsafe {
            libc::umask(0o077);
        }
        let path = destination(path)?;
        let mut prefix = PathBuf::from("/");
        let mut handle = open_directory(&prefix)?;
        let mut parents = Vec::new();
        let parts: Vec<_> = path
            .components()
            .filter_map(|part| match part {
                Component::Normal(name) => Some(name),
                _ => None,
            })
            .collect();
        for (index, name) in parts.iter().enumerate() {
            ancestor(&handle)?;
            let child = match at(&handle, name, libc::O_RDONLY | libc::O_DIRECTORY) {
                Ok(file) => file,
                Err(error) if error.kind() == io::ErrorKind::NotFound => {
                    let name_c = CString::new(name.as_bytes()).map_err(|_| refused())?;
                    if unsafe { libc::mkdirat(handle.as_raw_fd(), name_c.as_ptr(), 0o700) } < 0
                        && io::Error::last_os_error().kind() != io::ErrorKind::AlreadyExists
                    {
                        return Err(io::Error::last_os_error());
                    }
                    at(&handle, name, libc::O_RDONLY | libc::O_DIRECTORY)?
                }
                Err(error) => return Err(error),
            };
            parents.push((prefix.clone(), handle));
            prefix.push(name);
            handle = child;
            if index + 1 == parts.len() {
                private(&handle, true)?;
            }
        }
        let result = Self {
            path,
            handle,
            parents,
        };
        result.verify()?;
        Ok(result)
    }

    pub fn verify(&self) -> io::Result<()> {
        for (path, handle) in &self.parents {
            ancestor(handle)?;
            same(handle, &open_directory(path)?)?;
        }
        private(&self.handle, true)?;
        same(&self.handle, &open_directory(&self.path)?)
    }

    pub fn enter(&self) -> io::Result<()> {
        self.verify()?;
        if unsafe { libc::fchdir(self.handle.as_raw_fd()) } < 0 {
            return Err(io::Error::last_os_error());
        }
        same(&self.handle, &open_directory(Path::new("."))?)
    }

    pub fn file(&self, name: &OsStr, create: bool) -> io::Result<File> {
        self.verify()?;
        let file = at(
            &self.handle,
            name,
            libc::O_RDWR | if create { libc::O_CREAT } else { 0 },
        )?;
        private(&file, false)?;
        Ok(file)
    }

    pub fn same_file(&self, name: &OsStr, held: &File) -> io::Result<()> {
        private(held, false)?;
        same(held, &self.file(name, false)?)
    }

    pub fn child(&self, name: &OsStr) -> io::Result<Self> {
        self.verify()?;
        let handle = at(&self.handle, name, libc::O_RDONLY | libc::O_DIRECTORY)?;
        private(&handle, true)?;
        Ok(Self {
            path: self.path.join(name),
            handle,
            parents: vec![],
        })
    }

    pub fn check_tree(&self) -> io::Result<()> {
        check_tree(self)
    }
}
