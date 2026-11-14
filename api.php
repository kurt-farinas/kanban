<?php
// JSON API for the web board.
// GET: a=list[&project=Name] (first moves cards named in new verified DEPLOYMENTS.md entries to Live), a=card&id=N (card + notes), a=events[&after=N] (50 newest; with after, up to 50 newer than event N, oldest first), a=plan&id=N (markdown).
// POST: a=add (title, project), a=move (id, col, position), a=edit (id, title, project), a=delete (id), a=note (id, body),
//       a=start_db (launches MySQL if it is not running; works without a database).
// Requests: GET a=requests (requests + earlier requesters); POST a=request_add (body, requester, project, kind),
//       a=request_card (id, title), a=request_delete (id).
// A database that can't be reached answers 503 with db_down: true.
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

const GET_ACTIONS = ['list', 'card', 'events', 'plan', 'requests'];
const POST_ACTIONS = ['add', 'move', 'edit', 'delete', 'note', 'start_db', 'request_add', 'request_card', 'request_delete'];

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function int_param(string $name, bool $optional = false): ?int
{
    $v = $_POST[$name] ?? $_GET[$name] ?? '';
    if ($optional && $v === '') {
        return null;
    }
    if (!is_string($v) || !ctype_digit($v)) {
        throw new InvalidArgumentException("$name must be a whole number.");
    }
    return (int) $v;
}

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$a = $post ? ($_POST['a'] ?? '') : ($_GET['a'] ?? '');
// Blocks DNS rebinding: a foreign hostname that resolves to 127.0.0.1 still passes Require local.
$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
if (!in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
    out(['error' => 'Use http://localhost/kanban.'], 403);
}
// Browsers send Sec-Fetch-Site; refuse writes from other sites' pages (curl sends none).
if ($post && ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin') {
    out(['error' => 'Cross-site request refused.'], 403);
}
if (!in_array($a, [...GET_ACTIONS, ...POST_ACTIONS], true)) {
    out(['error' => "Unknown action '$a'."], 400);
}
if ($post !== in_array($a, POST_ACTIONS, true)) {
    out(['error' => $post ? "Use GET for $a." : "Use POST for $a."], 405);
}

try {
    if ($a === 'start_db') {
        // A second click while it runs does nothing. The script starts mysqld through WMI so it is not a child of Apache.
        exec('tasklist /FI "IMAGENAME eq mysqld.exe" /NH', $lines);
        if (stripos(implode("\n", $lines), 'mysqld.exe') !== false) {
            out(['started' => false]);
        }
        exec('cscript //B //Nologo "' . __DIR__ . '\autostart\start-mysql.vbs"');
        out(['started' => true]);
    }
    try {
        $db = db();
    } catch (PDOException $e) {
        out(['error' => "Can't reach the database. Is MySQL running in XAMPP?", 'db_down' => true], 503);
    }
    switch ($a) {
        case 'list':
            try {
                sync_deployments($db); // cheap: reads a DEPLOYMENTS.md only when its mtime or size changed
            } catch (Throwable $e) {
                error_log('kanban deploy sync: ' . $e->getMessage()); // the board still loads
            }
            $project = $_GET['project'] ?? '';
            out([
                'cards' => list_cards($db, $project === '' ? null : $project),
                'projects' => $db->query('SELECT name, color FROM projects ORDER BY id')->fetchAll(),
                'columns' => COLUMNS,
                'version' => board_version(),
            ]);
        case 'card':
            $id = int_param('id');
            out(['card' => get_card($db, $id), 'notes' => list_notes($db, $id)]);
        case 'events':
            out(['events' => list_events($db, 50, int_param('after', true))]);
        case 'plan':
            $card = get_card($db, int_param('id'));
            if ($card['plan_path'] === null || !is_file($card['plan_path'])) {
                out(['error' => 'No plan yet'], 404);
            }
            out(['markdown' => file_get_contents(check_plan_path($card['plan_path'], $card['folder']))]);
        case 'add':
            out(['id' => add_card($db, $_POST['title'] ?? '', $_POST['project'] ?? '', 'user')]);
        case 'edit':
            edit_card($db, int_param('id'), $_POST['title'] ?? '', $_POST['project'] ?? '', 'user');
            out(['ok' => true]);
        case 'delete':
            delete_card($db, int_param('id'), 'user');
            out(['ok' => true]);
        case 'note':
            out(['id' => add_note($db, int_param('id'), $_POST['body'] ?? '', 'user')]);
        case 'requests':
            out(['requests' => list_requests($db), 'requesters' => requesters($db)]);
        case 'request_add':
            out(['id' => add_request($db, $_POST['body'] ?? '', $_POST['requester'] ?? '', $_POST['project'] ?? '', $_POST['kind'] ?? '', 'user')]);
        case 'request_card':
            out(['id' => card_from_request($db, int_param('id'), $_POST['title'] ?? '', 'user')]);
        case 'request_delete':
            delete_request($db, int_param('id'), 'user');
            out(['ok' => true]);
    }
    $id = int_param('id');
    $col = $_POST['col'] ?? '';
    $from = card_col($db, $id);
    move_card($db, $id, $col, 'user', int_param('position', true));
    $card = get_card($db, $id);
    out(['ok' => true, 'link' => $from === $col ? null : claude_link($card, $card['folder'])]);
} catch (InvalidArgumentException $e) {
    out(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    out(['error' => 'Server error: ' . $e->getMessage()], 500);
}
