//! The hidden packaged-runtime check. Executing the native binary is the
//! check; its identity names exactly which build and target were exercised.

use crate::cli::Input;
use crate::error::CliError;
use crate::{build_info, formatter};

pub const HELP: &str =
    "Report the native `dw` binary's version, commit, build date and compilation\n\
target. A successful invocation exits 0.\n\n\
<comment>Examples:</comment>\n\n\
  <info>dw runtime:check</info>\n\
  <info>dw runtime:check >/tmp/dw-runtime.txt</info>";

pub fn run(_input: &Input) -> Result<i32, CliError> {
    formatter::writeln_out(&format!("Rust runtime: {}", build_info::version_line()));
    formatter::writeln_out(&format!("Target: {}", build_info::target()));
    Ok(0)
}
