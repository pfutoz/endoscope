@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0unregister_task.ps1"
if %errorlevel% neq 0 (
    echo.
    pause
)
