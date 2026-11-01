param([Parameter(Mandatory=$true)][string]$PhpPath,[string]$SeedPath,[Parameter(Mandatory=$true)][string]$OutputPath)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.UTF8Encoding]::new($false)
if (-not (Test-Path -LiteralPath $OutputPath)) { New-Item -ItemType Directory -Path $OutputPath | Out-Null }
$dbServer = Read-Host 'Databaseserver frå Domeneshop'
$dbName = Read-Host 'Databasenamn (bruk ei ny, tom database)'
$dbUser = Read-Host 'Databasebrukar'
$dbSecret = Read-Host 'Databasepassord' -AsSecureString
$ownerSecret = Read-Host 'Ditt nye innloggingspassord for Plan (minst 14 teikn)' -AsSecureString
$dbPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($dbSecret)
$ownerPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($ownerSecret)
try {
    $installData = @{db_host=$dbServer;db_name=$dbName;db_user=$dbUser;db_pass=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($dbPointer);password=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($ownerPointer);output=[IO.Path]::GetFullPath($OutputPath)}
    if ($SeedPath) { $installData.seed = [IO.Path]::GetFullPath($SeedPath) }
    $installData | ConvertTo-Json -Depth 5 -Compress | & $PhpPath -n (Join-Path $PSScriptRoot 'prepare-install.php')
    if ($LASTEXITCODE -ne 0) { throw 'Oppsettsfilene vart ikkje laga.' }
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($dbPointer)
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ownerPointer)
    $installData = $null
}
