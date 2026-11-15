<?php
// Claude Code hook for UserPromptSubmit, PreToolUse, PostToolUse, Stop and StopFailure: keeps each card's working dot right.
// Never disturbs Claude: prints nothing (UserPromptSubmit stdout goes into Claude's context) and exits 0 on any error.
ini_set('display_errors', '0');
error_reporting(0);
try {
    require dirname(__DIR__) . '/config.php';
    require dirname(__DIR__) . '/lib.php';
    $in = json_decode(file_get_contents('php://stdin'), true, 32, JSON_THROW_ON_ERROR);
    // After a failed connect, skip the database for 60 s: each try costs ~2 s on every tool call.
    $down = sys_get_temp_dir() . '/kanban-hook-dbdown';
    if (is_array($in) && ($in['session_id'] ?? '') !== '' && @filemtime($down) < time() - 60) {
        try {
            $db = db();
        } catch (PDOException $e) {
            touch($down);
            throw $e;
        }
        @unlink($down);
        // The desktop app gives its chats this id (local_…); a terminal session has none, so the board can't open it.
        $in['kanban_desktop'] = str_starts_with((string) getenv('CLAUDE_CODE_HOST_SESSION_ID'), 'local_');
        hook_event($db, $in);
        if (($in['hook_event_name'] ?? '') === 'Stop') {
            backup_if_due($db); // covers days the PC stays on without a fresh login
        }
    }
} catch (Throwable) {
}
exit(0);
