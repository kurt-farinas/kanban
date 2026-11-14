# Shows a Windows notification. Used by notify() in lib.php, which passes the text in
# KANBAN_TOAST_TITLE and KANBAN_TOAST_BODY (not arguments, so a leading "-" or non-ASCII text survives).
[Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime] | Out-Null
[Windows.Data.Xml.Dom.XmlDocument, Windows.Data.Xml.Dom.XmlDocument, ContentType = WindowsRuntime] | Out-Null
$t = [Security.SecurityElement]::Escape([string]$env:KANBAN_TOAST_TITLE)
$b = [Security.SecurityElement]::Escape([string]$env:KANBAN_TOAST_BODY)
$xml = New-Object Windows.Data.Xml.Dom.XmlDocument
$xml.LoadXml("<toast><visual><binding template=`"ToastGeneric`"><text>$t</text><text>$b</text></binding></visual></toast>")
# Windows PowerShell's own app id: lets the notification show without registering an app.
$appId = '{1AC14E77-02E7-4E5D-B744-2EB1AE5198B7}\WindowsPowerShell\v1.0\powershell.exe'
[Windows.UI.Notifications.ToastNotificationManager]::CreateToastNotifier($appId).Show([Windows.UI.Notifications.ToastNotification]::new($xml))
