#requires -Version 5.1
<#
.SYNOPSIS
    Desactiva el simulador local de Ballerine KYC.

.DESCRIPTION
    Pone SEDERG_BALLERINE_ENABLED=0, recrea el backend y detiene el servicio
    kyc-mock. Conserva el volumen de MySQL, los expedientes y el secreto del
    webhook para poder reactivar el mock sin perder la configuración.
#>
[CmdletBinding()]
param(
    [string]$ProjectRoot
)

$ErrorActionPreference = 'Stop'

# Windows PowerShell 5.1 leaves $PSScriptRoot empty inside param defaults when
# CmdletBinding is present, so resolve the project root after binding.
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $scriptPath = $MyInvocation.MyCommand.Path
    if ([string]::IsNullOrWhiteSpace($scriptPath)) {
        throw 'No se pudo determinar la ruta del script. Ejecútalo desde el repositorio.'
    }
    $ProjectRoot = Split-Path -Parent (Split-Path -Parent $scriptPath)
}

$envPath = Join-Path $ProjectRoot '.env'
if (-not (Test-Path -LiteralPath $envPath)) {
    throw "No existe .env en '$ProjectRoot'."
}

function Set-DotEnvValue {
    param([string]$Content, [string]$Key, [string]$Value)
    $line = "$Key=$Value"
    $pattern = "(?m)^\s*$([regex]::Escape($Key))\s*=.*$"
    if ([regex]::IsMatch($Content, $pattern)) {
        return [regex]::Replace(
            $Content,
            $pattern,
            [System.Text.RegularExpressions.MatchEvaluator]{ param($m) $line }
        )
    }
    return $Content.TrimEnd() + [Environment]::NewLine + $line + [Environment]::NewLine
}

$content = [System.IO.File]::ReadAllText($envPath)
$content = Set-DotEnvValue $content 'SEDERG_BALLERINE_ENABLED' '0'
[System.IO.File]::WriteAllText(
    $envPath,
    $content,
    (New-Object System.Text.UTF8Encoding($false))
)

Push-Location $ProjectRoot
try {
    Write-Host 'Recreando el backend con Ballerine desactivado...' -ForegroundColor Cyan
    & docker compose up -d --force-recreate backend
    if ($LASTEXITCODE -ne 0) {
        throw 'Docker Compose no pudo recrear el backend.'
    }

    Write-Host 'Deteniendo el servicio kyc-mock...' -ForegroundColor Cyan
    & docker compose --profile kyc-mock stop kyc-mock
    if ($LASTEXITCODE -ne 0) {
        Write-Warning 'No se pudo detener kyc-mock; puede que no estuviera iniciado.'
    }

    Write-Host 'Ballerine desactivado. El flujo de carga documental de Yii2 sigue disponible.' -ForegroundColor Green
    Write-Host 'Los expedientes y el secreto se conservan en .env y MySQL.' -ForegroundColor DarkGray
}
finally {
    Pop-Location
}
