<?php
const COLUMNS = ['todo', 'planning', 'building', 'review', 'test', 'live'];

// ORDER BY c.col sorts by ENUM index, which is COLUMNS order.
const CARD_SELECT = 'SELECT c.id, c.title, c.col, c.position, c.blocked, c.blocked_reason,
        p.name AS project, p.color, p.folder, c.updated_by, c.updated_at,
        c.status, c.working_since, c.plan_path,
        (SELECT COUNT(*) FROM notes n WHERE n.card_id = c.id) AS note_count
    FROM cards c JOIN projects p ON p.id = c.project_id';
const CARD_ORDER = ' ORDER BY c.col, c.position, c.id';

function check_by(string $by): void
{
    if ($by !== 'user' && $by !== 'claude') {
        throw new InvalidArgumentException("Unknown author '$by' (use user or claude).");
    }
}

function check_col(string $col): void
{
    if (!in_array($col, COLUMNS, true)) {
        throw new InvalidArgumentException("Unknown column '$col' (use " . implode(', ', COLUMNS) . ').');
    }
}

function project_id(PDO $db, string $project): int
{
    $st = $db->prepare('SELECT id FROM projects WHERE LOWER(name) = LOWER(?)');
    $st->execute([$project]);
    $id = $st->fetchColumn();
    if ($id === false) {
        throw new InvalidArgumentException("Unknown project '$project'.");
    }
    return (int) $id;
}

function card_col(PDO $db, int $id, bool $lock = false): string
{
    $st = $db->prepare('SELECT col FROM cards WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $col = $st->fetchColumn();
    if ($col === false) {
        throw new InvalidArgumentException("No card #$id.");
    }
    return $col;
}

function list_cards(PDO $db, ?string $project = null): array
{
    if ($project === null) {
        return $db->query(CARD_SELECT . CARD_ORDER)->fetchAll();
    }
    $st = $db->prepare(CARD_SELECT . ' WHERE c.project_id = ?' . CARD_ORDER);
    $st->execute([project_id($db, $project)]);
    return $st->fetchAll();
}

function check_title(string $title): void
{
    if (trim($title) === '') {
        throw new InvalidArgumentException('Title is empty.');
    }
    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException('Title is longer than 200 characters.');
    }
}

function in_tx(PDO $db, callable $fn): mixed
{
    $db->beginTransaction();
    try {
        $r = $fn();
        $db->commit();
        return $r;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// Copies the card title in, so the log still reads after the card is deleted.
function log_event(PDO $db, int $id, string $by, string $action, string $detail = ''): void
{
    $db->prepare('INSERT INTO events (card_id, card_title, author, action, detail) SELECT id, title, ?, ?, ? FROM cards WHERE id = ?')
        ->execute([$by, $action, mb_substr($detail, 0, 200), $id]);
}

function get_card(PDO $db, int $id): array
{
    $st = $db->prepare(CARD_SELECT . ' WHERE c.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: throw new InvalidArgumentException("No card #$id.");
}

function add_card(PDO $db, string $title, string $project, string $by): int
{
    check_by($by);
    check_title($title);
    $pid = project_id($db, $project);
    return in_tx($db, function () use ($db, $pid, $title, $by, $project) {
        $st = $db->prepare("INSERT INTO cards (project_id, title, col, position, updated_by)
            SELECT ?, ?, 'todo', COALESCE(MAX(position) + 1, 0), ? FROM cards WHERE col = 'todo'");
        $st->execute([$pid, $title, $by]);
        $id = (int) $db->lastInsertId();
        log_event($db, $id, $by, 'added', $project);
        return $id;
    });
}

function edit_card(PDO $db, int $id, string $title, string $project, string $by): void
{
    check_by($by);
    check_title($title);
    $pid = project_id($db, $project);
    in_tx($db, function () use ($db, $id, $pid, $title, $by, $project) {
        card_col($db, $id, true);
        $db->prepare('UPDATE cards SET title = ?, project_id = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$title, $pid, $by, $id]);
        log_event($db, $id, $by, 'edited', $project);
    });
}

function delete_card(PDO $db, int $id, string $by): void
{
    check_by($by);
    in_tx($db, function () use ($db, $id, $by) {
        $col = card_col($db, $id, true);
        log_event($db, $id, $by, 'deleted', $col);
        $db->prepare('DELETE FROM cards WHERE id = ?')->execute([$id]);
        $st = $db->prepare('SELECT id FROM cards WHERE col = ? ORDER BY position, id FOR UPDATE');
        $st->execute([$col]);
        renumber($db, $st->fetchAll(PDO::FETCH_COLUMN), $id);
    });
}

function add_note(PDO $db, int $id, string $body, string $by): int
{
    check_by($by);
    if (trim($body) === '') {
        throw new InvalidArgumentException('Note is empty.');
    }
    if (mb_strlen($body) > 2000) {
        throw new InvalidArgumentException('Note is longer than 2000 characters.');
    }
    return in_tx($db, function () use ($db, $id, $body, $by) {
        card_col($db, $id);
        $db->prepare('INSERT INTO notes (card_id, author, body) VALUES (?, ?, ?)')->execute([$id, $by, $body]);
        $nid = (int) $db->lastInsertId();
        log_event($db, $id, $by, 'note', $body);
        return $nid;
    });
}

function list_notes(PDO $db, int $id): array
{
    card_col($db, $id);
    $st = $db->prepare('SELECT id, author, body, created_at FROM notes WHERE card_id = ? ORDER BY id');
    $st->execute([$id]);
    return $st->fetchAll();
}

// Not logged (changes often) and leaves updated_at alone; null or blank clears.
function set_status(PDO $db, int $id, ?string $text, string $by): void
{
    check_by($by);
    $text = $text === null || trim($text) === '' ? null : $text;
    if ($text !== null && mb_strlen($text) > 120) {
        throw new InvalidArgumentException('Status is longer than 120 characters.');
    }
    card_col($db, $id);
    $db->prepare('UPDATE cards SET status = ?, updated_at = updated_at WHERE id = ?')->execute([$text, $id]);
}

// Returns the real path of a .md file inside $folder (case and slash direction ignored), or throws.
function check_plan_path(string $path, string $folder): string
{
    $norm = fn($p) => strtolower(str_replace('\\', '/', $p));
    $real = realpath($path);
    $dir = realpath($folder);
    if ($real === false || $dir === false || !is_file($real)
        || !str_ends_with($norm($real), '.md') || !str_starts_with($norm($real), rtrim($norm($dir), '/') . '/')) {
        throw new InvalidArgumentException("Plan must be an existing .md file inside the project folder ($folder).");
    }
    return $real;
}

function set_plan(PDO $db, int $id, string $path, string $by): void
{
    check_by($by);
    $real = check_plan_path($path, get_card($db, $id)['folder']);
    in_tx($db, function () use ($db, $id, $real, $by) {
        $db->prepare('UPDATE cards SET plan_path = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$real, $by, $id]);
        log_event($db, $id, $by, 'plan', $real);
    });
}

function list_events(PDO $db, int $limit = 50): array
{
    return $db->query('SELECT id, card_id, card_title, author, action, detail, created_at FROM events
        ORDER BY id DESC LIMIT ' . max(1, $limit))->fetchAll();
}

function move_card(PDO $db, int $id, string $col, string $by, ?int $position = null): void
{
    check_col($col);
    check_by($by);
    if ($position !== null && $position < 0) {
        throw new InvalidArgumentException('Position must be 0 or more.');
    }
    // Concurrent moves in opposite directions can deadlock; InnoDB rolls one back, so retry it.
    for ($try = 1; ; $try++) {
        try {
            move_card_once($db, $id, $col, $by, $position);
            return;
        } catch (PDOException $e) {
            if ($try >= 3 || ($e->errorInfo[1] ?? 0) !== 1213) {
                throw $e;
            }
        }
    }
}

function move_card_once(PDO $db, int $id, string $col, string $by, ?int $position): void
{
    $db->beginTransaction();
    try {
        $from = card_col($db, $id, true);
        $st = $db->prepare('SELECT id FROM cards WHERE col = ? AND id <> ? ORDER BY position, id FOR UPDATE');
        $st->execute([$col, $id]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        $position = min($position ?? count($ids), count($ids));
        array_splice($ids, $position, 0, [$id]);

        $db->prepare('UPDATE cards SET col = ?, position = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$col, $position, $by, $id]);
        renumber($db, $ids, $id);
        log_event($db, $id, $by, 'moved', "$from → $col");
        if ($from !== $col) {
            $st = $db->prepare('SELECT id FROM cards WHERE col = ? ORDER BY position, id FOR UPDATE');
            $st->execute([$from]);
            renumber($db, $st->fetchAll(PDO::FETCH_COLUMN), $id);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// Sets positions 0..n-1 in list order; neighbours keep their updated_at, the moved card is skipped.
function renumber(PDO $db, array $ids, int $skip): void
{
    $st = $db->prepare('UPDATE cards SET position = ?, updated_at = updated_at WHERE id = ?');
    foreach ($ids as $i => $cid) {
        if ((int) $cid !== $skip) {
            $st->execute([$i, $cid]);
        }
    }
}

function set_blocked(PDO $db, int $id, ?string $reason, string $by): void
{
    check_by($by);
    $blocked = $reason !== null && trim($reason) !== '';
    if ($blocked && mb_strlen($reason) > 500) {
        throw new InvalidArgumentException('Blocked reason is longer than 500 characters.');
    }
    in_tx($db, function () use ($db, $id, $blocked, $reason, $by) {
        card_col($db, $id, true);
        $db->prepare('UPDATE cards SET blocked = ?, blocked_reason = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([(int) $blocked, $blocked ? $reason : null, $by, $id]);
        log_event($db, $id, $by, $blocked ? 'blocked' : 'unblocked', $blocked ? $reason : '');
    });
    if ($blocked) {
        notify("Card #$id needs you", $reason);
    }
}

function find_cards(PDO $db, string $text): array
{
    $st = $db->prepare(CARD_SELECT . ' WHERE c.title LIKE ?' . CARD_ORDER);
    $st->execute(['%' . addcslashes($text, '\%_') . '%']);
    return $st->fetchAll();
}

function claude_link(array $card, string $folder): ?string
{
    $verb = [
        'planning' => '/feature-planner Plan card',
        'building' => 'Build the plan for card',
        'review' => 'Review the code for card',
        'live' => 'Deploy card',
    ][$card['col']] ?? null;
    if ($verb === null) {
        return null;
    }
    $prompt = mb_substr("$verb #{$card['id']} ({$card['project']}): {$card['title']}", 0, 4000);
    return 'claude://code/new?prompt=' . rawurlencode($prompt) . '&folder=' . rawurlencode($folder);
}

// Windows notification through notify/toast.ps1, started in the background so the caller never waits.
// Returns [command, environment]: the text goes in environment variables, so a leading "-" or non-ASCII survives.
function notify_command(string $title, string $body): array
{
    $clean = fn(string $s) => mb_substr(trim(preg_replace('/\s+/u', ' ', $s)), 0, 200);
    return ['start "" /B powershell -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File '
        . escapeshellarg(__DIR__ . '/notify/toast.ps1') . ' >NUL 2>&1',
        ['KANBAN_TOAST_TITLE' => $clean($title), 'KANBAN_TOAST_BODY' => $clean($body)]];
}

// The test runner sets KANBAN_NO_NOTIFY.
function notify(string $title, string $body): void
{
    if (getenv('KANBAN_NO_NOTIFY') === false) {
        [$cmd, $env] = notify_command($title, $body);
        foreach ($env as $k => $v) {
            putenv("$k=$v"); // inherited by the child process
        }
        pclose(popen($cmd, 'r'));
    }
}

// Claude Code hooks (hooks/claude_hook.php) keep sessions = which Claude session works on which card.
function card_from_prompt(string $prompt): ?int
{
    return preg_match('/\bcard #(\d{1,9})\b/i', $prompt, $m) ? (int) $m[1] : null;
}

// A command that runs "board.php <cmd> N ..." names card N.
function card_from_command(string $command): ?int
{
    return preg_match('/board\.php["\']?\s+[a-z]+\s+["\']?(\d{1,9})\b/i', $command, $m) ? (int) $m[1] : null;
}

// Links the session to the card and lights the card's dot; false if there is no such card.
function hook_link(PDO $db, string $session, int $card): bool
{
    $st = $db->prepare('SELECT 1 FROM cards WHERE id = ?');
    $st->execute([$card]);
    if (!$st->fetchColumn()) {
        return false;
    }
    $db->prepare('INSERT INTO sessions (session_id, card_id, seen_at) VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE card_id = VALUES(card_id), seen_at = NOW(), active = 1')->execute([$session, $card]);
    hook_touch($db, $session);
    return true;
}

// Refreshes a linked session (active again, e.g. a follow-up turn) and its card's dot; an unlinked session does nothing.
function hook_touch(PDO $db, string $session): void
{
    $db->prepare('UPDATE sessions s JOIN cards c ON c.id = s.card_id
        SET s.seen_at = NOW(), s.active = 1, c.working_since = NOW(), c.updated_at = c.updated_at WHERE s.session_id = ?')
        ->execute([$session]);
}

// Ends the turn: the row stays (inactive) so the next prompt relights; the dot and status go off once no session is active.
function hook_stop(PDO $db, string $session): void
{
    $st = $db->prepare('SELECT card_id FROM sessions WHERE session_id = ?');
    $st->execute([$session]);
    $card = $st->fetchColumn();
    if ($card === false) {
        return;
    }
    $db->prepare('UPDATE sessions SET active = 0 WHERE session_id = ?')->execute([$session]);
    $db->prepare('UPDATE cards SET working_since = NULL, status = NULL, updated_at = updated_at
        WHERE id = ? AND NOT EXISTS (SELECT 1 FROM sessions WHERE card_id = ? AND active = 1)')->execute([$card, $card]);
}

// One Claude Code hook payload (hooks/claude_hook.php): keeps the session's link and its card's dot right.
function hook_event(PDO $db, array $in): void
{
    $session = (string) ($in['session_id'] ?? '');
    if ($session === '') {
        return;
    }
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
        case 'StopFailure': // the turn ended on an API error
            hook_stop($db, $session);
            break;
    }
    hook_prune($db);
}

// Drops sessions unseen for a day; turns off dots with no active session seen in 30 min (crashed, closed or relinked).
function hook_prune(PDO $db): void
{
    $db->exec('DELETE FROM sessions WHERE seen_at < NOW() - INTERVAL 24 HOUR');
    $db->exec('UPDATE cards c SET working_since = NULL, status = NULL, updated_at = updated_at
        WHERE working_since IS NOT NULL AND NOT EXISTS (SELECT 1 FROM sessions s
            WHERE s.card_id = c.id AND s.active = 1 AND s.seen_at >= NOW() - INTERVAL 30 MINUTE)');
}
