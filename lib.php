<?php
const COLUMNS = ['todo', 'planning', 'building', 'review', 'test', 'live'];

// ORDER BY c.col sorts by ENUM index, which is COLUMNS order.
const CARD_SELECT = 'SELECT c.id, c.title, c.col, c.position, c.blocked, c.blocked_reason,
        p.name AS project, p.color, p.folder, c.updated_by, c.updated_at
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

function add_card(PDO $db, string $title, string $project, string $by): int
{
    check_by($by);
    if (trim($title) === '') {
        throw new InvalidArgumentException('Title is empty.');
    }
    if (mb_strlen($title) > 200) {
        throw new InvalidArgumentException('Title is longer than 200 characters.');
    }
    $st = $db->prepare("INSERT INTO cards (project_id, title, col, position, updated_by)
        SELECT ?, ?, 'todo', COALESCE(MAX(position) + 1, 0), ? FROM cards WHERE col = 'todo'");
    $st->execute([project_id($db, $project), $title, $by]);
    return (int) $db->lastInsertId();
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
    card_col($db, $id);
    $db->prepare('UPDATE cards SET blocked = ?, blocked_reason = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([(int) $blocked, $blocked ? $reason : null, $by, $id]);
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
