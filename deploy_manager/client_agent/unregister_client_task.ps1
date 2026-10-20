[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
# ==============================================================================
# クライアント端末 定期監視タスク 削除スクリプト
# ==============================================================================

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host ""
    Write-Host "[エラー] 管理者権限が必要です。「管理者として実行」してください。" -ForegroundColor Red
    Write-Host ""
    Exit 1
}

$taskName = "Onokai_Client_Health_Monitor"
Write-Host "タスク '$taskName' を削除中..." -ForegroundColor Yellow
$res = & schtasks.exe /delete /tn $taskName /f 2>&1
if ($LASTEXITCODE -eq 0) {
    Write-Host "[OK] タスク '$taskName' を正常に削除しました。" -ForegroundColor Green
} else {
    Write-Host "[情報] 登録済みのタスクはありませんでした。" -ForegroundColor Cyan
}
Write-Host ""
