' Starts XAMPP's MySQL and Apache hidden at login, unless they're already running.
' Put a shortcut to this file in the Startup folder (Win+R, shell:startup). Delete the shortcut to undo.
Option Explicit
Dim xampp, sh, wmi
xampp = "C:\xampp"
Set sh = CreateObject("WScript.Shell")
Set wmi = GetObject("winmgmts:\\.\root\cimv2")

Function Running(exe)
    Running = wmi.ExecQuery("SELECT ProcessId FROM Win32_Process WHERE Name='" & exe & "'").Count > 0
End Function

If Not Running("mysqld.exe") Then
    sh.CurrentDirectory = xampp
    sh.Run """" & xampp & "\mysql\bin\mysqld.exe"" --defaults-file=""" & xampp & "\mysql\bin\my.ini"" --standalone", 0, False
End If
If Not Running("httpd.exe") Then
    sh.CurrentDirectory = xampp & "\apache"
    sh.Run """" & xampp & "\apache\bin\httpd.exe""", 0, False
End If
