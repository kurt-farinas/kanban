<?php
// Claude Code hook for UserPromptSubmit, PreToolUse, PostToolUse and Stop: keeps each card's working dot right.
// Never disturbs Claude: prints nothing (UserPromptSubmit stdout goes into Claude's context) and exits 0 on any error.
ini_set('display_errors', '0');
error_reporting(0);
try {
    require dirname(__DIR__) . '/config.php';
    require dirname(__DIR__) . '/lib.php';
    $in = json_decode(file_get_contents('php://stdin'), true, 32, JSON_THROW_ON_ERROR);
    $session = is_array($in) ? (string) ($in['session_id'] ?? '') : '';
    if ($session !== '') {
        $db = db();
        switch ($in['hook_event_name'] ?? '') {
            case 'UserPromptSubmit':
                $card = card_from_prompt((string) ($in['prompt'] ?? ''));
                if ($card === null || !hook_link($db, $session, $card)) {
                    hook_touch($db, $session);
                }
                break;
            case 'PreToolUse':
                hook_touch($db, $session);
                break;
            case 'PostToolUse':
                // Any shell tool (Bash, PowerShell) that ran board.php on card N links this session to N.
                $card = card_from_command((string) ($in['tool_input']['command'] ?? ''));
                if ($card === null || !hook_link($db, $session, $card)) {
                    hook_touch($db, $session);
                }
                break;
            case 'Stop':
                hook_stop($db, $session);
                break;
        }
        hook_prune($db);
    }
} catch (Throwable) {
}
exit(0);
