Param(
  [string]$PluginRoot
)

# Make a distributable ZIP of the plugin folder with versioned name, excluding VCS and build artefacts.
# Usage (PowerShell):
#   pwsh -File tools/releases/make-zip.ps1
#   pwsh -File tools/releases/make-zip.ps1 -PluginRoot "C:\laragon\www\ARSHLINE\wp-content\plugins\arshline"

$ErrorActionPreference = 'Stop'

if (-not $PluginRoot) {
  $PluginRoot = (Resolve-Path (Join-Path $PSScriptRoot '..' '..')).Path
}

if (-not (Test-Path $PluginRoot)) {
  throw "Plugin root not found: $PluginRoot"
}

$pluginFolderName = Split-Path $PluginRoot -Leaf
$arshlinePhp = Join-Path $PluginRoot 'arshline.php'
if (-not (Test-Path $arshlinePhp)) {
  throw "arshline.php not found in $PluginRoot"
}

# Extract version from plugin header
$versionMatch = Select-String -Path $arshlinePhp -Pattern '^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)'
if (-not $versionMatch) {
  throw "Cannot determine version from arshline.php"
}
$version = $versionMatch.Matches[0].Groups[1].Value

$outDir = Join-Path $PSScriptRoot 'out'
if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir | Out-Null }

$zipPath = Join-Path $outDir ("$pluginFolderName-$version.zip")

# Create staging directory to preserve proper folder structure and apply excludes
$stageDir = Join-Path $outDir ("stage_" + [Guid]::NewGuid().ToString('N'))
$dstRoot = Join-Path $stageDir $pluginFolderName
New-Item -ItemType Directory -Path $dstRoot -Force | Out-Null

# Exclude patterns (paths containing any of these regex fragments will be skipped)
$excludePatterns = @('\\\.git\\', '\\\.vscode\\', 'tools\\releases\\out\\')

Write-Host "Staging files from $PluginRoot ..."
Get-ChildItem -Path $PluginRoot -Recurse -Force | ForEach-Object {
  $full = $_.FullName
  # skip the stage/out dir itself if script is run from inside repo
  if ($full -like "$outDir*") { return }
  foreach ($pat in $excludePatterns) { if ($full -match $pat) { return } }
  $rel = $full.Substring($PluginRoot.Length).TrimStart('\\')
  $target = Join-Path $dstRoot $rel
  if ($_.PSIsContainer) {
    New-Item -ItemType Directory -Path $target -Force | Out-Null
  } else {
    $targetDir = Split-Path $target -Parent
    if (-not (Test-Path $targetDir)) { New-Item -ItemType Directory -Path $targetDir -Force | Out-Null }
    Copy-Item -LiteralPath $full -Destination $target -Force
  }
}

if (Test-Path $zipPath) { Remove-Item -LiteralPath $zipPath -Force }

Write-Host "Compressing to $zipPath ..."
Compress-Archive -Path $dstRoot -DestinationPath $zipPath -CompressionLevel Optimal

# Cleanup staging
Remove-Item -LiteralPath $stageDir -Recurse -Force

Write-Host "Done: $zipPath"