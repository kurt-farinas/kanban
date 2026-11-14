' One-click launcher: starts XAMPP's MySQL and Apache if needed, waits for the board, then opens it in its own window.
' Point a Desktop shortcut at: wscript.exe "<this file>"
Option Explicit
Dim xampp, url, fso, sh, wmi, http, i, ok, browser
xampp = "C:\xampp"
url = "http://localhost/kanban/"
Set fso = CreateObject("Scripting.FileSystemObject")
Set sh = CreateObject("WScript.Shell")
Set wmi = GetObject("winmgmts:\\.\root\cimv2")

Function Running(exe)
    Running = wmi.ExecQuery("SELECT ProcessId FROM Win32_Process WHERE Name='" & exe & "'").Count > 0
End Function

Function BoardUp()
    On Error Resume Next
    Set http = CreateObject("MSXML2.ServerXMLHTTP.6.0")
    http.setTimeouts 1000, 1000, 2000, 2000
    http.Open "GET", url & "api.php?a=list", False
    http.Send
    BoardUp = (Err.Number = 0 And http.Status = 200)
    On Error GoTo 0
End Function

If Not Running("mysqld.exe") Then
    sh.CurrentDirectory = xampp
    sh.Run """" & xampp & "\mysql\bin\mysqld.exe"" --defaults-file=""" & xampp & "\mysql\bin\my.ini"" --standalone", 0, False
End If
If Not Running("httpd.exe") Then
    sh.CurrentDirectory = xampp & "\apache"
    sh.Run """" & xampp & "\apache\bin\httpd.exe""", 0, False
End If

ok = False
For i = 1 To 30
    If BoardUp() Then ok = True: Exit For
    WScript.Sleep 1000
Next
If Not ok Then
    MsgBox "The board didn't start within 30 seconds. Open the XAMPP Control Panel and start Apache and MySQL.", vbExclamation, "Kanban"
    WScript.Quit 1
End If

' Its own app window in Chrome or Edge; otherwise the default browser.
browser = ""
If fso.FileExists("C:\Program Files\Google\Chrome\Application\chrome.exe") Then
    browser = "C:\Program Files\Google\Chrome\Application\chrome.exe"
ElseIf fso.FileExists("C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe") Then
    browser = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
End If
If browser <> "" Then
    sh.Run """" & browser & """ --app=" & url & " --window-size=1400,820", 1, False
Else
    sh.Run url, 1, False
End If
