$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$installer = if ($env:DW_TEST_WINDOWS_INSTALLER) { $env:DW_TEST_WINDOWS_INSTALLER } else {
    Join-Path $PSScriptRoot '../install.ps1'
}
$tokens = $null
$errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($installer, [ref]$tokens, [ref]$errors)
if ($errors.Count -ne 0) { throw 'PowerShell installer has a syntax error' }
$source = $ast.Extent.Text
$commands = $ast.FindAll({ param($node)
    $node -is [System.Management.Automation.Language.CommandAst] -and
    ($node.GetCommandName() -eq 'Invoke-WebRequest' -or $node.Extent.Text -eq '& $dest --version')
}, $true)
if ($commands.Count -ne 3) { throw 'Expected exactly two downloads and one executable invocation' }
# Run the actual installer control flow, replacing only public downloads and
# the final executable invocation. All filesystem and hash operations are real.
foreach ($command in @($commands | Sort-Object { $_.Extent.StartOffset } -Descending)) {
    $replacement = if ($command.GetCommandName() -eq 'Invoke-WebRequest') {
        $command.Extent.Text.Replace('Invoke-WebRequest', 'Receive-FixtureDownload')
    } else { 'Invoke-FixtureExecutable $dest --version' }
    $source = $source.Substring(0, $command.Extent.StartOffset) + $replacement +
        $source.Substring($command.Extent.EndOffset)
}
$mockedAst = [System.Management.Automation.Language.Parser]::ParseInput($source, [ref]$tokens, [ref]$errors)
if ($errors.Count -ne 0) { throw 'Mocked installer has a syntax error' }
$guards = $mockedAst.FindAll({ param($node)
    $node -is [System.Management.Automation.Language.IfStatementAst] -and
    $node.Clauses[0].Item1.Extent.Text.Contains('$expectedSha256')
}, $true)
if ($guards.Count -ne 2) { throw 'Expected exactly the canonical-pin and pre-execution digest guards' }
$legacySource = $source
foreach ($guard in @($guards | Sort-Object { $_.Extent.StartOffset } -Descending)) {
    $legacySource = $legacySource.Remove($guard.Extent.StartOffset, $guard.Extent.EndOffset - $guard.Extent.StartOffset)
}

function Receive-FixtureDownload {
    param($Uri, $OutFile, [switch]$UseBasicParsing)
    $script:downloads += 1
    $prefix = if ($script:defaultChannel) { 'latest/download' } else { 'download/2.3.7' }
    if ($Uri -eq "https://fixture.invalid/releases/$prefix/SHA256SUMS") {
        Copy-Item $script:manifest $OutFile
    } elseif ($Uri -eq "https://fixture.invalid/releases/$prefix/dw-windows-x86_64.exe") {
        Copy-Item $script:payload $OutFile
    } else { throw 'Unexpected fixture download' }
}
function Invoke-FixtureExecutable {
    param($filename, $versionArgument)
    if ($filename -ne (Join-Path $env:DURABLE_WORKFLOW_INSTALL_DIR 'dw.exe') -or $versionArgument -ne '--version') {
        throw 'Unexpected executable invocation'
    }
    $script:invocations += 1
}

$directory = Join-Path ([IO.Path]::GetTempPath()) ('dw-installer-digest-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $directory | Out-Null
try {
    $script:payload = Join-Path $directory 'payload'
    $script:manifest = Join-Path $directory 'SHA256SUMS'
    $qualified = Join-Path $directory 'qualified'
    [IO.File]::WriteAllText($qualified, 'qualified synthetic executable')
    $qualifiedHash = (Get-FileHash -Algorithm SHA256 $qualified).Hash.ToLowerInvariant()
    $env:DURABLE_WORKFLOW_INSTALL_DIR = Join-Path $directory 'bin'
    New-Item -ItemType Directory -Path $env:DURABLE_WORKFLOW_INSTALL_DIR | Out-Null
    $env:DURABLE_WORKFLOW_INSTALL_NO_PATH_UPDATE = '1'
    $env:DURABLE_WORKFLOW_RELEASE_BASE_URL = 'https://fixture.invalid/releases'
    foreach ($default in @($false, $true)) {
        $script:defaultChannel = $default
        $env:VERSION = if ($default) { 'stable' } else { '2.3.7' }
        foreach ($case in @('raced', 'correct', 'unset', 'malformed', 'newline')) {
            $script:invocations = 0
            $script:downloads = 0
            $content = if ($case -eq 'raced') { 'wrong synthetic executable' } else { 'qualified synthetic executable' }
            [IO.File]::WriteAllText($script:payload, $content)
            $actualHash = (Get-FileHash -Algorithm SHA256 $script:payload).Hash.ToLowerInvariant()
            [IO.File]::WriteAllText($script:manifest, "$actualHash  dw-windows-x86_64.exe`n")
            $destination = Join-Path $env:DURABLE_WORKFLOW_INSTALL_DIR 'dw.exe'
            [IO.File]::WriteAllText($destination, 'original installed bytes')
            $env:DURABLE_WORKFLOW_INSTALL_EXPECTED_SHA256 = switch ($case) {
                'unset' { '' }
                'malformed' { 'not-a-digest' }
                'newline' { $qualifiedHash + "`n" }
                default { $qualifiedHash }
            }
            if ($case -eq 'raced') {
                & ([scriptblock]::Create($legacySource))
                if ($script:invocations -ne 1 -or [IO.File]::ReadAllText($destination) -ne $content) {
                    throw 'Legacy installer did not expose the raced-payload execution and replacement'
                }
                [IO.File]::WriteAllText($destination, 'original installed bytes')
                $script:invocations = 0
                $script:downloads = 0
            }
            $failed = $false
            try { & ([scriptblock]::Create($source)) } catch { $failed = $true }
            $refused = $case -in @('raced', 'malformed', 'newline')
            if ($failed -ne $refused -or $script:invocations -ne $(if ($refused) { 0 } else { 1 })) {
                throw "Wrong execution boundary: case=$case default=$default calls=$script:invocations failed=$failed"
            }
            if ($refused -and [IO.File]::ReadAllText($destination) -ne 'original installed bytes') {
                throw 'Refused payload replaced the original installed file'
            }
            if ($case -in @('malformed', 'newline') -and $script:downloads -ne 0) {
                throw 'Malformed pin started a download'
            }
        }
    }
} finally { Remove-Item $directory -Recurse -Force }
Write-Output 'Two legacy admissions exposed; ten candidate Windows installer-flow controls passed with synthetic downloads and recorded executable calls.'
