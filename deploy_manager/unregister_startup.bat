@echo off
title 院内システム総合デプロイマネージャー 自動常駐解除
cd /d "%~dp0"
node setup_startup.js --unregister
pause