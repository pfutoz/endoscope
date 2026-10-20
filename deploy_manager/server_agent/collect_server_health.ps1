# ==============================================================================
# Server Health Collector Script for Windows (PowerShell 5.1+)
# Collects: SMART Status, Storage, Memory, CPU, OS Uptime, Apache Status
# Output: JSON file (default: c:\Apache24\htdocs\_server_health.json)
# ==============================================================================

param (
    [string]$TargetDir = "c:\Apache24\htdocs",
    [string]$OutputFileName = "_server_health.json"
)

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$ErrorActionPreference = "SilentlyContinue"


# Output destination
if (-not (Test-Path $TargetDir)) {
    $scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
    $parentDir = Split-Path -Parent $scriptDir
    if (Test-Path $parentDir) {
        $TargetDir = $parentDir
    } else {
        $TargetDir = "c:\Apache24\htdocs"
    }
}
$OutputFile = Join-Path $TargetDir $OutputFileName

$data = @{}
$data["version"] = "1.0"
$now = Get-Date
$data["collectedAt"] = $now.ToString("yyyy-MM-ddTHH:mm:sszzz")
$data["collectedAtFormatted"] = $now.ToString("yyyy/MM/dd HH:mm:ss")

# 1. Host & OS Info
$os = $null
try {
    $os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue
} catch {}

$lastBoot = if ($os) { $os.LastBootUpTime } else { (Get-Date) }
$uptime = (Get-Date) - $lastBoot
$uptimeText = "$($uptime.Days)d $($uptime.Hours)h $($uptime.Minutes)m"

$data["host"] = @{
    computerName = $env:COMPUTERNAME
    os = if ($os) { $os.Caption.Trim() } else { [System.Environment]::OSVersion.VersionString }
    version = if ($os) { $os.Version } else { "" }
    lastBootTime = $lastBoot.ToString("yyyy/MM/dd HH:mm:ss")
    uptimeDays = $uptime.Days
    uptimeHours = $uptime.Hours
    uptimeText = $uptimeText
}

# 2. Web Server (Apache) Status
$apacheService = $null
try {
    $apacheService = Get-Service -Name *apache* -ErrorAction SilentlyContinue | Select-Object -First 1
} catch {}

$port80 = $false
try {
    $netstat = netstat -ano -p tcp
    $port80 = [bool]($netstat -match ':(80|443)\s+.*LISTENING')
} catch {}

$serviceName = if ($apacheService) { $apacheService.Name } else { "Apache2.4" }
$serviceDisplayName = if ($apacheService) { $apacheService.DisplayName } else { "Apache HTTP Server" }
$serviceStatus = if ($apacheService) { $apacheService.Status.ToString() } else { "NotFound" }

$data["webServer"] = @{
    hasService = [bool]($apacheService)
    serviceName = $serviceName
    serviceDisplayName = $serviceDisplayName
    serviceStatus = $serviceStatus
    port80Listening = $port80
}

# 3. Storage: Physical Disks & SMART
$disks = @()
$predictFailures = @{}
try {
    $smartFailures = Get-CimInstance -Namespace root\wmi -ClassName MSStorageDriver_FailurePredictStatus -ErrorAction SilentlyContinue
    if ($smartFailures) {
        foreach ($sf in $smartFailures) {
            $predictFailures[$sf.InstanceName] = [bool]$sf.PredictFailure
        }
    }
} catch {}

$gotPDisks = $false
try {
    $pDisks = Get-PhysicalDisk -ErrorAction Stop
    if ($pDisks) {
        $gotPDisks = $true
        foreach ($d in $pDisks) {
            $temp = $null
            $wear = $null
            $readErr = $null
            $writeErr = $null
            try {
                $rel = $d | Get-StorageReliabilityCounter -ErrorAction SilentlyContinue
                if ($rel) {
                    if ($rel.Temperature -gt 0 -and $rel.Temperature -lt 120) {
                        $temp = [int]$rel.Temperature
                    }
                    if ($rel.Wear -ne $null) {
                        $wear = [int]$rel.Wear
                    }
                    $readErr = $rel.ReadErrorsTotal
                    $writeErr = $rel.WriteErrorsTotal
                }
            } catch {}

            $pFail = $false
            foreach ($inst in $predictFailures.Keys) {
                if ($inst -like "*$($d.DeviceId)*" -or $inst -like "*$($d.FriendlyName)*") {
                    $pFail = $predictFailures[$inst]
                }
            }

            $mType = if ($d.MediaType) { $d.MediaType.ToString() } else { "Unknown" }
            $hStat = if ($d.HealthStatus) { $d.HealthStatus.ToString() } else { "Healthy" }
            $opStat = if ($d.OperationalStatus) { ($d.OperationalStatus -join ", ") } else { "OK" }
            $wearRem = if ($wear -ne $null) { [math]::Max(0, (100 - $wear)) } else { $null }

            $diskObj = @{
                deviceId = [string]$d.DeviceId
                friendlyName = $d.FriendlyName
                mediaType = $mType
                sizeGB = [math]::Round($d.Size / 1GB, 1)
                healthStatus = $hStat
                operationalStatus = $opStat
                predictFailure = $pFail
                temperature = $temp
                wearRemainingPercent = $wearRem
                wearUsedPercent = $wear
                readErrors = $readErr
                writeErrors = $writeErr
            }
            $disks += $diskObj
        }
    }
} catch {}

if (-not $gotPDisks) {
    try {
        $wDisks = Get-CimInstance Win32_DiskDrive -ErrorAction SilentlyContinue
        if ($wDisks) {
            foreach ($wd in $wDisks) {
                $hStat = if ($wd.Status -eq "OK") { "Healthy" } else { "Warning" }
                $diskObj = @{
                    deviceId = [string]$wd.Index
                    friendlyName = $wd.Model
                    mediaType = "Unknown"
                    sizeGB = [math]::Round($wd.Size / 1GB, 1)
                    healthStatus = $hStat
                    operationalStatus = $wd.Status
                    predictFailure = $false
                    temperature = $null
                    wearRemainingPercent = $null
                    wearUsedPercent = $null
                    readErrors = $null
                    writeErrors = $null
                }
                $disks += $diskObj
            }
        }
    } catch {}
}

# 4. Storage: Volumes
$volumes = @()
try {
    $vols = Get-Volume -ErrorAction SilentlyContinue | Where-Object { $_.DriveLetter -ne $null -and $_.Size -gt 0 }
    if ($vols) {
        foreach ($v in $vols) {
            $totalGB = [math]::Round($v.Size / 1GB, 1)
            $freeGB = [math]::Round($v.SizeRemaining / 1GB, 1)
            $usedGB = [math]::Round(($v.Size - $v.SizeRemaining) / 1GB, 1)
            $usedPct = [math]::Round(($usedGB / $totalGB) * 100, 1)
            $status = "OK"
            if ($usedPct -ge 95 -or $freeGB -lt 10) { $status = "DANGER" }
            elseif ($usedPct -ge 85 -or $freeGB -lt 25) { $status = "WARN" }

            $lbl = if ($v.FileSystemLabel) { $v.FileSystemLabel } else { "Local Disk" }
            $volObj = @{
                driveLetter = "$($v.DriveLetter):"
                label = $lbl
                totalGB = $totalGB
                freeGB = $freeGB
                usedGB = $usedGB
                usedPercent = $usedPct
                status = $status
            }
            $volumes += $volObj
        }
    }
} catch {}

if ($volumes.Count -eq 0) {
    try {
        $lDisks = Get-CimInstance Win32_LogicalDisk -Filter "DriveType=3" -ErrorAction SilentlyContinue
        foreach ($ld in $lDisks) {
            $totalGB = [math]::Round($ld.Size / 1GB, 1)
            $freeGB = [math]::Round($ld.FreeSpace / 1GB, 1)
            $usedGB = [math]::Round($totalGB - $freeGB, 1)
            $usedPct = if ($totalGB -gt 0) { [math]::Round(($usedGB / $totalGB) * 100, 1) } else { 0 }
            $status = "OK"
            if ($usedPct -ge 95 -or $freeGB -lt 10) { $status = "DANGER" }
            elseif ($usedPct -ge 85 -or $freeGB -lt 25) { $status = "WARN" }

            $volumes += @{
                driveLetter = $ld.DeviceID
                label = if ($ld.VolumeName) { $ld.VolumeName } else { "Local Disk" }
                totalGB = $totalGB
                freeGB = $freeGB
                usedGB = $usedGB
                usedPercent = $usedPct
                status = $status
            }
        }
    } catch {}
}

$storageOverall = "OK"
foreach ($d in $disks) {
    if ($d["healthStatus"] -ne "Healthy" -or $d["predictFailure"] -eq $true) {
        $storageOverall = "DANGER"
    } elseif (($d["temperature"] -ne $null -and $d["temperature"] -gt 55) -or ($d["wearRemainingPercent"] -ne $null -and $d["wearRemainingPercent"] -le 10)) {
        if ($storageOverall -ne "DANGER") { $storageOverall = "WARN" }
    }
}
foreach ($v in $volumes) {
    if ($v["status"] -eq "DANGER" -and $storageOverall -ne "DANGER") { $storageOverall = "DANGER" }
    elseif ($v["status"] -eq "WARN" -and $storageOverall -eq "OK") { $storageOverall = "WARN" }
}

$data["storage"] = @{
    overallStatus = $storageOverall
    physicalDisks = $disks
    volumes = $volumes
}

# 5. System Resources (Memory & CPU)
$memTotal = 0
$memFree = 0
$memUsed = 0
$memPct = 0
$memStatus = "OK"

if ($os) {
    $memTotal = [math]::Round($os.TotalVisibleMemorySize / 1MB, 1)
    $memFree = [math]::Round($os.FreePhysicalMemory / 1MB, 1)
    $memUsed = [math]::Round($memTotal - $memFree, 1)
    if ($memTotal -gt 0) {
        $memPct = [math]::Round(($memUsed / $memTotal) * 100, 1)
    }
    if ($memPct -ge 90) { $memStatus = "DANGER" }
    elseif ($memPct -ge 80) { $memStatus = "WARN" }
}

$cpuLoad = $null
try {
    $cpuLoad = [math]::Round(((Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average), 0)
} catch {}
$cpuStatus = if ($cpuLoad -ne $null -and $cpuLoad -ge 90) { "WARN" } else { "OK" }

$data["resources"] = @{
    memory = @{
        totalGB = $memTotal
        usedGB = $memUsed
        freeGB = $memFree
        usedPercent = $memPct
        status = $memStatus
    }
    cpu = @{
        loadPercent = $cpuLoad
        status = $cpuStatus
    }
}

# 6. JSON output
$json = $data | ConvertTo-Json -Depth 6

try {
    $parentDir = Split-Path -Parent $OutputFile
    if (-not (Test-Path $parentDir)) {
        New-Item -ItemType Directory -Path $parentDir -Force | Out-Null
    }
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($OutputFile, $json, $utf8NoBom)
    Write-Output "SUCCESS: Health data saved to $OutputFile"
} catch {
    Write-Error "FAILED to write health file"
}
