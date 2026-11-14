<?php
// Plain test runner: php tests/lib_test.php. Rebuilds database kanban_test from schema.sql + seed.example.sql each run.
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/lib.php';
putenv('KANBAN_NO_NOTIFY=1');

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
    'edit_changes_title_and_project' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        edit_card($db, $id, 'B', 'Beta', 'user');
        $r = list_cards($db)[0];
        ok($r['title'] === 'B' && $r['project'] === 'Beta', "{$r['title']}/{$r['project']}");
    },
    'edit_rejects_empty_title' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        throws(fn() => edit_card($db, $id, ' ', 'Alpha', 'user'), 'blank title');
        ok(card($db, $id)['title'] === 'A', 'title changed');
    },
    'delete_removes_card_notes_and_closes_gap' => function (PDO $db) {
        add_card($db, 'A', 'Alpha', 'user');
        $b = add_card($db, 'B', 'Alpha', 'user');
        add_card($db, 'C', 'Alpha', 'user');
        add_note($db, $b, 'hello', 'user');
        delete_card($db, $b, 'user');
        ok(col_order($db) === ['0:A', '1:C'], implode(',', col_order($db)));
        ok($db->query("SELECT COUNT(*) FROM notes WHERE card_id = $b")->fetchColumn() == 0, 'notes left');
        $e = list_events($db)[0];
        ok($e['action'] === 'deleted' && $e['card_title'] === 'B', "{$e['action']} {$e['card_title']}");
    },
    'note_records_author' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        add_note($db, $id, 'first', 'claude');
        $n = list_notes($db, $id);
        ok(count($n) === 1 && $n[0]['author'] === 'claude' && $n[0]['body'] === 'first', json_encode($n));
    },
    'note_rejects_empty_and_long' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        throws(fn() => add_note($db, $id, '  ', 'user'), 'blank note');
        throws(fn() => add_note($db, $id, str_repeat('é', 2001), 'user'), '2001 chars');
        add_note($db, $id, str_repeat('é', 2000), 'user');
        ok(count(list_notes($db, $id)) === 1, 'note count');
    },
    'status_caps_and_clears' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        set_status($db, $id, 'Fixing the page', 'claude');
        ok(card($db, $id)['status'] === 'Fixing the page', 'status not set');
        throws(fn() => set_status($db, $id, str_repeat('x', 121), 'claude'), '121 chars');
        set_status($db, $id, null, 'claude');
        ok(card($db, $id)['status'] === null, 'status not cleared');
        ok(count(list_events($db)) === 1, 'status change logged');
    },
    'plan_path_must_be_in_project_folder' => function (PDO $db) {
        $dir = sys_get_temp_dir() . '\kanban_plan_test';
        @mkdir("$dir\\proj", 0777, true);
        file_put_contents("$dir\\proj\\plan.md", '# Plan');
        file_put_contents("$dir\\proj\\plan.txt", 'x');
        file_put_contents("$dir\\outside.md", '# No');
        try {
            $db->prepare("INSERT INTO projects (name, folder, color) VALUES ('Plans', ?, 'teal')")->execute(["$dir\\proj"]);
            $id = add_card($db, 'A', 'Plans', 'user');
            throws(fn() => set_plan($db, $id, "$dir\\outside.md", 'claude'), 'outside folder');
            throws(fn() => set_plan($db, $id, "$dir\\proj\\..\\outside.md", 'claude'), 'dot-dot escape');
            throws(fn() => set_plan($db, $id, "$dir\\proj\\plan.txt", 'claude'), 'not .md');
            throws(fn() => set_plan($db, $id, "$dir\\proj\\missing.md", 'claude'), 'missing file');
            ok(card($db, $id)['plan_path'] === null, 'bad path stored');
            // Case and slash direction do not matter on Windows.
            set_plan($db, $id, strtoupper(str_replace('\\', '/', "$dir\\proj\\plan.md")), 'claude');
            ok(card($db, $id)['plan_path'] !== null, 'good path refused');
        } finally {
            $db->exec('DELETE FROM cards');
            $db->exec("DELETE FROM projects WHERE name = 'Plans'");
            array_map('unlink', ["$dir\\proj\\plan.md", "$dir\\proj\\plan.txt", "$dir\\outside.md"]);
            rmdir("$dir\\proj");
            rmdir($dir);
        }
    },
    'every_write_logs_one_event' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        move_card($db, $id, 'building', 'claude');
        add_note($db, $id, 'done', 'user');
        $got = array_map(fn($e) => "{$e['action']}:{$e['author']}:{$e['card_title']}", list_events($db));
        ok($got === ['note:user:A', 'moved:claude:A', 'added:user:A'], implode(',', $got));
    },
    'card_from_prompt_and_command' => function (PDO $db) {
        ok(card_from_prompt('/feature-planner Plan card #12 (Alpha): x') === 12, 'prompt link');
        ok(card_from_prompt('see CARD #7, thanks') === 7, 'case-insensitive');
        ok(card_from_prompt('cards #7 and #8') === null && card_from_prompt('discard #7') === null, 'not card #N');
        $php = 'C:\xampp\php\php.exe C:\xampp\htdocs\kanban\board.php';
        ok(card_from_command("$php move 21 review") === 21, 'move');
        ok(card_from_command("$php status 4 \"Fixing\"") === 4, 'status');
        ok(card_from_command("'$php' note '9' \"x\"") === 9, 'quoted');
        ok(card_from_command("$php add \"Fix 3 bugs\" Alpha") === null, 'add has no id');
        ok(card_from_command('git log -3') === null, 'not board.php');
    },
    'hook_link_lights_dot_and_stop_clears' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        ok(hook_link($db, 's1', $id), 'link refused');
        ok(card($db, $id)['working_since'] !== null, 'dot not on');
        set_status($db, $id, 'Fixing', 'claude');
        hook_stop($db, 's1');
        $c = card($db, $id);
        ok($c['working_since'] === null && $c['status'] === null, 'dot or status left on');
        ok($db->query('SELECT active FROM sessions')->fetchColumn() === 0, 'session row not kept inactive');
        ok(!hook_link($db, 's2', 9999), 'linked unknown card');
        hook_touch($db, 'nobody');
        hook_stop($db, 'nobody');
        ok(count(list_events($db)) === 1, 'hooks logged events');
    },
    'hook_two_sessions_keep_dot_until_both_stop' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        hook_link($db, 's1', $id);
        hook_link($db, 's2', $id);
        hook_stop($db, 's1');
        ok(card($db, $id)['working_since'] !== null, 'dot off while s2 works');
        hook_stop($db, 's2');
        ok(card($db, $id)['working_since'] === null, 'dot on after both stopped');
    },
    'hook_touch_refreshes_linked_session' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        hook_link($db, 's1', $id);
        $db->exec("UPDATE sessions SET seen_at = '2020-01-01'");
        $db->exec("UPDATE cards SET working_since = '2020-01-01'");
        $before = card($db, $id)['updated_at'];
        hook_touch($db, 's1');
        ok($db->query('SELECT seen_at FROM sessions')->fetchColumn() > '2020-01-02', 'seen_at not refreshed');
        $c = card($db, $id);
        ok($c['working_since'] > '2020-01-02', 'working_since not refreshed');
        ok($c['updated_at'] === $before, 'updated_at changed');
    },
    'hook_prune_drops_stale_sessions_and_orphan_dots' => function (PDO $db) {
        $a = add_card($db, 'A', 'Alpha', 'user');
        $b = add_card($db, 'B', 'Alpha', 'user');
        $c = add_card($db, 'C', 'Alpha', 'user');
        hook_link($db, 'old', $a);
        hook_link($db, 'fresh', $b);
        hook_link($db, 'day_old', $b);
        $db->exec("UPDATE sessions SET seen_at = NOW() - INTERVAL 31 MINUTE WHERE session_id = 'old'");
        $db->exec("UPDATE sessions SET seen_at = NOW() - INTERVAL 25 HOUR WHERE session_id = 'day_old'");
        hook_link($db, 'moved', $c);
        hook_link($db, 'moved', $b); // relinked: C is left with no session
        hook_prune($db);
        ok(card($db, $a)['working_since'] === null, 'stale dot left on');
        ok(card($db, $c)['working_since'] === null, 'orphan dot left on');
        ok(card($db, $b)['working_since'] !== null, 'live dot turned off');
        $left = $db->query('SELECT session_id FROM sessions ORDER BY session_id')->fetchAll(PDO::FETCH_COLUMN);
        ok($left === ['fresh', 'moved', 'old'], implode(',', $left)); // kept a day so a follow-up turn relights
    },
    'follow_up_turn_relights_dot' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        hook_event($db, ['session_id' => 's1', 'hook_event_name' => 'UserPromptSubmit', 'prompt' => "Plan card #$id"]);
        hook_event($db, ['session_id' => 's1', 'hook_event_name' => 'Stop']);
        ok(card($db, $id)['working_since'] === null, 'dot on after Stop');
        hook_event($db, ['session_id' => 's1', 'hook_event_name' => 'UserPromptSubmit', 'prompt' => 'yes go ahead']);
        ok(card($db, $id)['working_since'] !== null, 'follow-up turn did not relight the dot');
    },
    'notify_command_escapes_and_caps' => function (PDO $db) {
        $cmd = notify_command('Card #5 needs you', "Pick \"A\" or B?\r\nNext line " . str_repeat('x', 300));
        ok(str_starts_with($cmd, 'start "" /B powershell -NoProfile -WindowStyle Hidden'), $cmd);
        ok(str_contains($cmd, 'toast.ps1" -Title "Card #5 needs you" -Body "Pick  A  or B? Next line x'), $cmd);
        ok(!str_contains($cmd, "\n") && !str_contains($cmd, 'x' . str_repeat('x', 200)), 'newline or no cap');
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
    $db->exec('DELETE FROM events');
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
