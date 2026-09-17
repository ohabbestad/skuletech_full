param([string]$Destination)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$source = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$repo = [IO.Path]::GetFullPath((Join-Path $source '../..'))
if (-not $Destination) { $Destination = Join-Path $repo 'codex-temp-kantine-oppgradering' }
$destinationPath = [IO.Path]::GetFullPath($Destination)
if (-not $destinationPath.StartsWith($repo + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Pakka må byggjast i ei eiga mappe under prosjektet.'
}
if (Test-Path -LiteralPath $destinationPath) { throw 'Målmappa finst. Vel ei ny mappe for å unngå gamle filer i pakka.' }
$package = Join-Path $destinationPath 'pakke'
foreach ($folder in @('1-steng/api','3-filer/api','3-filer/assets','4-opne/api')) {
    New-Item -ItemType Directory -Path (Join-Path $package $folder) -Force | Out-Null
}
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'maintenance-index.php') -Destination (Join-Path $package '1-steng/api/index.php')
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'LES-MEG.txt') -Destination (Join-Path $package 'LES-MEG.txt')
Copy-Item -LiteralPath (Join-Path $source 'sql/migrations/002_phpmyadmin.sql') -Destination (Join-Path $package '2-database.sql')
foreach ($file in @('laerar.html','driftsleiar.html','tilsett.html')) {
    Copy-Item -LiteralPath (Join-Path $source $file) -Destination (Join-Path $package "3-filer/$file")
}
foreach ($file in @('app.js','model.js','kantine.css')) {
    Copy-Item -LiteralPath (Join-Path $source "assets/$file") -Destination (Join-Path $package "3-filer/assets/$file")
}
foreach ($file in @('config.php','db.php','domain.php','operations.php','read-model.php')) {
    Copy-Item -LiteralPath (Join-Path $source "api/$file") -Destination (Join-Path $package "3-filer/api/$file")
}
Copy-Item -LiteralPath (Join-Path $source 'api/index.php') -Destination (Join-Path $package '4-opne/api/index.php')
$archive = Join-Path $destinationPath 'KantineVeke-oppgradering.zip'
Compress-Archive -Path (Join-Path $package '*') -DestinationPath $archive
Write-Output $archive
