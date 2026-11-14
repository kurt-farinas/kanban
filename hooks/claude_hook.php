<?php
// Claude Code hook for UserPromptSubmit, PreToolUse, PostToolUse and Stop: keeps each card's working dot right.
// Never disturbs Claude: prints nothing (UserPromptSubmit stdout goes into Claude's context) and exits 0 on any error.
ini_set('display_errors', '0');
error_reporting(0);
try {
    require dirname(__DIR__) . '/config.php';
    require dirname(__DIR__) . '/lib.php';
    $in = json_decode(file_get_contents('php://stdin'), true, 32, JSON_THROW_ON_ERROR);
    if (is_array($in) && ($in['session_id'] ?? '') !== '') {
        hook_event(db(), $in);
    }
} catch (Throwable) {
}
exit(0);
