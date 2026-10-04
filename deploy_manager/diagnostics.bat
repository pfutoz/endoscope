@echo off
chcp 932 > nul
title 髯｢蜀・す繧ｹ繝・Β邱丞粋繝・・繝ｭ繧､繝槭ロ繝ｼ繧ｸ繝｣繝ｼ 迺ｰ蠅・・蟾ｱ險ｺ譁ｭ繝・・繝ｫ

cd /d "%~dp0"

where node > nul 2>&1
if %errorlevel% neq 0 (
    echo ======================================================================
    echo   [繧ｨ繝ｩ繝ｼ] Node.js 縺瑚ｦ九▽縺九ｊ縺ｾ縺帙ｓ・・    echo.
    echo   譛ｬ繝・・繝ｫ縺ｮ蜍穂ｽ懊↓縺ｯ Node.js (LTS迚・ 縺悟ｿ・ｦ√〒縺吶・    echo   蜈ｬ蠑上し繧､繝・ https://nodejs.org/ 縺九ｉ繧､繝ｳ繧ｹ繝医・繝ｫ縺励※縺上□縺輔＞縲・    echo ======================================================================
    echo.
    pause
    exit /b 1
)

node diagnostics.js
echo.
pause