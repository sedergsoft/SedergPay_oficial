[CmdletBinding()]
param(
    [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot),
    [switch]$SkipBackup
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

function Invoke-DockerChecked {
    param([Parameter(Mandatory)][string[]]$Arguments)
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Docker command failed (exit $LASTEXITCODE): docker $($Arguments -join ' ')"
    }
}

function Read-DotEnvValue {
    param([string]$File, [string]$Name, [string]$Default = "")
    if (-not (Test-Path -LiteralPath $File)) { return $Default }
    $line = Get-Content -LiteralPath $File | Where-Object { $_ -match "^\s*$([regex]::Escape($Name))\s*=" } | Select-Object -Last 1
    if (-not $line) { return $Default }
    $value = ($line -replace "^\s*$([regex]::Escape($Name))\s*=", "").Trim()
    if ($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) {
        $value = $value.Substring(1, $value.Length - 2)
    }
    return $value
}

$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$envFile = Join-Path $ProjectRoot ".env"
$composeFile = Join-Path $ProjectRoot "docker-compose.yml"
$migrationCompose = Join-Path $ProjectRoot "docker-compose.migration.yml"

foreach ($requiredFile in @($envFile, $composeFile, $migrationCompose)) {
    if (-not (Test-Path -LiteralPath $requiredFile)) {
        throw "Required file not found: $requiredFile. Run this script from a complete repository checkout."
    }
}

$mysqlVolume = Read-DotEnvValue -File $envFile -Name "SEDERG_MYSQL_VOLUME" -Default "sederg_pay_sederg_mysql_data"
$mysqlPassword = Read-DotEnvValue -File $envFile -Name "SEDERG_MYSQL_ROOT_PASSWORD"
$pgPassword = Read-DotEnvValue -File $envFile -Name "SEDERG_DB_PASSWORD"

if ([string]::IsNullOrWhiteSpace($mysqlPassword) -or $mysqlPassword -match "replace-with|change-this|your-password") {
    throw "Set SEDERG_MYSQL_ROOT_PASSWORD in .env to the real password of the legacy MySQL database."
}
if ([string]::IsNullOrWhiteSpace($pgPassword) -or $pgPassword -match "replace-with|change-this|your-password") {
    throw "Set SEDERG_DB_PASSWORD in .env to the PostgreSQL application password."
}

Push-Location $ProjectRoot
try {
    Write-Host "Validating Docker Compose configuration..." -ForegroundColor Cyan
    Invoke-DockerChecked -Arguments @("compose", "-f", "docker-compose.yml", "-f", "docker-compose.migration.yml", "--profile", "migration", "config", "--quiet")

    $volumeExists = & docker volume inspect $mysqlVolume 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "Legacy MySQL volume '$mysqlVolume' was not found. Check docker volume ls and SEDERG_MYSQL_VOLUME in .env."
    }

    $confirmation = Read-Host "This will stop containers using '$mysqlVolume', back up the volume, create the PostgreSQL schema and import the data. Continue? (yes/no)"
    if ($confirmation -ne "yes") {
        Write-Host "Cancelled. No containers or data were changed."
        return
    }

    $runningConsumers = @(& docker ps -q --filter "volume=$mysqlVolume")
    if ($runningConsumers.Count -gt 0) {
        Write-Host "Stopping running containers attached to the legacy MySQL volume..." -ForegroundColor Yellow
        Invoke-DockerChecked -Arguments (@("stop") + $runningConsumers)
    }

    if (-not $SkipBackup) {
        $backupName = "sederg_mysql_backup_{0}.tar.gz" -f (Get-Date -Format "yyyyMMdd_HHmmss")
        Write-Host "Creating read-only volume backup: $backupName" -ForegroundColor Cyan
        Invoke-DockerChecked -Arguments @(
            "run", "--rm",
            "--mount", "type=volume,src=$mysqlVolume,dst=/source,readonly",
            "--mount", "type=bind,src=$ProjectRoot,dst=/backup",
            "alpine:3.22", "sh", "-ec", "tar czf /backup/$backupName -C /source . && test -s /backup/$backupName"
        )
        if (-not (Test-Path -LiteralPath (Join-Path $ProjectRoot $backupName))) {
            throw "Backup file was not created. Migration stopped."
        }
    } else {
        Write-Warning "Backup skipped by explicit request."
    }

    Write-Host "Starting PostgreSQL..." -ForegroundColor Cyan
    Invoke-DockerChecked -Arguments @("compose", "up", "-d", "postgres")

    $pgContainer = (& docker compose ps -q postgres).Trim()
    if (-not $pgContainer) { throw "PostgreSQL container was not created." }

    $healthy = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        $health = (& docker inspect --format "{{.State.Health.Status}}" $pgContainer 2>$null).Trim()
        if ($health -eq "healthy") { $healthy = $true; break }
        if ($health -eq "unhealthy") { break }
        Start-Sleep -Seconds 2
    }
    if (-not $healthy) { throw "PostgreSQL did not become healthy. Inspect: docker compose logs postgres" }

    Write-Host "Building backend and applying Yii migrations to PostgreSQL..." -ForegroundColor Cyan
    Invoke-DockerChecked -Arguments @("compose", "run", "--rm", "--build", "--no-deps", "backend", "php", "yii", "migrate", "--interactive=0")

    Write-Host "Copying the stopped MySQL volume to an isolated migration volume and importing records..." -ForegroundColor Cyan
    $migrationOutput = & docker compose -f docker-compose.yml -f docker-compose.migration.yml --profile migration up --build --abort-on-container-exit --exit-code-from db-migrator db-migrator 2>&1
    $migrationExitCode = $LASTEXITCODE
    $migrationOutput | ForEach-Object { Write-Host $_ }
    if ($migrationExitCode -ne 0 -or (([string]::Join([Environment]::NewLine, $migrationOutput)) -notmatch "DATA TRANSFER COMPLETED")) {
        throw "Data import did not complete successfully. Original MySQL volume and backup were preserved. Inspect migration logs before retrying."
    }

    Write-Host "Starting the complete SEDERG_PAY stack on PostgreSQL..." -ForegroundColor Cyan
    Invoke-DockerChecked -Arguments @("compose", "up", "-d")
    Invoke-DockerChecked -Arguments @("compose", "ps")

    Write-Host ""
    Write-Host "Migration completed. Keep the original MySQL volume and backup until financial reconciliation is signed off." -ForegroundColor Green
    Write-Host "Useful checks: docker compose logs --tail=150 backend postgres rate-updater"
}
finally {
    Pop-Location
}
