# Instala Ballerine en un directorio local aislado para evaluación KYC.
# No modifica docker-compose.yml ni las dependencias de SEDERG_PAY.
[CmdletBinding()]
param(
    [string]$InstallPath = ""
)

$ErrorActionPreference = "Stop"

# Resolver la ruta después del enlace de parámetros: $PSScriptRoot puede estar
# vacío al evaluar valores predeterminados dentro de param().
if ([string]::IsNullOrWhiteSpace($InstallPath)) {
    $scriptDirectory = $PSScriptRoot
    if ([string]::IsNullOrWhiteSpace($scriptDirectory)) {
        $invokedScript = $MyInvocation.MyCommand.Path
        if ([string]::IsNullOrWhiteSpace($invokedScript)) {
            throw "No se pudo determinar la ubicación del script. Ejecuta el archivo .ps1 desde su ruta completa."
        }
        $scriptDirectory = Split-Path -LiteralPath $invokedScript -Parent
    }
    $repositoryRoot = Split-Path -LiteralPath $scriptDirectory -Parent
    $InstallPath = Join-Path $repositoryRoot ".local\ballerine"
}
$repoUrl = "https://github.com/ballerine-io/ballerine.git"

function Require-Command([string]$Name) {
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "No se encontró '$Name'. Instala Git, Node.js y pnpm antes de continuar."
    }
}

Require-Command "git"
Require-Command "node"
Require-Command "pnpm"

$nodeVersion = [version]((node -p "process.versions.node").Trim())
if ($nodeVersion.Major -lt 18 -or $nodeVersion.Major -ge 22) {
    throw "Ballerine requiere Node >=18 y <22 según su package.json. Versión detectada: $nodeVersion"
}

$pnpmVersionText = (pnpm --version).Trim()
$pnpmVersion = [version]($pnpmVersionText -replace '[^0-9.].*$', '')
if ($pnpmVersion.Major -lt 8) {
    throw "Ballerine requiere pnpm >=8. Versión detectada: $pnpmVersionText"
}

$parent = Split-Path $InstallPath -Parent
New-Item -ItemType Directory -Force -Path $parent | Out-Null

if (-not (Test-Path (Join-Path $InstallPath ".git"))) {
    if (Test-Path $InstallPath) {
        throw "El destino existe y no es un repositorio Git: $InstallPath. Muévelo o elimínalo manualmente."
    }
    git clone $repoUrl $InstallPath
    if ($LASTEXITCODE -ne 0) { throw "Falló git clone de Ballerine." }
} else {
    git -C $InstallPath fetch origin
    if ($LASTEXITCODE -ne 0) { throw "Falló git fetch de Ballerine." }
    git -C $InstallPath status --short
    Write-Host "Repositorio existente detectado. No se sobrescribirán cambios locales."
}

$head = (git -C $InstallPath rev-parse HEAD).Trim()
$branch = (git -C $InstallPath branch --show-current).Trim()
Write-Host "Ballerine local: $InstallPath"
Write-Host "Rama: $branch"
Write-Host "Commit fijado para esta instalación: $head"

Push-Location $InstallPath
try {
    pnpm install
    if ($LASTEXITCODE -ne 0) { throw "Falló pnpm install." }
    pnpm monorepo:init
    if ($LASTEXITCODE -ne 0) { throw "Falló pnpm monorepo:init." }
} finally {
    Pop-Location
}

Write-Host ""
Write-Host "Instalación completada. No se han iniciado servicios ni se han usado datos personales."
Write-Host "Para iniciar el ejemplo KYC sintético, ejecuta scripts/Start-BallerineKycLocal.ps1."
