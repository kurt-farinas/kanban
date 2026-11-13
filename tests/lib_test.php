<?php
// Plain test runner: php tests/lib_test.php. Rebuilds database kanban_test from schema.sql + seed.example.sql each run.
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/lib.php';

function ok(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new Exception($msg);
    }
}

function throws(callable $fn, string $msg): void
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new Exception("expected InvalidArgumentException: $msg");
}

function card(PDO $db, int $id): array
{
    $st = $db->prepare('SELECT * FROM cards WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

// "position:title" for every card in a column, in list_cards order.
function col_order(PDO $db, string $col = 'todo'): array
{
    $rows = array_filter(list_cards($db), fn($r) => $r['col'] === $col);
    return array_map(fn($r) => $r['position'] . ':' . $r['title'], array_values($rows));
}

function prompt_of(string $url): string
{
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    return $q['prompt'];
}

$tests = [
    'move_changes_column' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        move_card($db, $id, 'building', 'claude');
        $c = card($db, $id);
        ok($c['col'] === 'building', "col is {$c['col']}");
        ok($c['updated_by'] === 'claude', "updated_by is {$c['updated_by']}");
    },
    'move_rejects_bad_column' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        throws(fn() => move_card($db, $id, 'done', 'user'), 'column done');
        ok(card($db, $id)['col'] === 'todo', 'col changed');
    },
    'move_unknown_id_throws' => function (PDO $db) {
        throws(fn() => move_card($db, 9999, 'review', 'user'), 'id 9999');
    },
    'add_rejects_unknown_project' => function (PDO $db) {
        add_card($db, 'A', 'Alpha', 'user');
        throws(fn() => add_card($db, 'X', 'Nope', 'user'), 'project Nope');
        ok(count(list_cards($db)) === 1, 'card count changed');
    },
    'add_rejects_empty_title' => function (PDO $db) {
        throws(fn() => add_card($db, '  ', 'Alpha', 'user'), 'blank title');
        ok(count(list_cards($db)) === 0, 'card written');
    },
    'block_then_unblock' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        set_blocked($db, $id, 'Need answer', 'claude');
        $c = card($db, $id);
        ok($c['blocked'] == 1 && $c['blocked_reason'] === 'Need answer', 'not blocked');
        set_blocked($db, $id, null, 'user');
        $c = card($db, $id);
        ok($c['blocked'] == 0 && $c['blocked_reason'] === null, 'not unblocked');
    },
    'link_for_planning' => function (PDO $db) {
        $card = ['id' => 7, 'title' => 'Add search bar', 'project' => 'Alpha', 'col' => 'planning'];
        $url = claude_link($card, 'C:\xampp\htdocs\alpha');
        ok(str_starts_with($url, 'claude://code/new?prompt='), $url);
        ok(str_contains($url, '&folder=' . rawurlencode('C:\xampp\htdocs\alpha')), "folder missing: $url");
        ok(str_contains($url, rawurlencode('Plan card #7')), "prompt missing: $url");
        ok(claude_link(['col' => 'todo'] + $card, 'x') === null, 'todo link');
        ok(claude_link(['col' => 'test'] + $card, 'x') === null, 'test link');
    },
    'title_kept_raw' => function (PDO $db) {
        add_card($db, '<b>"Hi"</b>', 'Alpha', 'user');
        ok(list_cards($db)[0]['title'] === '<b>"Hi"</b>', 'title changed');
        ok((list_cards($db)[0]['folder'] ?? null) === 'C:\xampp\htdocs\alpha', 'folder missing');
    },
    'move_to_position_renumbers' => function (PDO $db) {
        add_card($db, 'A', 'Alpha', 'user');
        add_card($db, 'B', 'Alpha', 'user');
        $c = add_card($db, 'C', 'Alpha', 'user');
        move_card($db, $c, 'todo', 'user', 0);
        ok(col_order($db) === ['0:C', '1:A', '2:B'], implode(',', col_order($db)));
    },
    // Extra: branches the nine above do not reach.
    'move_out_renumbers_source_and_keeps_block' => function (PDO $db) {
        add_card($db, 'A', 'Alpha', 'user');
        $b = add_card($db, 'B', 'Beta', 'user');
        add_card($db, 'C', 'Alpha', 'user');
        set_blocked($db, $b, 'Waiting', 'user');
        ok(card($db, $b)['col'] === 'todo', 'block moved card');
        move_card($db, $b, 'review', 'claude', 5);
        ok(col_order($db) === ['0:A', '1:C'], implode(',', col_order($db)));
        $c = card($db, $b);
        ok($c['col'] === 'review' && $c['position'] == 0 && $c['blocked'] == 1, 'moved card wrong');
        throws(fn() => move_card($db, $b, 'todo', 'user', -1), 'negative position');
    },
    'add_validates_title_project_case_and_by' => function (PDO $db) {
        throws(fn() => add_card($db, str_repeat('x', 201), 'Alpha', 'user'), '201 chars');
        throws(fn() => add_card($db, 'A', 'Alpha', 'bot'), 'by bot');
        $id = add_card($db, str_repeat('x', 200), 'delta two', 'claude');
        ok(list_cards($db, 'DELTA TWO')[0]['id'] == $id, 'case-insensitive project');
        throws(fn() => set_blocked($db, 9999, 'x', 'user'), 'block unknown id');
        throws(fn() => set_blocked($db, $id, 'x', 'bot'), 'block by bot');
    },
    'find_cards_matches_title_literally' => function (PDO $db) {
        add_card($db, 'Fix 50% bug', 'Alpha', 'user');
        $id = add_card($db, 'Fix 50 bugs', 'Gamma', 'user');
        move_card($db, $id, 'live', 'user');
        ok(count(find_cards($db, 'fix')) === 2, 'search not across columns/case');
        ok(count(find_cards($db, '50%')) === 1, '% not literal');
    },
    'link_prompts_and_cap' => function (PDO $db) {
        $card = ['id' => 3, 'title' => 'T', 'project' => 'Beta'];
        $p = fn($col, $title = 'T') => prompt_of(claude_link(['col' => $col, 'title' => $title] + $card, 'f'));
        ok($p('building') === 'Build the plan for card #3 (Beta): T', $p('building'));
        ok($p('review') === 'Review the code for card #3 (Beta): T', $p('review'));
        ok($p('live') === 'Deploy card #3 (Beta): T', $p('live'));
        ok(mb_strlen($p('live', str_repeat('é', 5000))) === 4000, 'prompt not capped');
    },
    'schema_seeds_folders_with_backslashes' => function (PDO $db) {
        $folders = $db->query('SELECT folder FROM projects ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        ok($folders[0] === 'C:\xampp\htdocs\alpha', $folders[0]);
        ok($folders[3] === 'C:\Users\Example\delta-two', $folders[3]);
    },
];

$server = db('');
$server->exec('DROP DATABASE IF EXISTS kanban_test');
$server->exec('CREATE DATABASE kanban_test CHARACTER SET utf8mb4');
$db = db('kanban_test');
$db->exec(file_get_contents(dirname(__DIR__) . '/schema.sql'));
$db->exec(file_get_contents(dirname(__DIR__) . '/seed.example.sql'));

$pass = $fail = 0;
foreach ($tests as $name => $fn) {
    $db->exec('DELETE FROM cards');
    try {
        $fn($db);
        echo "PASS $name\n";
        $pass++;
    } catch (Throwable $e) {
        echo "FAIL $name: {$e->getMessage()}\n";
        $fail++;
    }
}
$server->exec('DROP DATABASE kanban_test');
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
