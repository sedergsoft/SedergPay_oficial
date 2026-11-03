#requires -Version 5.1
[CmdletBinding()]
param(
    [string]$ProjectRoot
)

$ErrorActionPreference = 'Stop'

# No calcular PSScriptRoot en el valor predeterminado del parámetro:
# durante el enlace de parámetros puede llegar vacío en algunas formas de invocación.
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $scriptFile = $PSCommandPath
    if ([string]::IsNullOrWhiteSpace($scriptFile)) {
        $scriptFile = $MyInvocation.MyCommand.Path
    }

    if (-not [string]::IsNullOrWhiteSpace($scriptFile)) {
        $scriptDirectory = Split-Path -Parent $scriptFile
        $ProjectRoot = Split-Path -Parent $scriptDirectory
    }
    else {
        # Último recurso para ejecución desde una sesión interactiva.
        $ProjectRoot = (Get-Location).Path
    }
}

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    throw 'No se pudo determinar la raíz del proyecto. Ejecuta el script desde su ubicación o indica -ProjectRoot.'
}

$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$envPath = Join-Path $ProjectRoot '.env'

if (-not (Test-Path -LiteralPath $envPath)) {
    throw "No existe .env en '$ProjectRoot'. Copia primero .env.example a .env y completa las credenciales obligatorias."
}

$content = [System.IO.File]::ReadAllText($envPath)
$match = [regex]::Match($content, '(?m)^SEDERG_ACCOUNT_ENCRYPTION_KEY=(.*)$')
$currentValue = if ($match.Success) { $match.Groups[1].Value.Trim().Trim('"') } else { '' }

# No rotar una clave ya configurada: los datos cifrados existentes dependen de ella.
if ($currentValue.Length -lt 32 -or $currentValue -match '^(replace-with-|change-this|your-)') {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($bytes)
    }
    finally {
        $rng.Dispose()
    }
    $newKey = ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant()
    [Array]::Clear($bytes, 0, $bytes.Length)

    $line = "SEDERG_ACCOUNT_ENCRYPTION_KEY=$newKey"
    if ($match.Success) {
        $content = [regex]::Replace(
            $content,
            '(?m)^SEDERG_ACCOUNT_ENCRYPTION_KEY=.*$',
            [System.Text.RegularExpressions.MatchEvaluator]{ param($m) $line },
            1
        )
    }
    else {
        $content = $content.TrimEnd() + [Environment]::NewLine + $line + [Environment]::NewLine
    }

    # UTF-8 sin BOM para compatibilidad con Docker Compose dotenv.
    [System.IO.File]::WriteAllText(
        $envPath,
        $content,
        (New-Object System.Text.UTF8Encoding($false))
    )
    Write-Host 'Se generó una nueva clave local de cifrado de cuentas (32 bytes).' -ForegroundColor Green
}
else {
    Write-Host 'Ya existe una clave de cifrado válida; se conserva sin cambios.' -ForegroundColor Green
}

Push-Location $ProjectRoot
try {
    Write-Host 'Recreando únicamente el servicio backend para cargar .env...' -ForegroundColor Cyan
    & docker compose up -d --force-recreate backend
    if ($LASTEXITCODE -ne 0) {
        throw 'Docker Compose no pudo recrear el backend.'
    }

    Write-Host 'Comprobando que el backend recibió la clave (sin mostrarla)...' -ForegroundColor Cyan
    & docker compose exec -T backend sh -c 'test "${#SEDERG_ACCOUNT_ENCRYPTION_KEY}" -ge 32'
    if ($LASTEXITCODE -ne 0) {
        throw 'El backend no recibió una clave de al menos 32 caracteres. Revisa docker-compose.yml y .env.'
    }

    Write-Host 'Configuración completada. La clave permanece únicamente en .env y no debe subirse a Git.' -ForegroundColor Green
}
finally {
    Pop-Location
}
