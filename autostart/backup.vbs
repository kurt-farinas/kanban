' Daily board backup, run hidden by the "Kanban backup" scheduled task (see README).
' board.php backup skips quietly when today's file exists or MySQL is off, and shows a toast if a dump fails.
CreateObject("WScript.Shell").Run """C:\xampp\php\php.exe"" ""C:\xampp\htdocs\kanban\board.php"" backup", 0, False
