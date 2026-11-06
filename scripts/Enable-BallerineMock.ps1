#requires -Version 5.1
<#
.SYNOPSIS
    Activa el simulador local de Ballerine KYC en Docker Compose.

.DESCRIPTION
    Escribe la configuración SEDERG_BALLERINE_* en .env, levanta el servicio
    kyc-mock bajo el profile "kyc-mock", recrea el backend para que cargue las
    variables y aplica la migración de KYC del proveedor.

    Solo para desarrollo local. No uses este script en producción ni con
    documentos o identidades reales.
#>
[CmdletBinding()]
param(
    [string]$ProjectRoot,
    [int]$Port = 5174,
    [switch]$RegenerateSecret,
    [switch]$SkipMigration,
    [switch]$Force
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
    throw "No existe .env en '$ProjectRoot'. Copia .env.example a .env, ejecuta scripts/Initialize-LocalAccountEncryption.ps1 y vuelve a intentarlo."
}

if ($Port -lt 1024 -or $Port -gt 65535) {
    throw "El puerto $Port no es válido. Usa un puerto local entre 1024 y 65535."
}

function Get-DotEnvValue {
    param([string]$Content, [string]$Key)
    $match = [regex]::Match($Content, "(?m)^\s*$([regex]::Escape($Key))\s*=(.*)$")
    if (-not $match.Success) { return '' }
    return $match.Groups[1].Value.Trim().Trim('"').Trim("'")
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

$currentBaseUrl = Get-DotEnvValue $content 'SEDERG_BALLERINE_BASE_URL'
$currentEnabled = Get-DotEnvValue $content 'SEDERG_BALLERINE_ENABLED'
if (-not $Force -and $currentEnabled -eq '1' -and $currentBaseUrl -ne '' -and $currentBaseUrl -notmatch '^https?://kyc-mock(:|/|$)') {
    throw "Ya hay una configuración Ballerine no simulada en .env ($currentBaseUrl). Usa -Force solo si quieres sustituirla por el mock local."
}

$apiKey = Get-DotEnvValue $content 'SEDERG_BALLERINE_API_KEY'
if ($apiKey -eq '') { $apiKey = 'local-mock-api-key' }

$workflowId = Get-DotEnvValue $content 'SEDERG_BALLERINE_WORKFLOW_ID'
if ($workflowId -eq '') { $workflowId = 'kyc-manual-review' }

$webhookSecret = Get-DotEnvValue $content 'SEDERG_BALLERINE_WEBHOOK_SECRET'
if ($RegenerateSecret -or $webhookSecret.Length -lt 16 -or $webhookSecret -match '^(replace-with-|change-this|your-)') {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($bytes)
    }
    finally {
        $rng.Dispose()
    }
    $webhookSecret = ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant()
    [Array]::Clear($bytes, 0, $bytes.Length)
}

$appUrl = Get-DotEnvValue $content 'SEDERG_APP_URL'
if ($appUrl -eq '') { $appUrl = 'http://localhost:5173' }

$content = Set-DotEnvValue $content 'SEDERG_BALLERINE_ENABLED' '1'
$content = Set-DotEnvValue $content 'SEDERG_BALLERINE_BASE_URL' 'http://kyc-mock:3000'
$content = Set-DotEnvValue $content 'SEDERG_BALLERINE_API_KEY' $apiKey
$content = Set-DotEnvValue $content 'SEDERG_BALLERINE_WORKFLOW_ID' $workflowId
$content = Set-DotEnvValue $content 'SEDERG_BALLERINE_COLLECTION_URL' "http://localhost:$Port"
$content = Set-DotEnvValue $content 'SEDERG_BALLERINE_COLLECTION_URL_TEMPLATE' ''
$content = Set-DotEnvValue $content 'SEDERG_BALLERINE_WEBHOOK_SECRET' $webhookSecret
$content = Set-DotEnvValue $content 'SEDERG_BALLERINE_RETURN_URL' $appUrl
$content = Set-DotEnvValue $content 'SEDERG_BALLERINE_WEBHOOK_TOLERANCE_SECONDS' '3600'
$content = Set-DotEnvValue $content 'SEDERG_BALLERINE_MOCK_PORT' "$Port"

# UTF-8 sin BOM para compatibilidad con Docker Compose dotenv.
[System.IO.File]::WriteAllText(
    $envPath,
    $content,
    (New-Object System.Text.UTF8Encoding($false))
)

Write-Host 'Configuración del mock escrita en .env (sin mostrar el secreto).' -ForegroundColor Green
Write-Warning 'Solo datos sintéticos. El simulador no valida identidades ni sustituye a Ballerine.'

Push-Location $ProjectRoot
try {
    Write-Host 'Construyendo y arrancando el servicio kyc-mock...' -ForegroundColor Cyan
    & docker compose --profile kyc-mock up -d --build kyc-mock
    if ($LASTEXITCODE -ne 0) {
        throw 'Docker Compose no pudo arrancar kyc-mock.'
    }

    Write-Host 'Comprobando la salud del simulador...' -ForegroundColor Cyan
    $mockReady = $false
    for ($attempt = 1; $attempt -le 10; $attempt++) {
        & docker compose --profile kyc-mock exec -T kyc-mock wget -qO- http://127.0.0.1:3000/api/health | Out-Null
        if ($LASTEXITCODE -eq 0) { $mockReady = $true; break }
        Start-Sleep -Seconds 2
    }
    if (-not $mockReady) {
        throw 'kyc-mock no responde. Revisa `docker compose --profile kyc-mock logs kyc-mock` y que el puerto no esté ocupado.'
    }

    Write-Host 'Recreando el backend para cargar las variables de Ballerine...' -ForegroundColor Cyan
    & docker compose up -d --force-recreate backend
    if ($LASTEXITCODE -ne 0) {
        throw 'Docker Compose no pudo recrear el backend.'
    }

    if (-not $SkipMigration) {
        Write-Host 'Aplicando migraciones (con reintentos mientras MySQL termina de arrancar)...' -ForegroundColor Cyan
        $migrated = $false
        for ($attempt = 1; $attempt -le 10; $attempt++) {
            & docker compose exec -T backend php yii migrate --migrationPath=@app/migrations --interactive=0
            if ($LASTEXITCODE -eq 0) { $migrated = $true; break }
            Start-Sleep -Seconds 3
        }
        if (-not $migrated) {
            throw 'No se pudieron aplicar las migraciones. Revisa `docker compose logs backend` y ejecuta `docker compose exec backend php yii migrate --migrationPath=@app/migrations --interactive=0`.'
        }
    }

    Write-Host 'Verificando el estado del proveedor...' -ForegroundColor Cyan
    & docker compose exec -T backend php yii kyc/provider-status
    if ($LASTEXITCODE -ne 0) {
        throw 'El backend no reporta Ballerine listo. Revisa la salida anterior y .env.'
    }

    Write-Host ''
    Write-Host "Mock listo: http://localhost:$Port" -ForegroundColor Green
    Write-Host 'Flujo: inicia sesión en el portal, abre KYC y pulsa "Iniciar verificación".' -ForegroundColor Green
    Write-Host 'Después decide el caso como administrador en Administración > KYC.' -ForegroundColor Green
    Write-Host 'Si el portal no está levantado: docker compose up -d frontend' -ForegroundColor DarkGray
    Write-Host 'Para desactivarlo: scripts/Disable-BallerineMock.ps1' -ForegroundColor DarkGray
}
finally {
    Pop-Location
}
