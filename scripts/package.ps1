[CmdletBinding()]
param([string] $Destination = (Join-Path $PSScriptRoot '../../output/modular-code-studio.zip'))

$ErrorActionPreference = 'Stop'
$studioRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$studioZip = [IO.Path]::GetFullPath($Destination)
$temporaryZip = "$studioZip.$([guid]::NewGuid().ToString('N')).tmp"

Add-Type -AssemblyName System.IO.Compression.FileSystem
[void] [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($studioZip))

$directories = @('build', 'includes', 'runtime', 'src', 'vendor', 'licenses')
$files = @('modular-code-studio.php', 'README.md', 'CHANGELOG.md', 'TEST-REPORT.md', 'LICENSE', 'package.json', 'package-lock.json', 'composer.json', 'composer.lock', 'webpack.config.js')
$entries = @(
    foreach ($file in $files) {
        Get-Item -LiteralPath (Join-Path $studioRoot $file)
    }
    foreach ($directory in $directories) {
        Get-ChildItem -LiteralPath (Join-Path $studioRoot $directory) -File -Recurse | Where-Object {
            $_.FullName -notmatch '[\\/]vendor[\\/].*[\\/](doc|tests|examples)[\\/]' -and $_.Name -ne 'phpcs.xml'
        }
    }
)

try {
    $archive = [IO.Compression.ZipFile]::Open($temporaryZip, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($entry in $entries | Sort-Object FullName) {
            $relative = [IO.Path]::GetRelativePath($studioRoot, $entry.FullName).Replace('\', '/')
            [void] [IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive, $entry.FullName, "modular-code-studio/$relative", [IO.Compression.CompressionLevel]::Optimal
            )
        }
    } finally {
        $archive.Dispose()
    }
    [IO.File]::Move($temporaryZip, $studioZip, $true)
} finally {
    if (Test-Path -LiteralPath $temporaryZip) {
        Remove-Item -LiteralPath $temporaryZip
    }
}

Get-Item -LiteralPath $studioZip
Get-FileHash -LiteralPath $studioZip -Algorithm SHA256
