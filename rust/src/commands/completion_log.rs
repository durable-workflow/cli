//! Completion debug logs live in private state and are read through the
//! opened handle. Neither creation nor following may traverse a log symlink.

use std::fs::{File, OpenOptions};
use std::io::{self, Read, Seek, SeekFrom, Write};
use std::path::PathBuf;

pub const ERROR: &str = "Cannot open the private shell completion debug log safely.";

fn directory() -> io::Result<PathBuf> {
    let config = crate::config::default_path().map_err(|_| io::ErrorKind::NotFound)?;
    let path = config
        .parent()
        .ok_or(io::ErrorKind::NotFound)?
        .join("completion");
    let mut builder = std::fs::DirBuilder::new();
    builder.recursive(true);
    #[cfg(unix)]
    {
        use std::os::unix::fs::DirBuilderExt;
        builder.mode(0o700);
    }
    builder.create(&path)?;
    Ok(path)
}

#[cfg(unix)]
pub fn open(name: &str) -> io::Result<File> {
    use std::ffi::CString;
    use std::os::fd::{AsRawFd, FromRawFd};
    use std::os::unix::fs::{MetadataExt, OpenOptionsExt};

    let dir = OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW | libc::O_DIRECTORY | libc::O_CLOEXEC)
        .open(directory()?)?;
    let metadata = dir.metadata()?;
    // O_NOFOLLOW protects the directory entry; owner/mode checks protect
    // against a pre-created shared directory. Use openat on this same fd
    // so replacing the path afterwards cannot redirect the file open.
    if metadata.uid() != unsafe { libc::geteuid() } || metadata.mode() & 0o077 != 0 {
        return Err(io::ErrorKind::PermissionDenied.into());
    }
    let filename =
        CString::new(format!("sf_{name}.log")).map_err(|_| io::ErrorKind::InvalidInput)?;
    let fd = unsafe {
        libc::openat(
            dir.as_raw_fd(),
            filename.as_ptr(),
            libc::O_RDWR
                | libc::O_CREAT
                | libc::O_APPEND
                | libc::O_NOFOLLOW
                | libc::O_NONBLOCK
                | libc::O_CLOEXEC,
            0o600,
        )
    };
    if fd < 0 {
        return Err(io::Error::last_os_error());
    }
    let file = unsafe { File::from_raw_fd(fd) };
    let metadata = file.metadata()?;
    // Nonblocking open avoids a FIFO wait before fstat. Reject hard links,
    // devices, shared files and a file belonging to another account.
    if !metadata.is_file()
        || metadata.uid() != unsafe { libc::geteuid() }
        || metadata.mode() & 0o077 != 0
        || metadata.nlink() != 1
    {
        return Err(io::ErrorKind::PermissionDenied.into());
    }
    Ok(file)
}

#[cfg(windows)]
pub fn open(name: &str) -> io::Result<File> {
    use std::os::windows::fs::{MetadataExt, OpenOptionsExt};
    // Opening the reparse point itself lets us refuse it without writing
    // through it. Default Windows ACLs inherit from the user's config home.
    let file = OpenOptions::new()
        .read(true)
        .append(true)
        .create(true)
        .custom_flags(0x0020_0000) // FILE_FLAG_OPEN_REPARSE_POINT
        .open(directory()?.join(format!("sf_{name}.log")))?;
    let metadata = file.metadata()?;
    if !metadata.is_file() || metadata.file_attributes() & 0x400 != 0 {
        return Err(io::ErrorKind::PermissionDenied.into());
    }
    Ok(file)
}

#[cfg(not(any(unix, windows)))]
pub fn open(_name: &str) -> io::Result<File> {
    Err(io::ErrorKind::Unsupported.into())
}

/// Tail the last ten lines and follow this descriptor, rather than
/// invoking a separate program that reopens the log by its path.
pub fn follow(mut file: File) -> io::Result<()> {
    let end = file.seek(SeekFrom::End(0))?;
    let mut start = end;
    let mut tail = Vec::new();
    while start > 0 && tail.iter().filter(|byte| **byte == b'\n').count() <= 10 {
        let count = start.min(8192);
        start -= count;
        file.seek(SeekFrom::Start(start))?;
        let mut chunk = vec![0; count as usize];
        file.read_exact(&mut chunk)?;
        chunk.extend(tail);
        tail = chunk;
    }
    let skip = tail
        .iter()
        .rev()
        .enumerate()
        .filter(|(_, byte)| **byte == b'\n')
        .nth(10)
        .map(|(offset, _)| tail.len() - offset)
        .unwrap_or(0);
    let mut stdout = io::stdout().lock();
    stdout.write_all(&tail[skip..])?;
    stdout.flush()?;
    file.seek(SeekFrom::Start(end))?;
    let mut buffer = [0; 8192];
    loop {
        match file.read(&mut buffer)? {
            0 => std::thread::sleep(std::time::Duration::from_millis(100)),
            count => {
                stdout.write_all(&buffer[..count])?;
                stdout.flush()?;
            }
        }
    }
}
