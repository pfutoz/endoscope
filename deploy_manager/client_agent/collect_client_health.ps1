# ==============================================================================
# 医療法人小野会 クライアント端末用 ヘルス情報自動収集・送信スクリプト
# ファイル名: collect_client_health.ps1
# 動作要件: Windows PowerShell 5.1+ (Windows 10/11)
# 権限: SYSTEM または 管理者権限推奨 (SMART/PhysicalDisk取得のため)
# ==============================================================================

param (
    [string]$ServerUrl = "http://192.168.1.16/ip/api_report_health.php",
    [switch]$VerboseOutput
)

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$ErrorActionPreference = "SilentlyContinue"

# 1. ホスト & ネットワーク情報
$computerName = $env:COMPUTERNAME
$os = $null
try { $os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue } catch {}
$lastBoot = if ($os) { $os.LastBootUpTime } else { (Get-Date) }
$uptime = (Get-Date) - $lastBoot
$uptimeText = "$($uptime.Days)d $($uptime.Hours)h $($uptime.Minutes)m"

# 有効なIPv4アドレスを取得
$clientIp = ""
try {
    $ipObj = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | 
        Where-Object { $_.IPAddress -notlike "127.*" -and $_.IPAddress -notlike "169.254.*" } | 
        Sort-Object -Property InterfaceIndex | Select-Object -First 1
    if ($ipObj) { $clientIp = $ipObj.IPAddress }
} catch {}

# 有効なMACアドレスを取得
$macAddress = ""
try {
    $netAd = Get-NetAdapter -ErrorAction SilentlyContinue | Where-Object { $_.Status -eq 'Up' } | Select-Object -First 1
    if ($netAd) { $macAddress = $netAd.MacAddress }
} catch {}

# 2. CPU & メモリ
$cpuLoad = $null
$cpuName = ""
try {
    $proc = Get-CimInstance Win32_Processor -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($proc) {
        $cpuName = $proc.Name.Trim()
        $cpuLoad = $proc.LoadPercentage
    }
} catch {}

$memTotal = 0
$memFree = 0
$memUsed = 0
$memPct = 0
if ($os) {
    $memTotal = [math]::Round($os.TotalVisibleMemorySize / 1MB, 1)
    $memFree = [math]::Round($os.FreePhysicalMemory / 1MB, 1)
    $memUsed = [math]::Round($memTotal - $memFree, 1)
    if ($memTotal -gt 0) {
        $memPct = [math]::Round(($memUsed / $memTotal) * 100, 1)
    }
}

# 3. ストレージ SMART & 物理ディスク
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
                    if ($rel.Temperature -gt 0 -and $rel.Temperature -lt 120) { $temp = [int]$rel.Temperature }
                    if ($rel.Wear -ne $null) { $wear = [int]$rel.Wear }
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

            $disks += @{
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
        }
    }
} catch {}

# Fallback: Win32_DiskDrive
if (-not $gotPDisks) {
    try {
        $wDisks = Get-CimInstance Win32_DiskDrive -ErrorAction SilentlyContinue
        if ($wDisks) {
            foreach ($wd in $wDisks) {
                $hStat = if ($wd.Status -eq "OK") { "Healthy" } else { "Warning" }
                $disks += @{
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
            }
        }
    } catch {}
}

# 4. ボリューム (C:ドライブ等)
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
            $volumes += @{
                driveLetter = "$($v.DriveLetter):"
                label = $lbl
                totalGB = $totalGB
                freeGB = $freeGB
                usedGB = $usedGB
                usedPercent = $usedPct
                status = $status
            }
        }
    }
} catch {}

# ストレージ総合判定
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

# 5. ペイロード構築
$payload = @{
    version = "1.0"
    computerName = $computerName
    ip = $clientIp
    mac = $macAddress
    collectedAt = (Get-Date).ToString("yyyy/MM/dd HH:mm:ss")
    host = @{
        computerName = $computerName
        os = if ($os) { $os.Caption.Trim() } else { "Windows" }
        uptime = $uptimeText
        uptimeDays = $uptime.Days
        uptimeHours = $uptime.Hours
        lastBoot = $lastBoot.ToString("yyyy/MM/dd HH:mm:ss")
    }
    resources = @{
        cpu = @{
            name = $cpuName
            loadPercent = $cpuLoad
        }
        memory = @{
            totalGB = $memTotal
            usedGB = $memUsed
            freeGB = $memFree
            usedPercent = $memPct
            status = if ($memPct -ge 90) { "DANGER" } elseif ($memPct -ge 80) { "WARN" } else { "OK" }
        }
    }
    storage = @{
        overallStatus = $storageOverall
        physicalDisks = $disks
        volumes = $volumes
    }
}

$json = $payload | ConvertTo-Json -Depth 6

# 6. 本番サーバー (/ip/api_report_health.php) へのHTTP POST送信
try {
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $req = [System.Net.HttpWebRequest]::Create($ServerUrl)
    $req.Method = "POST"
    $req.ContentType = "application/json; charset=utf-8"
    $req.ContentLength = $bytes.Length
    $req.Timeout = 5000
    
    $stream = $req.GetRequestStream()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()
    
    $res = $req.GetResponse()
    $reader = New-Object System.IO.StreamReader($res.GetResponseStream())
    $respBody = $reader.ReadToEnd()
    $reader.Close()
    $res.Close()

    if ($VerboseOutput) {
        Write-Host "[OK] ヘルス情報を送信しました: $ServerUrl" -ForegroundColor Green
        Write-Host "サーバー応答: $respBody" -ForegroundColor Cyan
    }
} catch {
    if ($VerboseOutput) {
        Write-Host "[エラー] 送信に失敗しました: $_" -ForegroundColor Red
    }
}
