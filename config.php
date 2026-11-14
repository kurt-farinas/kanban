<?php
// Optional config.local.php returns an array overriding any of: host, user, pass, db.
function db_config(): array
{
    $c = ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'db' => 'kanban'];
    if (is_file(__DIR__ . '/config.local.php')) {
        $c = array_merge($c, require __DIR__ . '/config.local.php');
    }
    return $c;
}

function db(?string $name = null): PDO
{
    $c = db_config();
    $name ??= $c['db'];
    // An empty name connects to the server only (tests use it to create/drop kanban_test).
    $dsn = "mysql:host={$c['host']};charset=utf8mb4" . ($name === '' ? '' : ";dbname=$name");
    return new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 2, // seconds to connect; the Claude hooks must never hang
        // XAMPP's default sql_mode silently truncates bad data; fail loudly instead.
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode = 'STRICT_ALL_TABLES'",
    ]);
}
