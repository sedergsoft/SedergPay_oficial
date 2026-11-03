# Inicia el ejemplo KYC manual-review de Ballerine con datos sintéticos.
# Ballerine usa por defecto 3000 (API), 5173 (collection UI) y 5137 (backoffice).
[CmdletBinding()]
param(
    [string]$InstallPath = (Join-Path (Split-Path $PSScriptRoot -Parent) ".local\ballerine")
)

$ErrorActionPreference = "Stop"
if (-not (Test-Path (Join-Path $InstallPath "package.json"))) {
    throw "Ballerine no está instalado. Ejecuta scripts/Install-BallerineKycLocal.ps1 primero."
}
if (-not (Get-Command pnpm -ErrorAction SilentlyContinue)) {
    throw "No se encontró pnpm."
}

$ports = @(3000, 5173, 5137)
$busy = @()
foreach ($port in $ports) {
    $listener = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($listener) { $busy += "$port (PID $($listener.OwningProcess))" }
}
if ($busy.Count -gt 0) {
    throw "Puertos requeridos por el ejemplo Ballerine ocupados: $($busy -join ', '). Detén los servicios que los usan y vuelve a intentarlo. El frontend SEDERG_PAY también publica 5173."
}

Write-Warning "Solo usa datos ficticios. No cargues pasaportes, selfies ni documentos reales."
Write-Warning "Este ejemplo puede iniciar dependencias Docker propias de Ballerine; no conecta automáticamente MySQL ni los expedientes de SEDERG_PAY."
Push-Location $InstallPath
try {
    pnpm kyc-manual-review-example
    if ($LASTEXITCODE -ne 0) { throw "El ejemplo KYC de Ballerine terminó con error." }
} finally {
    Pop-Location
}
