//! A private filesystem lease for the in-process development runtime.
//!
//! Runtime/SQLite opens relative paths. Keep the selected directory and its
//! database/lock handles alive, refuse linked or shared existing state before
//! opening the runtime, and recheck the lease at the startup/shutdown boundary.

use std::ffi::OsStr;
use std::fs::{self, File};
use std::io;
use std::path::{Path, PathBuf};

#[cfg(unix)]
#[path = "start_dev_data_unix.rs"]
mod platform;
#[cfg(windows)]
#[path = "start_dev_data_windows.rs"]
mod platform;

pub struct Data {
    directory: platform::Directory,
    lock: File,
    database: Option<File>,
}

impl Data {
    pub fn open(path: &Path) -> io::Result<Self> {
        let directory = platform::Directory::prepare(path)?;
        directory.check_tree()?;
        for name in [
            "server.lock",
            "database.sqlite",
            "database.sqlite-wal",
            "database.sqlite-shm",
            "database.sqlite-journal",
        ] {
            match directory.file(OsStr::new(name), false) {
                Ok(_) => {}
                Err(error) if error.kind() == io::ErrorKind::NotFound => {}
                Err(error) => return Err(error),
            }
        }
        let lock = directory.file(OsStr::new("server.lock"), true)?;
        lock.try_lock().map_err(|_| io::Error::new(
            io::ErrorKind::WouldBlock,
            "data directory is already in use or cannot be locked; stop its Server or choose another --data-dir",
        ))?;
        let data = Self {
            directory,
            lock,
            database: None,
        };
        data.verify()?;
        Ok(data)
    }

    pub fn path(&self) -> &Path {
        &self.directory.path
    }

    pub fn enter(&self) -> io::Result<()> {
        self.verify()?;
        self.directory.enter()?;
        self.verify()
    }

    // Called only after the listener is reserved. A busy port cannot create DB
    // state. Precreating the file also gives SQLite private permissions.
    pub fn prepare_database(&mut self) -> io::Result<()> {
        self.verify()?;
        self.directory.check_tree()?;
        self.database = Some(self.directory.file(OsStr::new("database.sqlite"), true)?);
        self.verify()
    }

    pub fn verify(&self) -> io::Result<()> {
        self.directory.verify()?;
        self.directory
            .same_file(OsStr::new("server.lock"), &self.lock)?;
        if let Some(database) = &self.database {
            self.directory
                .same_file(OsStr::new("database.sqlite"), database)?;
        }
        Ok(())
    }
}

fn refused() -> io::Error {
    io::Error::new(io::ErrorKind::PermissionDenied,
        "development data must be private, owned by this account and free of links or special files; choose a fresh --data-dir")
}

// Inspect the entire dedicated directory, including WAL/journals and relative
// payload state. Never repair permissions or unlink an entry on a refusal.
fn check_tree(directory: &platform::Directory) -> io::Result<()> {
    directory.verify()?;
    for entry in fs::read_dir(&directory.path)? {
        let entry = entry?;
        let name = entry.file_name();
        let kind = entry.file_type()?;
        if kind.is_symlink() || (!kind.is_dir() && !kind.is_file()) {
            return Err(refused());
        }
        if kind.is_dir() {
            let child = directory.child(&name)?;
            child.check_tree()?;
        } else {
            directory.file(&name, false)?;
        }
    }
    directory.verify()
}

// Resolve existing parents, but never resolve the selected final component.
// This permits OS-owned aliases such as macOS /var while rejecting a linked
// data directory itself. Platform traversal holds/checks the resolved parents.
fn destination(path: &Path) -> io::Result<PathBuf> {
    let absolute = std::path::absolute(path)?;
    let mut missing = Vec::new();
    let mut parent = absolute.parent().ok_or_else(refused)?;
    while !parent.exists() {
        missing.push(parent.file_name().ok_or_else(refused)?.to_owned());
        parent = parent.parent().ok_or_else(refused)?;
    }
    let mut result = parent.canonicalize()?;
    for name in missing.into_iter().rev() {
        result.push(name);
    }
    result.push(absolute.file_name().ok_or_else(refused)?);
    Ok(result)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::{AtomicU64, Ordering};

    struct Scratch(PathBuf);
    impl Scratch {
        fn new() -> Self {
            static NEXT: AtomicU64 = AtomicU64::new(0);
            let path = std::env::temp_dir().join(format!(
                "dw-private-data-{}-{}",
                std::process::id(),
                NEXT.fetch_add(1, Ordering::Relaxed)
            ));
            fs::create_dir(&path).unwrap();
            Self(path)
        }
    }
    impl Drop for Scratch {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.0);
        }
    }

    #[cfg(unix)]
    #[test]
    #[ignore = "requires a disposable root-owned test process"]
    fn foreign_owned_private_directory_is_refused_before_any_state_write() {
        use std::os::unix::ffi::OsStrExt;
        use std::os::unix::fs::PermissionsExt;
        assert_eq!(unsafe { libc::geteuid() }, 0);
        let root = Scratch::new();
        let directory = root.0.join("foreign");
        fs::create_dir(&directory).unwrap();
        fs::set_permissions(&directory, fs::Permissions::from_mode(0o700)).unwrap();
        let path = std::ffi::CString::new(directory.as_os_str().as_bytes()).unwrap();
        assert_eq!(unsafe { libc::chown(path.as_ptr(), 65534, 65534) }, 0);
        assert!(Data::open(&directory).is_err());
        assert_eq!(fs::read_dir(directory).unwrap().count(), 0);
    }

    #[test]
    fn held_database_refuses_a_replaced_entry() {
        let root = Scratch::new();
        let mut data = Data::open(&root.0.join("data")).unwrap();
        data.prepare_database().unwrap();
        let path = data.path().join("database.sqlite");
        let moved = data.path().join("moved.sqlite");
        #[cfg(unix)]
        {
            fs::rename(&path, &moved).unwrap();
            fs::write(&path, b"replacement").unwrap();
            assert!(data.verify().is_err());
            assert_eq!(fs::read(&moved).unwrap(), b"");
        }
        #[cfg(windows)]
        assert!(fs::rename(&path, &moved).is_err());
    }

    #[test]
    fn parent_replacement_cannot_redirect_the_held_directory() {
        let root = Scratch::new();
        let parent = root.0.join("parent");
        let data = Data::open(&parent.join("data")).unwrap();
        let moved = root.0.join("moved");
        #[cfg(unix)]
        {
            fs::rename(&parent, &moved).unwrap();
            fs::create_dir_all(parent.join("data")).unwrap();
            fs::write(parent.join("data/server.lock"), b"untouched").unwrap();
            assert!(data.enter().is_err());
            assert_eq!(
                fs::read(parent.join("data/server.lock")).unwrap(),
                b"untouched"
            );
            assert!(!parent.join("data/database.sqlite").exists());
        }
        #[cfg(windows)]
        assert!(fs::rename(&parent, &moved).is_err());
    }
}
