<?php
const COLUMNS = ['todo', 'planning', 'building', 'review', 'test', 'live'];
const STUCK_DAYS = 3;
const STUCK_COLUMNS = ['planning', 'building', 'review', 'test'];

// Newest file time of the code the page runs; an open board reloads itself when this changes.
function board_version(): int
{
    $files = ['index.php', 'app.js', 'style.css', 'api.php', 'lib.php'];
    return max(array_map(fn($f) => (int) @filemtime(__DIR__ . '/' . $f), $files));
}

// ORDER BY c.col sorts by ENUM index, which is COLUMNS order.
const CARD_SELECT = 'SELECT c.id, c.title, c.col, c.position, c.blocked, c.blocked_reason,
        p.name AS project, p.color, p.folder, c.updated_by, c.updated_at,
        c.status, c.working_since, c.plan_path, c.request_id, c.live_at, c.section_id, s.name AS section,
        r.kind AS request_kind, r.requester AS request_requester,
        (SELECT COUNT(*) FROM notes n WHERE n.card_id = c.id) AS note_count,
        EXISTS (SELECT 1 FROM chat_links cl WHERE cl.card_id = c.id) AS has_chat,
        GREATEST(c.updated_at, COALESCE((SELECT MAX(n.created_at) FROM notes n WHERE n.card_id = c.id), c.updated_at),
            COALESCE((SELECT MAX(e.created_at) FROM events e WHERE e.card_id = c.id), c.updated_at)) AS last_activity,
        NOW() AS now_at
    FROM cards c JOIN projects p ON p.id = c.project_id LEFT JOIN requests r ON r.id = c.request_id
        LEFT JOIN sections s ON s.id = c.section_id';
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
        $rows = $db->query(CARD_SELECT . CARD_ORDER)->fetchAll();
    } else {
        $st = $db->prepare(CARD_SELECT . ' WHERE c.project_id = ?' . CARD_ORDER);
        $st->execute([project_id($db, $project)]);
        $rows = $st->fetchAll();
    }
    return array_map('with_stuck_days', $rows);
}

// Whole days since last activity, measured on the DB clock (now_at), or 0 when the card is not stuck.
function with_stuck_days(array $card): array
{
    $days = (new DateTime($card['last_activity']))->diff(new DateTime($card['now_at']))->days;
    $card['stuck_days'] = in_array($card['col'], STUCK_COLUMNS, true) && $card['working_since'] === null
        && $days >= STUCK_DAYS ? $days : 0;
    return $card;
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

function add_card(PDO $db, string $title, string $project, string $by, ?int $requestId = null, ?string $section = null): int
{
    check_by($by);
    check_title($title);
    $pid = project_id($db, $project);
    $sid = section_id($db, $pid, $project, $section)['id'];
    return in_tx($db, function () use ($db, $pid, $title, $by, $project, $requestId, $sid) {
        $st = $db->prepare("INSERT INTO cards (project_id, title, col, position, updated_by, request_id, section_id)
            SELECT ?, ?, 'todo', COALESCE(MAX(position) + 1, 0), ?, ?, ? FROM cards WHERE col = 'todo'");
        $st->execute([$pid, $title, $by, $requestId, $sid]);
        $id = (int) $db->lastInsertId();
        log_event($db, $id, $by, 'added', $project);
        return $id;
    });
}

// The board's edit form sends the title and project it was filled with; a different current value means
// Claude changed the card meanwhile. Fields are compared, not updated_at, so moves and notes do not conflict.
class CardConflict extends InvalidArgumentException {}

function edit_card(PDO $db, int $id, string $title, string $project, string $by,
    ?string $wasTitle = null, ?string $wasProject = null): void
{
    check_by($by);
    check_title($title);
    $pid = project_id($db, $project);
    in_tx($db, function () use ($db, $id, $pid, $title, $by, $project, $wasTitle, $wasProject) {
        card_col($db, $id, true);
        $st = $db->prepare('SELECT c.title, p.name FROM cards c JOIN projects p ON p.id = c.project_id WHERE c.id = ? FOR UPDATE');
        $st->execute([$id]);
        [$curTitle, $curProject] = $st->fetch(PDO::FETCH_NUM);
        if (($wasTitle !== null && $wasTitle !== $curTitle) || ($wasProject !== null && $wasProject !== $curProject)) {
            throw new CardConflict('Claude changed this card while you were editing. Your edit was not saved.');
        }
        // Sections belong to one project, so a project change clears it; section_id is set first, before project_id changes.
        $db->prepare('UPDATE cards SET section_id = IF(project_id = ?, section_id, NULL), title = ?, project_id = ?,
                updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$pid, $title, $pid, $by, $id]);
        log_event($db, $id, $by, 'edited', $project);
    });
}

// --- Sections: a website's parts, in report order; a card carries at most one of its own project's ---

function list_sections(PDO $db, string $project): array
{
    $st = $db->prepare('SELECT id, name, position FROM sections WHERE project_id = ? ORDER BY position, id');
    $st->execute([project_id($db, $project)]);
    return $st->fetchAll();
}

// ['id' => …, 'name' => …] of $section in project $pid (case ignored); null or blank gives both null.
function section_id(PDO $db, int $pid, string $project, ?string $section): array
{
    if ($section === null || trim($section) === '') {
        return ['id' => null, 'name' => null];
    }
    $st = $db->prepare('SELECT id, name FROM sections WHERE project_id = ? ORDER BY position, id');
    $st->execute([$pid]);
    $rows = $st->fetchAll();
    foreach ($rows as $r) {
        if (mb_strtolower($r['name']) === mb_strtolower(trim($section))) {
            return ['id' => (int) $r['id'], 'name' => $r['name']];
        }
    }
    throw new InvalidArgumentException("Unknown section '$section' for $project. Sections: "
        . (implode(', ', array_column($rows, 'name')) ?: 'none'));
}

// Null or blank clears it.
function set_section(PDO $db, int $id, ?string $section, string $by): void
{
    check_by($by);
    in_tx($db, function () use ($db, $id, $section, $by) {
        card_col($db, $id, true);
        $card = get_card($db, $id);
        $s = section_id($db, project_id($db, $card['project']), $card['project'], $section);
        $db->prepare('UPDATE cards SET section_id = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$s['id'], $by, $id]);
        log_event($db, $id, $by, 'section', $s['name'] ?? 'none');
    });
}

// --- Projects editor: add and edit projects and their sections from the board (no delete) ---

const PROJECT_COLORS = ['purple', 'teal', 'coral', 'pink', 'blue', 'green', 'gold', 'slate'];

// Every project in id order with its card count and its sections (each with its card count), in report order.
function list_projects(PDO $db): array
{
    $projects = $db->query('SELECT p.id, p.name, p.folder, p.color, p.site_name,
            (SELECT COUNT(*) FROM cards c WHERE c.project_id = p.id) AS card_count
        FROM projects p ORDER BY p.id')->fetchAll();
    $st = $db->prepare('SELECT s.id, s.name, s.position, (SELECT COUNT(*) FROM cards c WHERE c.section_id = s.id) AS card_count
        FROM sections s WHERE s.project_id = ? ORDER BY s.position, s.id');
    foreach ($projects as &$p) {
        $st->execute([$p['id']]);
        $p['sections'] = array_map(fn($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'position' => (int) $s['position'],
            'card_count' => (int) $s['card_count']], $st->fetchAll());
        $p['id'] = (int) $p['id'];
        $p['card_count'] = (int) $p['card_count'];
    }
    return $projects;
}

// An existing folder on a drive (no UNC: it is stat'ed on every poll); returns its real path, so slashes and a
// trailing \ are normalised. Explorer's "Copy as path" quotes are dropped.
function check_folder(string $f): string
{
    $f = preg_replace('/^"(.*)"$/s', '$1', trim($f));
    $real = preg_match('#^[A-Za-z]:[\\\\/]#', $f) ? realpath($f) : false;
    if (mb_strlen($f) > 255 || ($real !== false && mb_strlen($real) > 255)) {
        throw new InvalidArgumentException('Folder path is longer than 255 characters.');
    }
    if ($real === false || !is_dir($real)) {
        throw new InvalidArgumentException("Folder must be an existing full path, like C:\\xampp\\htdocs\\my-site ($f).");
    }
    return $real;
}

// Checks name, folder, colour and report name; returns them cleaned up. $id is the project being edited, if any.
function check_project(PDO $db, ?int $id, string $name, string $folder, string $color, ?string $siteName): array
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 50) {
        throw new InvalidArgumentException('Project name must be 1 to 50 characters.');
    }
    // The column's collation decides, so case and accent variants ("Café", "cafe") count as the same name.
    $st = $db->prepare('SELECT id FROM projects WHERE name = ? AND id <> ?');
    $st->execute([$name, $id ?? 0]);
    if ($st->fetchColumn() !== false) {
        throw new InvalidArgumentException("A project with that name already exists ($name).");
    }
    if (!in_array($color, PROJECT_COLORS, true)) {
        throw new InvalidArgumentException("Unknown colour '$color' (use " . implode(', ', PROJECT_COLORS) . ').');
    }
    $siteName = $siteName === null || trim($siteName) === '' ? null : trim($siteName);
    if ($siteName !== null && mb_strlen($siteName) > 100) {
        throw new InvalidArgumentException('Report name is longer than 100 characters.');
    }
    return [$name, check_folder($folder), $siteName];
}

function add_project(PDO $db, string $name, string $folder, string $color, ?string $siteName, array $sections, string $by): int
{
    check_by($by);
    [$name, $folder, $siteName] = check_project($db, null, $name, $folder, $color, $siteName);
    return in_tx($db, function () use ($db, $name, $folder, $color, $siteName, $sections, $by) {
        $db->prepare('INSERT INTO projects (name, folder, color, site_name) VALUES (?, ?, ?, ?)')
            ->execute([$name, $folder, $color, $siteName]);
        $id = (int) $db->lastInsertId();
        log_request_event($db, $name, $by, 'project', "added $name");
        write_sections($db, $id, $name, $sections, $by);
        return $id;
    });
}

// Cards point at the project id, so a rename keeps them.
function edit_project(PDO $db, int $id, string $name, string $folder, string $color, ?string $siteName, string $by): void
{
    check_by($by);
    [$name, $folder, $siteName] = check_project($db, $id, $name, $folder, $color, $siteName);
    in_tx($db, function () use ($db, $id, $name, $folder, $color, $siteName, $by) {
        $st = $db->prepare('SELECT name, folder, color, site_name FROM projects WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $old = $st->fetch() ?: throw new InvalidArgumentException("No project #$id.");
        if ($old === ['name' => $name, 'folder' => $folder, 'color' => $color, 'site_name' => $siteName]) {
            return; // nothing changed: nothing to write or log
        }
        $db->prepare('UPDATE projects SET name = ?, folder = ?, color = ?, site_name = ? WHERE id = ?')
            ->execute([$name, $folder, $color, $siteName, $id]);
        log_request_event($db, $name, $by, 'project', $old['name'] === $name ? "edited $name" : "renamed {$old['name']} to $name");
    });
}

// $sections: the whole ordered list, each ['id' => ?int, 'name' => string]. An id renames, no id adds,
// a missing one is removed (refused while cards use it); positions follow the list.
function save_sections(PDO $db, int $projectId, array $sections, string $by): void
{
    check_by($by);
    in_tx($db, function () use ($db, $projectId, $sections, $by) {
        $st = $db->prepare('SELECT name FROM projects WHERE id = ? FOR UPDATE');
        $st->execute([$projectId]);
        $name = $st->fetchColumn();
        if ($name === false) {
            throw new InvalidArgumentException("No project #$projectId.");
        }
        write_sections($db, $projectId, $name, $sections, $by);
    });
}

// A MySQL UNIQUE-key violation (error 1062), e.g. two saves racing for one project or section name.
function is_duplicate_key(Throwable $e): bool
{
    return $e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062;
}

// save_sections without its transaction, so add_project can run it inside its own.
function write_sections(PDO $db, int $pid, string $project, array $sections, string $by): void
{
    $want = [];
    foreach (array_values($sections) as $i => $s) {
        $n = trim((string) ($s['name'] ?? ''));
        if ($n === '' || mb_strlen($n) > 60) {
            throw new InvalidArgumentException('Section name must be 1 to 60 characters.');
        }
        $sid = $s['id'] ?? null;
        $sid = $sid === null || $sid === '' ? null : (int) $sid;
        if ($sid !== null && $sid < 1) {
            throw new InvalidArgumentException("Section id must be 1 or more (got $sid).");
        }
        $want[] = ['id' => $sid, 'name' => $n, 'position' => $i + 1];
    }
    $st = $db->prepare('SELECT s.id, s.name, s.position, (SELECT COUNT(*) FROM cards c WHERE c.section_id = s.id) AS used
        FROM sections s WHERE s.project_id = ? ORDER BY s.position, s.id FOR UPDATE');
    $st->execute([$pid]);
    $have = array_column($st->fetchAll(), null, 'id');
    $kept = array_filter(array_column($want, 'id'));
    if (count($kept) !== count(array_unique($kept))) {
        throw new InvalidArgumentException('A section is in the list twice.');
    }
    foreach ($kept as $sid) {
        if (!isset($have[$sid])) {
            throw new InvalidArgumentException("Section #$sid is not one of $project's.");
        }
    }
    $before = array_map(fn($s) => [(int) $s['id'], $s['name'], (int) $s['position']], array_values($have));
    foreach ($have as $sid => $s) {
        if (!in_array($sid, $kept, true) && $s['used'] > 0) {
            throw new InvalidArgumentException("Section {$s['name']} is used by {$s['used']} " . ($s['used'] == 1 ? 'card' : 'cards')
                . '; give those cards another section first.');
        }
    }
    $db->prepare('DELETE FROM sections WHERE project_id = ? AND id NOT IN (' . implode(',', [0, ...$kept]) . ')')->execute([$pid]);
    // Swapping two names would hit UNIQUE (project_id, name) halfway, so kept rows step through a placeholder name.
    $tmp = $db->prepare("UPDATE sections SET name = CONCAT('~', id) WHERE id = ?");
    foreach ($kept as $sid) {
        $tmp->execute([$sid]);
    }
    $upd = $db->prepare('UPDATE sections SET name = ?, position = ? WHERE id = ?');
    $ins = $db->prepare('INSERT INTO sections (project_id, name, position) VALUES (?, ?, ?)');
    // Removed rows are gone and kept ones hold placeholders, so a match is a name written earlier in this list;
    // the column's collation decides, so "Café" and "cafe" count as the same.
    $dup = $db->prepare('SELECT 1 FROM sections WHERE project_id = ? AND name = ?');
    $after = [];
    foreach ($want as $w) {
        $dup->execute([$pid, $w['name']]);
        if ($dup->fetchColumn() !== false) {
            throw new InvalidArgumentException("A section with that name already exists ({$w['name']}).");
        }
        if ($w['id'] !== null) {
            $upd->execute([$w['name'], $w['position'], $w['id']]);
        } else {
            $ins->execute([$pid, $w['name'], $w['position']]);
            $w['id'] = (int) $db->lastInsertId();
        }
        $after[] = [$w['id'], $w['name'], $w['position']];
    }
    if ($before !== $after) {
        log_request_event($db, $project, $by, 'project', "sections of $project: " . (implode(', ', array_column($want, 'name')) ?: 'none'));
    }
}

const REPORT_UNDERWAY = ['building', 'review', 'test'];

function check_date(string $d): void
{
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    if ($dt === false || $dt->format('Y-m-d') !== $d) {
        throw new InvalidArgumentException("Bad date '$d' (use YYYY-MM-DD).");
    }
}

// A project's cards for the president report, one group per section in report order, then "Other changes".
// Done = Live with live_at in [from, to]; underway = Building, Review or Test (the current column wins). Empty groups are left out.
function report_cards(PDO $db, string $project, string $from, string $to): array
{
    check_date($from);
    check_date($to);
    if ($from > $to) {
        throw new InvalidArgumentException("Start date $from is after end date $to.");
    }
    $st = $db->prepare('SELECT id, name, site_name FROM projects WHERE id = ?');
    $st->execute([project_id($db, $project)]);
    $p = $st->fetch();
    $groups = [];
    foreach (list_sections($db, $p['name']) as $s) {
        $groups[(int) $s['id']] = ['name' => $s['name'], 'other' => false, 'done' => [], 'underway' => []];
    }
    $groups[0] = ['name' => 'Other changes', 'other' => true, 'done' => [], 'underway' => []];

    $in = implode(',', array_fill(0, count(REPORT_UNDERWAY), '?'));
    $st = $db->prepare("SELECT id, title, col, live_at, section_id FROM cards
        WHERE project_id = ? AND ((col = 'live' AND live_at BETWEEN ? AND ?) OR col IN ($in))
        ORDER BY col, position, id");
    $st->execute([$p['id'], $from, $to, ...REPORT_UNDERWAY]);
    $notes = $db->prepare('SELECT body FROM notes WHERE card_id = ? ORDER BY id');
    foreach ($st->fetchAll() as $c) {
        $notes->execute([$c['id']]);
        $card = ['id' => (int) $c['id'], 'title' => $c['title'], 'col' => $c['col'], 'live_at' => $c['live_at'],
            'notes' => $notes->fetchAll(PDO::FETCH_COLUMN)];
        $key = isset($groups[(int) $c['section_id']]) ? (int) $c['section_id'] : 0;
        $groups[$key][$c['col'] === 'live' ? 'done' : 'underway'][] = $card;
    }
    return ['site_name' => $p['site_name'], 'project' => $p['name'], 'from' => $from, 'to' => $to,
        'sections' => array_values(array_filter($groups, fn($g) => $g['done'] || $g['underway']))];
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

// With $after: only events newer than that id, oldest first (for polling); without: the newest, newest first.
function list_events(PDO $db, int $limit = 50, ?int $after = null): array
{
    $sql = 'SELECT id, card_id, card_title, author, action, detail, created_at FROM events';
    if ($after !== null) {
        $st = $db->prepare("$sql WHERE id > ? ORDER BY id LIMIT " . max(1, $limit));
        $st->execute([$after]);
        return $st->fetchAll();
    }
    return $db->query("$sql ORDER BY id DESC LIMIT " . max(1, $limit))->fetchAll();
}

function move_card(PDO $db, int $id, string $col, string $by, ?int $position = null): void
{
    check_col($col);
    check_by($by);
    if ($position !== null && $position < 0) {
        throw new InvalidArgumentException('Position must be 0 or more.');
    }
    retry_deadlock(fn() => move_card_once($db, $id, $col, $by, $position));
}

// Concurrent moves in opposite directions can deadlock; InnoDB rolls one back, so retry it (3 tries).
function retry_deadlock(callable $fn): mixed
{
    for ($try = 1; ; $try++) {
        try {
            return $fn();
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
        if ($from === $col) {
            $st = $db->prepare('SELECT position FROM cards WHERE id = ?');
            $st->execute([$id]);
            if ((int) $st->fetchColumn() === $position) { // dropped where it was: nothing to write or log
                $db->commit();
                return;
            }
        }
        array_splice($ids, $position, 0, [$id]);

        $db->prepare('UPDATE cards SET col = ?, position = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$col, $position, $by, $id]);
        renumber($db, $ids, $id);
        if ($col === 'live' && $from !== 'live') {
            $db->prepare('UPDATE cards SET live_at = CURDATE(), updated_at = updated_at WHERE id = ?')->execute([$id]);
        }
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

// --- Requests: what people asked for; each becomes one or more cards linked by cards.request_id ---

const REQUEST_KINDS = ['do', 'change', 'remove'];

// A request has no card, so its event carries card_id NULL and the request text as the title.
function log_request_event(PDO $db, string $body, string $by, string $action, string $detail): void
{
    $db->prepare('INSERT INTO events (card_id, card_title, author, action, detail) VALUES (NULL, ?, ?, ?, ?)')
        ->execute([mb_substr($body, 0, 200), $by, $action, mb_substr($detail, 0, 200)]);
}

function add_request(PDO $db, string $body, string $requester, string $project, string $kind, string $by): int
{
    check_by($by);
    if (trim($body) === '' || mb_strlen($body) > 500) {
        throw new InvalidArgumentException('Request text must be 1 to 500 characters.');
    }
    if (trim($requester) === '' || mb_strlen($requester) > 100) {
        throw new InvalidArgumentException('Who asked must be 1 to 100 characters.');
    }
    if (!in_array($kind, REQUEST_KINDS, true)) {
        throw new InvalidArgumentException("Unknown kind '$kind' (use do, change or remove).");
    }
    $pid = project_id($db, $project);
    $requester = trim($requester);
    return in_tx($db, function () use ($db, $body, $requester, $pid, $kind, $by, $project) {
        $db->prepare('INSERT INTO requests (body, requester, project_id, kind) VALUES (?, ?, ?, ?)')
            ->execute([$body, $requester, $pid, $kind]);
        $id = (int) $db->lastInsertId();
        log_request_event($db, $body, $by, 'request', "$kind · $requester · $project");
        return $id;
    });
}

// Newest first; state is new (no cards), done (every card in Live) or in_progress.
function list_requests(PDO $db): array
{
    $rows = $db->query("SELECT r.id, r.body, r.requester, r.kind, r.created_at, p.name AS project, p.color,
            COALESCE(SUM(c.col = 'live'), 0) AS cards_live,
            COUNT(c.id) AS cards_total
        FROM requests r JOIN projects p ON p.id = r.project_id LEFT JOIN cards c ON c.request_id = r.id
        GROUP BY r.id ORDER BY r.id DESC")->fetchAll();
    foreach ($rows as &$r) {
        $r['cards_total'] = (int) $r['cards_total'];
        $r['cards_live'] = (int) ($r['cards_live'] ?? 0);
        $r['state'] = $r['cards_total'] === 0 ? 'new' : ($r['cards_live'] === $r['cards_total'] ? 'done' : 'in_progress');
    }
    return $rows;
}

function requesters(PDO $db): array
{
    return $db->query('SELECT DISTINCT requester FROM requests ORDER BY requester')->fetchAll(PDO::FETCH_COLUMN);
}

function card_from_request(PDO $db, int $requestId, string $title, string $by): int
{
    $st = $db->prepare('SELECT p.name FROM requests r JOIN projects p ON p.id = r.project_id WHERE r.id = ?');
    $st->execute([$requestId]);
    $project = $st->fetchColumn();
    if ($project === false) {
        throw new InvalidArgumentException("No request #$requestId.");
    }
    return add_card($db, $title, $project, $by, $requestId);
}

function delete_request(PDO $db, int $id, string $by): void
{
    check_by($by);
    in_tx($db, function () use ($db, $id, $by) {
        $st = $db->prepare('SELECT body, requester FROM requests WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $r = $st->fetch() ?: throw new InvalidArgumentException("No request #$id.");
        $st = $db->prepare('SELECT COUNT(*) FROM cards WHERE request_id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn() > 0) {
            throw new InvalidArgumentException('This request has cards; delete its cards first.');
        }
        log_request_event($db, $r['body'], $by, 'request_deleted', $r['requester']);
        $db->prepare('DELETE FROM requests WHERE id = ?')->execute([$id]);
    });
}

// --- Live from DEPLOYMENTS.md: a verified deploy entry naming "Card: #N" (or "card #N" in its heading) moves card N to Live ---

// One section: [date, title, card ids, entry key], or null when it is not a dated, verified, UTF-8 entry.
// The key is the heading's date + time only, so editing an entry's title later does not apply it again.
function parse_deploy_entry(string $section): ?array
{
    $lines = explode("\n", str_replace("\r", '', $section));
    if (!mb_check_encoding($section, 'UTF-8')
        || !preg_match('/^##[ \t]+(\d{4})-(\d{2})-(\d{2})(?:[ \t]+(\d{1,2}:\d{2}))?\s*(.*)$/u', $lines[0], $h)
        || !checkdate((int) $h[2], (int) $h[3], (int) $h[1])
        || !deploy_verified($lines)) {
        return null;
    }
    $parts = preg_split('/\s+[—-]\s+/u', preg_replace('/^[—-]\s*/u', '', trim($h[5])));
    $title = preg_replace('/\s*\(cards?:?\s*#[^)]*\)\s*$/iu', '', trim(end($parts)));
    $ids = [];
    foreach ([$lines[0], ...preg_grep('/^\s*-\s*Cards?:/iu', $lines)] as $line) {
        if (preg_match_all('/\bcards?:?\s*(#\d{1,9}(?:(?:\s*(?:,|&|\band\b)\s*|\s+)#\d{1,9})*)/iu', $line, $m)) {
            preg_match_all('/#(\d+)/', implode(' ', $m[1]), $n);
            array_push($ids, ...array_map('intval', $n[1]));
        }
    }
    $date = "$h[1]-$h[2]-$h[3]";
    return [$date, $title, array_unique($ids), trim("$date $h[4]")];
}

// The first "- Verified:" line has text, or a more indented non-empty line right under it,
// and that text is not "none", "not …", "no …", "skipped", "pending" or an untouched "<template>".
function deploy_verified(array $lines): bool
{
    foreach ($lines as $i => $line) {
        if (preg_match('/^([ \t]*)-[ \t]*Verified:(.*)$/iu', $line, $m)) {
            $text = trim($m[2]);
            $next = $lines[$i + 1] ?? '';
            if ($text === '' && trim($next) !== '' && strspn($next, " \t") > strlen($m[1])) {
                $text = preg_replace('/^[-*+][ \t]*/', '', trim($next));
            }
            return $text !== '' && !preg_match('/^(?:none|not|no\b|skipped|pending|<)/iu', $text);
        }
    }
    return false;
}

// Reads each project's DEPLOYMENTS.md whose mtime changed since the last run; returns how many cards moved to Live.
// Each entry/card pair applies once (deploy_applied), so a card moved back out of Live stays out.
function sync_deployments(PDO $db): int
{
    clearstatcache();
    $seen = $db->query('SELECT file, mtime, size FROM deploy_sync')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $moved = 0;
    foreach ($db->query('SELECT id, folder FROM projects')->fetchAll() as $p) {
        $folder = rtrim(trim($p['folder']), '\\/');
        if ($folder === '') {
            continue; // a blank folder would read \DEPLOYMENTS.md
        }
        $file = $folder . DIRECTORY_SEPARATOR . 'DEPLOYMENTS.md';
        $stat = @stat($file);
        // mtime has 1-second resolution, so the size also catches a rewrite within the same second
        if ($stat === false || $stat['size'] > 5 * 1024 * 1024
            || ((int) ($seen[$file]['mtime'] ?? -1) === $stat['mtime'] && (int) $seen[$file]['size'] === $stat['size'])) {
            continue;
        }
        $text = @file_get_contents($file);
        if ($text === false) {
            continue;
        }
        foreach (preg_split('/^(?=##[ \t])/m', preg_replace('/^\xEF\xBB\xBF/', '', $text)) as $section) {
            $entry = parse_deploy_entry($section);
            if ($entry === null) {
                continue;
            }
            [$date, $title, $ids, $key] = $entry;
            foreach ($ids as $id) {
                $moved += (int) retry_deadlock(fn() => sync_card_live($db, $file, sha1($key), $id, (int) $p['id'], $date, $title));
            }
        }
        $db->prepare('INSERT INTO deploy_sync (file, mtime, size) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE mtime = VALUES(mtime), size = VALUES(size)')->execute([$file, $stat['mtime'], $stat['size']]);
    }
    return $moved;
}

// Moves one card to the end of Live for one deploy entry; false if unknown, of another project, already applied or already live.
function sync_card_live(PDO $db, string $file, string $hash, int $id, int $projectId, string $date, string $title): bool
{
    return in_tx($db, function () use ($db, $file, $hash, $id, $projectId, $date, $title) {
        $st = $db->prepare('SELECT col, project_id FROM cards WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $c = $st->fetch();
        // Recorded for an unknown card or one of another project too, so a typo'd or future number never moves a card later.
        $st = $db->prepare('INSERT IGNORE INTO deploy_applied (file, heading_hash, card_id) VALUES (?, ?, ?)');
        $st->execute([$file, $hash, $id]);
        if ($st->rowCount() === 0 || $c === false || (int) $c['project_id'] !== $projectId || $c['col'] === 'live') {
            return false;
        }
        $pos = $db->query("SELECT COALESCE(MAX(position) + 1, 0) FROM cards WHERE col = 'live' FOR UPDATE")->fetchColumn();
        $db->prepare("UPDATE cards SET col = 'live', position = ?, live_at = ?, blocked = 0, blocked_reason = NULL,
            updated_by = 'claude', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$pos, $date, $id]);
        $st = $db->prepare('SELECT id FROM cards WHERE col = ? ORDER BY position, id FOR UPDATE');
        $st->execute([$c['col']]);
        renumber($db, $st->fetchAll(PDO::FETCH_COLUMN), $id);
        log_event($db, $id, 'claude', 'live', trim("$date $title"));
        return true;
    });
}

function find_cards(PDO $db, string $text): array
{
    $st = $db->prepare(CARD_SELECT . ' WHERE c.title LIKE ?' . CARD_ORDER);
    $st->execute(['%' . addcslashes($text, '\%_') . '%']);
    return $st->fetchAll();
}

function claude_link(array $card, string $folder): ?string
{
    // Never start with "/": Claude desktop turns a leading slash in a link's prompt into a full-width "／" (no slash commands from links).
    $id = $card['id'];
    $plan = $card['plan_path'] ?? null;
    [$verb, $ask] = [
        'planning' => ['Plan', 'Use the feature-planner skill.'],
        'building' => ['Build', $plan ? "Follow the plan in $plan." : "There is no plan file yet, so read the card's notes first."],
        'review' => ['Review', 'Check the changes against ' . ($plan ? "the plan in $plan." : "the card's notes.")],
        'live' => ['Deploy', "Follow the project's deploy rules and log it in DEPLOYMENTS.md with Card: #$id."],
    ][$card['col']] ?? [null, null];
    if ($verb === null) {
        return null;
    }
    $title = rtrim($card['title'], '. ');
    $prompt = mb_substr("$verb card #$id ({$card['project']}): $title. $ask", 0, 4000);
    return 'claude://code/new?prompt=' . rawurlencode($prompt) . '&folder=' . rawurlencode($folder);
}

// Folders holding the desktop app's chat files (local_*.json); the tests point CLAUDE_SESSIONS_GLOB at fakes.
function chat_sessions_dirs(): array
{
    $glob = defined('CLAUDE_SESSIONS_GLOB') ? CLAUDE_SESSIONS_GLOB
        : getenv('LOCALAPPDATA') . '/Claude-Data/*/claude-code-sessions/*/*';
    return glob($glob, GLOB_ONLYDIR) ?: [];
}

// The desktop app's chat for the card's newest linked session that isn't archived: ['id' => 'local_…', 'title' => …] or null.
// A chat continued under a new CLI id keeps the old ones in priorCliSessionIds.
function find_chat(PDO $db, int $card): ?array
{
    // linked_at, not seen_at: the chat that last linked this card wins, not whichever touched it last.
    $st = $db->prepare('SELECT session_id FROM chat_links WHERE card_id = ? ORDER BY linked_at DESC');
    $st->execute([$card]);
    $sessions = $st->fetchAll(PDO::FETCH_COLUMN);
    $chats = []; // CLI session id => chat
    foreach ($sessions ? chat_sessions_dirs() : [] as $dir) {
        foreach (glob("$dir/local_*.json") ?: [] as $file) {
            $raw = @file_get_contents($file);
            // Skips decoding the many unrelated files (they hold large snapshots).
            if ($raw === false || !array_filter($sessions, fn($s) => str_contains($raw, $s))) {
                continue;
            }
            $j = json_decode($raw, true);
            $id = $j['sessionId'] ?? null;
            // The app opens only ids of this shape, and skips archived chats.
            if (!is_array($j) || !empty($j['isArchived']) || !is_string($id) || !preg_match('/^local_[A-Za-z0-9-]{1,64}$/', $id)) {
                continue;
            }
            $chat = ['id' => $id, 'title' => is_string($j['title'] ?? null) ? $j['title'] : ''];
            foreach ([$j['cliSessionId'] ?? null, ...(array) ($j['priorCliSessionIds'] ?? [])] as $cli) {
                if (is_string($cli)) {
                    $chats[$cli] ??= $chat;
                }
            }
        }
    }
    foreach ($sessions as $s) {
        if (isset($chats[$s])) {
            return $chats[$s];
        }
    }
    return null;
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
    // Only the prompts the board builds (claude_link, app.js planLink): a prompt that merely mentions a card must not link.
    // Older wordings stay so chats opened before 2026-11-14 still link ("／" is the app's rewrite of a leading "/").
    $verbs = '(?:\/|／)feature-planner Plan card|Plan card|Build card|Review card|Deploy card|Update the plan for card'
        . '|Build the plan for card|Review the code for card';
    return preg_match("/^\s*(?:$verbs) #(\d{1,9})\b/", $prompt, $m) ? (int) $m[1] : null;
}

// Only "board.php status N ..." links the session to card N: other commands (move, note, section) often touch other cards.
function card_from_command(string $command): ?int
{
    return preg_match('/board\.php["\']?\s+status\s+["\']?(\d{1,9})\b/i', $command, $m) ? (int) $m[1] : null;
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
    // Separate from sessions so the card keeps its Open chat button after the chat moves on to another card.
    $db->prepare('INSERT INTO chat_links (card_id, session_id) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE linked_at = NOW()')->execute([$card, $session]);
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
    // Kept for months: the card's Open chat button finds its chat through these rows.
    $db->exec('DELETE FROM chat_links WHERE linked_at < NOW() - INTERVAL 180 DAY');
    $db->exec('UPDATE cards c SET working_since = NULL, status = NULL, updated_at = updated_at
        WHERE working_since IS NOT NULL AND NOT EXISTS (SELECT 1 FROM sessions s
            WHERE s.card_id = c.id AND s.active = 1 AND s.seen_at >= NOW() - INTERVAL 30 MINUTE)');
}

// Daily database backup (board.php backup): one kanban-YYYY-MM-DD.sql per day in $dir, keeping the newest $keep.
// Returns the new file's path, or null when today's backup already exists.
const MYSQLDUMP = 'C:/xampp/mysql/bin/mysqldump.exe';

function backup_dir(): string
{
    return (getenv('USERPROFILE') ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'KanbanBackups';
}

function backup_db(string $dir, string $date, int $keep = 7, ?string $dbName = null): ?string
{
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException("Bad backup date '$date'.");
    }
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Can't create the backup folder $dir.");
    }
    $path = "$dir/kanban-$date.sql";
    if (is_file($path)) {
        return null;
    }
    $c = db_config();
    $tmp = "$path.tmp";
    $env = getenv();
    $env['MYSQL_PWD'] = $c['pass']; // the password never goes on the command line
    $proc = proc_open([MYSQLDUMP, '--host=' . $c['host'], '--user=' . $c['user'], '--single-transaction',
        '--routines', '--result-file=' . $tmp, $dbName ?? $c['db']], [2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start mysqldump.');
    }
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $tail = is_file($tmp) ? file_get_contents($tmp, false, null, max(0, filesize($tmp) - 200)) : '';
    if ($code !== 0 || !str_contains($tail, '-- Dump completed')) {
        @unlink($tmp);
        throw new RuntimeException('mysqldump failed: ' . trim($err ?: "exit code $code"));
    }
    rename($tmp, $path);
    prune_backups($dir, $keep);
    return $path;
}

function prune_backups(string $dir, int $keep): void
{
    $all = glob("$dir/kanban-????-??-??.sql");
    rsort($all); // newest first: the names sort by date
    foreach (array_slice($all, $keep) as $old) {
        unlink($old);
    }
}

// Second copy of each backup (OneDrive by default, so it survives a dead disk). BACKUP_COPY_DIR in config.local.php overrides.
function backup_copy_dir(): ?string
{
    db_config(); // loads config.local.php, which may define BACKUP_COPY_DIR
    if (defined('BACKUP_COPY_DIR')) {
        return BACKUP_COPY_DIR;
    }
    $one = getenv('OneDrive');
    return $one !== false && $one !== '' && is_dir($one) ? $one . DIRECTORY_SEPARATOR . 'KanbanBackups' : null;
}

// Copies $file into $dir via a .tmp (a half-copied file never looks complete), keeping the newest $keep there.
function copy_backup(string $file, string $dir, int $keep = 7): string
{
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Can't create the backup copy folder $dir.");
    }
    $dest = $dir . DIRECTORY_SEPARATOR . basename($file);
    if (!copy($file, "$dest.tmp") || !rename("$dest.tmp", $dest)) {
        @unlink("$dest.tmp");
        throw new RuntimeException("Could not copy $file to $dir.");
    }
    prune_backups($dir, $keep);
    return $dest;
}

// For the board's warning chip: the newest local backup's date, and whether it reached the copy folder (null: no copy folder).
function backup_status(string $dir, ?string $copyDir): array
{
    $all = glob("$dir/kanban-????-??-??.sql") ?: [];
    rsort($all);
    $name = $all ? basename($all[0]) : null;
    return [
        'date' => $name === null ? null : substr($name, 7, 10),
        'copied' => $copyDir === null ? null : $name !== null && is_file($copyDir . DIRECTORY_SEPARATOR . $name),
    ];
}

// Starts today's backup in the background when it doesn't exist yet (called from the Stop hook; never waits).
function backup_if_due(PDO $db): bool
{
    $today = $db->query('SELECT CURDATE()')->fetchColumn();
    $copy = backup_copy_dir();
    $done = is_file(backup_dir() . "/kanban-$today.sql") && ($copy === null || is_file("$copy/kanban-$today.sql"));
    if ($done || getenv('KANBAN_NO_NOTIFY') !== false) {
        return false;
    }
    pclose(popen('start "" /B wscript.exe ' . escapeshellarg(__DIR__ . '/autostart/backup.vbs'), 'r'));
    return true;
}
