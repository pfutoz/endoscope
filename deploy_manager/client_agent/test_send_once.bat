@echo off
setlocal
cd /d "%~dp0"
echo [Testing client health report transmission...]
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0collect_client_health.ps1" -VerboseOutput
echo.
pause
