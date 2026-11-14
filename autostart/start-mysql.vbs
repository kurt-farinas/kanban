' Starts XAMPP's MySQL detached from the caller (the new process belongs to WMI, not to Apache/PHP), unless it's already running.
' Used by api.php (Start database button); start-xampp.vbs does the same at login.
Option Explicit
Dim wmi, pid
Set wmi = GetObject("winmgmts:\\.\root\cimv2")

If wmi.ExecQuery("SELECT ProcessId FROM Win32_Process WHERE Name='mysqld.exe'").Count = 0 Then
    wmi.Get("Win32_Process").Create """C:\xampp\mysql\bin\mysqld.exe"" --defaults-file=""C:\xampp\mysql\bin\my.ini"" --standalone", "C:\xampp", Null, pid
End If
