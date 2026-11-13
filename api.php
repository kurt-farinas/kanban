<?php
// JSON API for the web board: GET ?a=list[&project=Name], POST a=add (title, project), POST a=move (id, col, position).
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function int_param(string $name, bool $optional = false): ?int
{
    $v = $_POST[$name] ?? '';
    if ($optional && $v === '') {
        return null;
    }
    if (!ctype_digit($v)) {
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
if (!in_array($a, ['list', 'add', 'move'], true)) {
    out(['error' => "Unknown action '$a'."], 400);
}
if ($post !== ($a !== 'list')) {
    out(['error' => $post ? 'Use GET for list.' : "Use POST for $a."], 405);
}

try {
    try {
        $db = db();
    } catch (PDOException $e) {
        out(['error' => "Can't reach the database. Is MySQL running in XAMPP?"], 503);
    }
    if ($a === 'list') {
        $project = $_GET['project'] ?? '';
        out([
            'cards' => list_cards($db, $project === '' ? null : $project),
            'projects' => $db->query('SELECT name, color FROM projects ORDER BY id')->fetchAll(),
            'columns' => COLUMNS,
        ]);
    }
    if ($a === 'add') {
        out(['id' => add_card($db, $_POST['title'] ?? '', $_POST['project'] ?? '', 'user')]);
    }
    $id = int_param('id');
    $col = $_POST['col'] ?? '';
    $from = card_col($db, $id);
    move_card($db, $id, $col, 'user', int_param('position', true));
    $st = $db->prepare(CARD_SELECT . ' WHERE c.id = ?');
    $st->execute([$id]);
    $card = $st->fetch();
    out(['ok' => true, 'link' => $from === $col ? null : claude_link($card, $card['folder'])]);
} catch (InvalidArgumentException $e) {
    out(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    out(['error' => 'Server error: ' . $e->getMessage()], 500);
}
