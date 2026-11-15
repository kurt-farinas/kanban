<?php
// JSON API for the web board.
// GET: a=list[&project=Name] (first moves cards named in new verified DEPLOYMENTS.md entries to Live), a=card&id=N (card + notes), a=events[&after=N] (50 newest; with after, up to 50 newer than event N, oldest first), a=plan&id=N (markdown), a=chat&id=N (url + title of the card's desktop-app chat; 404 if none),
//      a=report_preview&project=P&from=YYYY-MM-DD&to=YYYY-MM-DD (counts of done, underway, planned and no-section cards).
// POST: a=add (title, project), a=move (id, col, position), a=edit (id, title, project, optional was_title/was_project: 409 conflict if the card changed), a=delete (id), a=note (id, body), a=section (id, section; blank clears),
//       a=start_db (launches MySQL if it is not running; works without a database).
// Requests: GET a=requests (requests + earlier requesters); POST a=request_add (body, requester, project, kind),
//       a=request_card (id, title), a=request_delete (id).
// Projects: GET a=projects (each with folder, colour, report name, card count and sections with ids and card counts);
//       POST a=project_add (name, folder, color, site_name, sections), a=project_edit (id, name, folder, color, site_name),
//       a=project_archive (id, archived 0/1), a=sections_save (id, sections). sections is a JSON list of {id: int|null, name}, in report order.
// Bad input, including a name taken at the same moment by another save, answers 400 with the message.
// A database that can't be reached answers 503 with db_down: true.
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

const GET_ACTIONS = ['list', 'card', 'events', 'plan', 'chat', 'plan_open', 'requests', 'report_preview', 'projects'];
const POST_ACTIONS = ['add', 'move', 'edit', 'delete', 'note', 'section', 'start_db', 'request_add', 'request_card', 'request_delete',
    'project_add', 'project_edit', 'project_archive', 'sections_save'];

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

// The JSON `sections` field: a list of {id: int|null, name: string}.
function sections_param(bool $required = false): array
{
    if ($required && !isset($_POST['sections'])) {
        throw new InvalidArgumentException('sections is required.');
    }
    $list = json_decode((string) ($_POST['sections'] ?? '[]'), true);
    $good = is_array($list) && array_is_list($list);
    foreach ($good ? $list : [] as $s) {
        $good = $good && is_array($s) && is_string($s['name'] ?? null) && (($s['id'] ?? null) === null || is_int($s['id']));
    }
    if (!$good) {
        throw new InvalidArgumentException('sections must be a JSON list of {id, name}.');
    }
    return $list;
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
                'projects' => array_map(fn($p) => ['archived' => (bool) $p['archived']] + $p
                    + ['sections' => array_column(list_sections($db, $p['name']), 'name')],
                    $db->query('SELECT name, color, folder, site_name, archived FROM projects ORDER BY id')->fetchAll()),
                'columns' => COLUMNS,
                'version' => board_version(),
                'backup' => backup_status(backup_dir(), backup_copy_dir()),
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
        case 'chat':
            $chat = find_chat($db, int_param('id'));
            if ($chat === null) {
                forget_chats($db, int_param('id'));
                out(['error' => "This card's chat was archived or deleted."], 404);
            }
            out(['url' => 'claude://code/continue?session=' . $chat['id'], 'title' => $chat['title']]);
        case 'plan_open': // the panel's "Update plan" button
            $card = get_card($db, int_param('id'));
            out(card_open_link($db, $card, plan_prompt($card)));
        case 'add':
            out(['id' => add_card($db, $_POST['title'] ?? '', $_POST['project'] ?? '', 'user', null, $_POST['section'] ?? null)]);
        case 'edit':
            edit_card($db, int_param('id'), $_POST['title'] ?? '', $_POST['project'] ?? '', 'user',
                $_POST['was_title'] ?? null, $_POST['was_project'] ?? null);
            out(['ok' => true]);
        case 'section':
            set_section($db, int_param('id'), $_POST['section'] ?? '', 'user');
            out(['ok' => true]);
        case 'report_preview':
            $r = report_cards($db, $_GET['project'] ?? '', $_GET['from'] ?? '', $_GET['to'] ?? '');
            $count = fn(array $groups, string $k) => array_sum(array_map(fn($g) => count($g[$k]), $groups));
            $other = array_filter($r['sections'], fn($g) => $g['other']); // report_cards' group for cards with no section
            out(['done' => $count($r['sections'], 'done'), 'underway' => $count($r['sections'], 'underway'),
                'planned' => $count($r['sections'], 'planned'),
                'no_section' => $count($other, 'done') + $count($other, 'underway')]);
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
        case 'projects':
            out(['projects' => list_projects($db), 'colors' => PROJECT_COLORS]);
        case 'project_add':
            out(['id' => add_project($db, $_POST['name'] ?? '', $_POST['folder'] ?? '', $_POST['color'] ?? '',
                $_POST['site_name'] ?? null, sections_param(), 'user')]);
        case 'project_edit':
            edit_project($db, int_param('id'), $_POST['name'] ?? '', $_POST['folder'] ?? '', $_POST['color'] ?? '',
                $_POST['site_name'] ?? null, 'user');
            out(['ok' => true]);
        case 'project_archive':
            set_project_archived($db, int_param('id'), (bool) int_param('archived'), 'user');
            out(['ok' => true]);
        case 'sections_save':
            save_sections($db, int_param('id'), sections_param(true), 'user');
            out(['ok' => true]);
    }
    $id = int_param('id');
    $col = $_POST['col'] ?? '';
    $from = card_col($db, $id);
    move_card($db, $id, $col, 'user', int_param('position', true));
    $card = get_card($db, $id);
    $o = $from === $col ? null : card_open_link($db, $card);
    out(['ok' => true, 'link' => $o['link'] ?? null, 'prompt' => $o['prompt'] ?? null, 'chat' => $o['chat'] ?? null]);
} catch (CardConflict $e) {
    out(['error' => $e->getMessage(), 'conflict' => true], 409);
} catch (InvalidArgumentException $e) {
    out(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    if (is_duplicate_key($e)) { // the name checks passed, then another save took the name first
        out(['error' => 'That name was just taken by another save. Reload and pick another name.'], 400);
    }
    out(['error' => 'Server error: ' . $e->getMessage()], 500);
}
