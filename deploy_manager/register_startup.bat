@echo off
title 院内システム総合デプロイマネージャー 自動常駐登録
cd /d "%~dp0"
node setup_startup.js --register
pause