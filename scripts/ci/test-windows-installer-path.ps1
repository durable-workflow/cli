$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# Parse the installer, but execute only its PATH policy after replacing both
# .NET environment calls with in-memory call recorders. No installation,
# registry access, profile update or network operation is performed here.
$installer = Join-Path $PSScriptRoot '../install.ps1'
$tokens = $null
$errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile(
    $installer, [ref]$tokens, [ref]$errors)
if ($errors.Count -ne 0) { throw 'PowerShell installer has a syntax error' }
$condition = 'if ($env:DURABLE_WORKFLOW_INSTALL_NO_PATH_UPDATE -ne ''1'')'
$guard = $ast.Find({ param($node)
    $node -is [System.Management.Automation.Language.IfStatementAst] -and
    $node.Extent.Text.StartsWith($condition)
}, $true)
if ($null -eq $guard) { throw 'Missing exact1 nonpersistent PATH guard' }

$readCall = '[Environment]::GetEnvironmentVariable(''Path'', ''User'')'
$writeCall = '[Environment]::SetEnvironmentVariable(''Path'', (($paths + $installDir) -join '';''), ''User'')'

function Get-FakeUserPath {
    $script:reads += 1
    return $script:existingPath
}
function Set-FakeUserPath($value) {
    $script:writes += 1
    $script:writtenPath = $value
}
function Write-FixtureMessage { param($Object, $ForegroundColor) }

function Invoke-RecordedPolicy([string]$source, [string]$flag, [string]$existing) {
    if ([regex]::Matches($source, [regex]::Escape($readCall)).Count -ne 1 -or
        [regex]::Matches($source, [regex]::Escape($writeCall)).Count -ne 1) {
        throw 'PATH fixture does not bind exactly the original read and write calls'
    }
    $mocked = $source.Replace($readCall, '(Get-FakeUserPath)').Replace(
        $writeCall, 'Set-FakeUserPath (($paths + $installDir) -join '';'')')
    $mocked = $mocked.Replace('Write-Host ', 'Write-FixtureMessage ')
    if ($mocked -match '\[(?:System\.)?Environment\]\s*::') { throw 'Unmocked environment access in PATH control' }
    $script:reads = 0
    $script:writes = 0
    $script:writtenPath = $null
    $script:existingPath = $existing
    $installDir = 'C:\temporary-fixture\bin'
    if ($flag -eq '') { Remove-Item Env:DURABLE_WORKFLOW_INSTALL_NO_PATH_UPDATE -ErrorAction SilentlyContinue }
    else { $env:DURABLE_WORKFLOW_INSTALL_NO_PATH_UPDATE = $flag }
    & ([scriptblock]::Create($mocked))
    return @{ reads = $script:reads; writes = $script:writes; written = $script:writtenPath }
}

# Exact prior policy body, excluding only the newly added outer condition.
$legacy = $guard.Clauses[0].Item2.Statements.Extent.Text -join "`n"
$old = Invoke-RecordedPolicy $legacy '1' 'C:\existing-fixture'
if ($old.reads -ne 1 -or $old.writes -ne 1) {
    throw 'Unchanged policy did not reproduce persistent PATH modification with the opt-out request'
}

foreach ($flag in @('', '0', '1', '01', 'true', ' 1 ')) {
    foreach ($present in @($false, $true)) {
        $existing = if ($present) { 'C:\temporary-fixture\bin' } else { 'C:\existing-fixture' }
        $result = Invoke-RecordedPolicy $guard.Extent.Text $flag $existing
        $expectedReads = if ($flag -eq '1') { 0 } else { 1 }
        $expectedWrites = if ($flag -eq '1' -or $present) { 0 } else { 1 }
        if ($result.reads -ne $expectedReads -or $result.writes -ne $expectedWrites) {
            throw "Unexpected PATH calls for flag '$flag' and existing=$present"
        }
        if ($expectedWrites -eq 1 -and $result.written -ne 'C:\existing-fixture;C:\temporary-fixture\bin') {
            throw 'Default PATH append changed'
        }
    }
}
Write-Output 'Legacy write reproduced; twelve candidate PATH controls passed with in-memory call recorders.'
