' 医療法人小野会 デプロイマネージャー 自動常駐ランチャー
Set WshShell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
strDir = "c:\Apache24\htdocs\deploy_manager"
serverJs = strDir & "\server.js"

If fso.FileExists(serverJs) Then
    cmd = "powershell -WindowStyle Hidden -NoProfile -Command ""Start-Process node -ArgumentList '" & serverJs & "' -WorkingDirectory '" & strDir & "' -WindowStyle Hidden"""
    WshShell.Run cmd, 0, False
End If
