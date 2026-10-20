[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
# ==============================================================================
# 医療法人小野会 本番サーバー ヘルス情報定期収集タスク 登録スクリプト
# ==============================================================================

# 1. 管理者権限チェック
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host ""
    Write-Host "======================================================================" -ForegroundColor Red
    Write-Host "  [エラー] 管理者権限が必要です。" -ForegroundColor Red
    Write-Host "  本バッチファイルを右クリックし、「管理者として実行」を選択してください。" -ForegroundColor Yellow
    Write-Host "======================================================================" -ForegroundColor Red
    Write-Host ""
    Exit 1
}

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$collectorScript = Join-Path $scriptDir "collect_server_health.ps1"
$taskName = "Onokai_Server_Health_Monitor"

Write-Host ""
Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "  医療法人小野会 本番サーバー ヘルス情報定期収集タスク 登録ツール" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host ""

if (-not (Test-Path $collectorScript)) {
    Write-Host "[エラー] 収集スクリプトが見つかりません: $collectorScript" -ForegroundColor Red
    Exit 1
}

Write-Host "[1/3] 既存タスクの確認・クリーンアップ中..." -ForegroundColor Yellow
& schtasks.exe /delete /tn $taskName /f *>$null

Write-Host "[2/3] タスクスケジューラに登録中 (10分毎 / SYSTEM特権)..." -ForegroundColor Yellow
$action = "powershell.exe -NoProfile -NoLogo -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$collectorScript`""

$res = & schtasks.exe /create /tn $taskName /tr $action /sc MINUTE /mo 10 /ru "SYSTEM" /rl HIGHEST /f 2>&1
if ($LASTEXITCODE -eq 0) {
    Write-Host "   -> [OK] タスク '$taskName' の登録に成功しました。" -ForegroundColor Green
} else {
    Write-Host "   -> [エラー] タスク登録に失敗しました: $res" -ForegroundColor Red
    Exit 1
}

Write-Host ""
Write-Host "[3/3] 初回ヘルス情報収集テストを実行中..." -ForegroundColor Yellow
& powershell.exe -NoProfile -NoLogo -NonInteractive -ExecutionPolicy Bypass -File "$collectorScript"

Write-Host ""
Write-Host "======================================================================" -ForegroundColor Green
Write-Host "  【設定完了】" -ForegroundColor Green
Write-Host "  本番サーバーのヘルス情報 (SMART / ディスク容量 / CPU / メモリ)" -ForegroundColor Green
Write-Host "  が 10分毎 に自動更新されます。" -ForegroundColor Green
Write-Host "  Deploy Manager からリアルタイムに閲覧可能です。" -ForegroundColor Green
Write-Host "======================================================================" -ForegroundColor Green
Write-Host ""
