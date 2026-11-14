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

// A temp project "Deploys" whose folder holds $md as DEPLOYMENTS.md; runs $fn($file), then removes both.
function with_deploy_log(PDO $db, string $md, callable $fn): void
{
    $dir = sys_get_temp_dir() . '\kanban_deploy_test';
    @mkdir($dir, 0777, true);
    $file = "$dir\\DEPLOYMENTS.md";
    file_put_contents($file, $md);
    try {
        $db->prepare("INSERT INTO projects (name, folder, color) VALUES ('Deploys', ?, 'teal')")->execute([$dir]);
        $fn($file);
    } finally {
        $db->exec('DELETE FROM cards');
        $db->exec("DELETE FROM projects WHERE name = 'Deploys'");
        unlink($file);
        rmdir($dir);
    }
}

// Rewrites the log with a later mtime so the next sync parses it again.
function rewrite_log(string $file, string $md): void
{
    file_put_contents($file, $md);
    touch($file, filemtime($file) + 5);
    clearstatcache();
}

function entry(string $heading, string $card, string $verified = 'live page shows the fix'): string
{
    return "## $heading\n- By: Claude, requested by the user\n- Code: abc → def\n$card- Verified: $verified\n\n";
}

// Fake desktop-app chat folders for find_chat; the real ones under LOCALAPPDATA are never touched.
define('CHAT_TEST_DIR', sys_get_temp_dir() . '/kanban_chat_test');
define('CLAUDE_SESSIONS_GLOB', CHAT_TEST_DIR . '/*/*');

// Writes $files (name => array, or a raw string for a bad file) into one fake chat folder, runs $fn, then removes them.
function with_chats(array $files, callable $fn): void
{
    $dir = CHAT_TEST_DIR . '/acct/proj';
    @mkdir($dir, 0777, true);
    foreach ($files as $name => $body) {
        file_put_contents("$dir/$name", is_string($body) ? $body : json_encode($body));
    }
    try {
        $fn();
    } finally {
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
        rmdir(dirname($dir));
        rmdir(CHAT_TEST_DIR);
    }
}

function chat(string $id, string $cli, array $more = []): array
{
    return array_merge(['sessionId' => "local_$id", 'cliSessionId' => $cli, 'title' => "Chat $id", 'isArchived' => false], $more);
}

function link_session(PDO $db, string $session, int $card, string $linked): void
{
    $db->prepare('INSERT INTO chat_links (card_id, session_id, linked_at) VALUES (?, ?, ?)')->execute([$card, $session, $linked]);
}

// Runs $fn, then drops every project added beyond the four seeded ones, with their cards and sections.
function with_new_projects(PDO $db, callable $fn): void
{
    try {
        $fn();
    } finally {
        $db->exec('DELETE FROM cards');
        $db->exec('DELETE FROM sections WHERE project_id > 4');
        $db->exec('DELETE FROM projects WHERE id > 4');
    }
}

function project_row(PDO $db, string $name): array
{
    foreach (list_projects($db) as $p) {
        if ($p['name'] === $name) {
            return $p;
        }
    }
    throw new Exception("no project $name");
}

// The InvalidArgumentException message $fn throws.
function error_of(callable $fn): string
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    throw new Exception('expected InvalidArgumentException');
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
        $p = fn($col, $title = 'T', $plan = null) => prompt_of(claude_link(['col' => $col, 'title' => $title, 'plan_path' => $plan] + $card, 'f'));
        $plan = 'C:\p\plan.md';
        ok($p('planning') === 'Plan card #3 (Beta): T. Use the feature-planner skill.', $p('planning'));
        ok($p('building') === "Build card #3 (Beta): T. There is no plan file yet, so read the card's notes first.", $p('building'));
        ok($p('building', 'T', $plan) === 'Build card #3 (Beta): T. Follow the plan in C:\p\plan.md.', $p('building', 'T', $plan));
        ok($p('review') === "Review card #3 (Beta): T. Check the changes against the card's notes.", $p('review'));
        ok($p('review', 'T', $plan) === 'Review card #3 (Beta): T. Check the changes against the plan in C:\p\plan.md.', $p('review', 'T', $plan));
        ok($p('live') === "Deploy card #3 (Beta): T. Follow the project's deploy rules and log it in DEPLOYMENTS.md with Card: #3.", $p('live'));
        ok($p('planning', 'Fix it.') === 'Plan card #3 (Beta): Fix it. Use the feature-planner skill.', 'double full stop');
        foreach (['planning', 'building', 'review', 'live'] as $col) {
            ok(!str_starts_with($p($col), '/'), "$col starts with a slash: the app turns it into a full-width one");
            ok(card_from_prompt($p($col)) === 3, "$col prompt doesn't link the chat");
        }
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
    'events_after_returns_only_newer_oldest_first' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        move_card($db, $id, 'building', 'claude');
        add_note($db, $id, 'done', 'user');
        $first = (int) min(array_column(list_events($db), 'id'));
        $got = list_events($db, 50, $first);
        $ids = array_map('intval', array_column($got, 'id'));
        ok(count($got) === 2, 'got ' . count($got) . ' events');
        ok($ids[0] > $first && $ids[0] < $ids[1], 'ids ' . implode(',', $ids));
        ok(array_column($got, 'action') === ['moved', 'note'], implode(',', array_column($got, 'action')));
    },
    'same_place_move_logs_nothing' => function (PDO $db) {
        $a = add_card($db, 'A', 'Alpha', 'user');
        $b = add_card($db, 'B', 'Alpha', 'user');
        $db->exec("UPDATE cards SET updated_at = '2020-01-01'");
        move_card($db, $a, 'todo', 'claude', 0);
        move_card($db, $b, 'todo', 'claude'); // already last
        move_card($db, $b, 'todo', 'claude', 9); // capped to last
        ok(count(list_events($db)) === 2, 'no-op move logged');
        ok(card($db, $a)['updated_by'] === 'user' && card($db, $b)['updated_at'] === '2020-01-01 00:00:00', 'no-op move wrote');
        move_card($db, $a, 'todo', 'claude', 1);
        ok(col_order($db) === ['0:B', '1:A'] && count(list_events($db)) === 3, 'real reorder not done or not logged');
    },
    'card_from_prompt_and_command' => function (PDO $db) {
        // Only the prompts the board builds link, at the start (leading whitespace allowed).
        ok(card_from_prompt('/feature-planner Plan card #12 (Alpha): x') === 12, 'plan prompt');
        ok(card_from_prompt('／feature-planner Plan card #55 (Portfolio): x') === 55, 'old plan prompt the app rewrote');
        ok(card_from_prompt('Plan card #12 (Alpha): x') === 12 && card_from_prompt('Build card #13 (Alpha): x') === 13
            && card_from_prompt('Review card #14 (Alpha): x') === 14, 'new prompts');
        ok(card_from_prompt('Build the plan for card #13 (Alpha): x') === 13, 'build prompt');
        ok(card_from_prompt('Review the code for card #14 (Alpha): x') === 14, 'review prompt');
        ok(card_from_prompt('Deploy card #15 (Alpha): x') === 15, 'deploy prompt');
        ok(card_from_prompt("\n  Update the plan for card #16 (Alpha): x") === 16, 'update plan prompt');
        ok(card_from_prompt('Deploy card #1500') === 1500 && card_from_prompt('Deploy card #15x') === null, 'id boundary');
        // A prompt that only mentions a card (a message to a subagent, say) must not link this chat.
        ok(card_from_prompt('Look at card #21 please') === null, 'mention links');
        ok(card_from_prompt('Then: Review the code for card #21 (Alpha)') === null, 'mid-sentence verb links');
        ok(card_from_prompt('cards #7 and #8') === null && card_from_prompt('discard #7') === null, 'not card #N');
        $php = 'C:\xampp\php\php.exe C:\xampp\htdocs\kanban\board.php';
        ok(card_from_command("$php status 4 \"Fixing\"") === 4, 'status');
        ok(card_from_command("'$php' status '9' \"x\"") === 9, 'quoted');
        // Only status links a chat: tagging or moving other cards must not (a section run linked this chat to #33).
        ok(card_from_command("$php move 21 review") === null, 'move links');
        ok(card_from_command("$php note 9 x") === null, 'note links');
        ok(card_from_command("$php section 5 Loans") === null, 'section links');
        ok(card_from_command("$php add \"Fix 3 bugs\" Alpha") === null, 'add has no id');
        ok(card_from_command('git log -3') === null, 'not board.php');
    },
    'find_chat_matches_newest_unarchived' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        $other = add_card($db, 'B', 'Alpha', 'user');
        ok(get_card($db, $id)['has_chat'] === 0, 'has_chat without sessions');
        link_session($db, 'cli-old', $id, '2026-01-01 10:00:00');
        link_session($db, 'cli-new', $id, '2026-01-01 11:00:00');
        link_session($db, 'cli-b', $other, '2026-01-01 12:00:00');
        ok(get_card($db, $id)['has_chat'] === 1, 'has_chat with sessions');
        with_chats([
            'local_old.json' => chat('old', 'cli-old'),
            'local_new.json' => chat('new', 'cli-new'),
            'local_b.json' => chat('b', 'cli-b'),
        ], function () use ($db, $id) {
            ok(find_chat($db, $id) === ['id' => 'local_new', 'title' => 'Chat new'], json_encode(find_chat($db, $id)));
        });
        with_chats([
            'local_old.json' => chat('old', 'cli-old'),
            'local_new.json' => chat('new', 'cli-new', ['isArchived' => true]),
        ], function () use ($db, $id) {
            ok(find_chat($db, $id)['id'] === 'local_old', 'archived chat used: ' . json_encode(find_chat($db, $id)));
        });
    },
    'find_chat_prior_ids_bad_files_and_no_match' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        ok(find_chat($db, $id) === null, 'chat for a card with no sessions');
        link_session($db, 'cli-1', $id, '2026-01-01 10:00:00');
        with_chats([
            'local_bad.json' => '{"cliSessionId":"cli-1",', // holds the id, so it reaches json_decode
            'local_evil.json' => chat('x"y', 'cli-1'), // id the app would refuse
            'local_two.json' => chat('two', 'cli-2', ['priorCliSessionIds' => ['cli-0', 'cli-1']]),
        ], function () use ($db, $id) {
            ok(find_chat($db, $id) === ['id' => 'local_two', 'title' => 'Chat two'], json_encode(find_chat($db, $id)));
        });
        with_chats(['local_z.json' => chat('z', 'cli-9')], function () use ($db, $id) {
            ok(find_chat($db, $id) === null, 'matched an unrelated chat');
        });
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
        // The pruned session's chat link stays for Open chat; a link 181 days old goes.
        $links = fn() => $db->query("SELECT CONCAT(session_id, '>', card_id) FROM chat_links ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
        ok(in_array("day_old>$b", $links(), true), 'chat link pruned with its session: ' . implode(',', $links()));
        $db->exec("UPDATE chat_links SET linked_at = NOW() - INTERVAL 181 DAY WHERE session_id = 'day_old'");
        $db->exec("UPDATE chat_links SET linked_at = NOW() - INTERVAL 179 DAY WHERE session_id = 'old'");
        hook_prune($db);
        ok($links() === ["fresh>$b", "moved>$b", "moved>$c", "old>$a"], implode(',', $links()));
    },
    'chat_keeps_earlier_card_after_moving_on' => function (PDO $db) {
        $a = add_card($db, 'A', 'Alpha', 'user');
        $b = add_card($db, 'B', 'Alpha', 'user');
        hook_event($db, ['session_id' => 'cli-1', 'hook_event_name' => 'UserPromptSubmit', 'prompt' => "Build the plan for card #$a (Alpha): A"]);
        $php = 'C:\xampp\php\php.exe C:\xampp\htdocs\kanban\board.php';
        hook_event($db, ['session_id' => 'cli-1', 'hook_event_name' => 'PostToolUse', 'tool_input' => ['command' => "$php status $b \"x\""]]);
        ok(get_card($db, $a)['has_chat'] === 1 && get_card($db, $b)['has_chat'] === 1, 'has_chat lost on the earlier card');
        with_chats(['local_one.json' => chat('one', 'cli-1')], function () use ($db, $a, $b) {
            ok(find_chat($db, $a)['id'] === 'local_one', 'earlier card: ' . json_encode(find_chat($db, $a)));
            ok(find_chat($db, $b)['id'] === 'local_one', 'later card: ' . json_encode(find_chat($db, $b)));
        });
    },
    'find_chat_prefers_last_linked_over_last_seen' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        link_session($db, 'cli-linker', $id, '2026-01-01 11:00:00');
        link_session($db, 'cli-toucher', $id, '2026-01-01 10:00:00');
        // The toucher ran on this card later (seen_at), but the linker linked it last.
        $db->exec("INSERT INTO sessions (session_id, card_id, seen_at, active) VALUES ('cli-toucher', $id, '2026-01-01 12:00:00', 0)");
        with_chats(['local_l.json' => chat('l', 'cli-linker'), 'local_t.json' => chat('t', 'cli-toucher')], function () use ($db, $id) {
            ok(find_chat($db, $id)['id'] === 'local_l', json_encode(find_chat($db, $id)));
        });
    },
    'follow_up_turn_relights_dot' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        hook_event($db, ['session_id' => 's1', 'hook_event_name' => 'UserPromptSubmit', 'prompt' => "/feature-planner Plan card #$id (Alpha): A"]);
        hook_event($db, ['session_id' => 's1', 'hook_event_name' => 'Stop']);
        ok(card($db, $id)['working_since'] === null, 'dot on after Stop');
        hook_event($db, ['session_id' => 's1', 'hook_event_name' => 'UserPromptSubmit', 'prompt' => 'yes go ahead']);
        ok(card($db, $id)['working_since'] !== null, 'follow-up turn did not relight the dot');
    },
    'stop_failure_ends_turn_like_stop' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        hook_event($db, ['session_id' => 's1', 'hook_event_name' => 'UserPromptSubmit', 'prompt' => "Deploy card #$id (Alpha): A"]);
        set_status($db, $id, 'Fixing', 'claude');
        hook_event($db, ['session_id' => 's1', 'hook_event_name' => 'StopFailure']);
        $c = card($db, $id);
        ok($c['working_since'] === null && $c['status'] === null, 'dot or status left on');
    },
    'notify_command_escapes_and_caps' => function (PDO $db) {
        [$cmd, $env] = notify_command('Card #5 needs you', "-Pick \"A\" or é?\r\nNext line " . str_repeat('x', 300));
        ok(str_starts_with($cmd, 'start "" /B powershell -NoProfile -WindowStyle Hidden'), $cmd);
        ok(str_ends_with($cmd, 'toast.ps1" >NUL 2>&1') && !str_contains($cmd, 'Pick'), "text on the command line: $cmd");
        ok($env['KANBAN_TOAST_TITLE'] === 'Card #5 needs you', $env['KANBAN_TOAST_TITLE']);
        $b = $env['KANBAN_TOAST_BODY'];
        ok(str_starts_with($b, '-Pick "A" or é? Next line x') && mb_strlen($b) === 200, "body not raw, joined and capped: $b");
    },
    'request_add_validates' => function (PDO $db) {
        throws(fn() => add_request($db, '  ', 'Al', 'Alpha', 'do', 'user'), 'empty body');
        throws(fn() => add_request($db, 'Fix it', 'Al', 'Nope', 'do', 'user'), 'unknown project');
        throws(fn() => add_request($db, 'Fix it', 'Al', 'Alpha', 'x', 'user'), "kind 'x'");
        throws(fn() => add_request($db, 'Fix it', ' ', 'Alpha', 'do', 'user'), 'empty requester');
        ok($db->query('SELECT COUNT(*) FROM requests')->fetchColumn() == 0, 'bad request written');
        $id = add_request($db, '<b>Fix</b> it', 'Al', 'alpha', 'change', 'user');
        ok($id > 0, 'no id');
        $e = list_events($db);
        ok(count($e) === 1 && $e[0]['action'] === 'request' && $e[0]['card_id'] === null
            && $e[0]['card_title'] === '<b>Fix</b> it', json_encode($e));
    },
    'card_from_request_links_and_progress' => function (PDO $db) {
        $rid = add_request($db, 'New page', 'Al', 'Beta', 'do', 'user');
        $state = fn() => array_values(array_filter(list_requests($db), fn($r) => $r['id'] == $rid))[0];
        ok($state()['state'] === 'new' && $state()['cards_total'] === 0, json_encode($state()));
        $a = card_from_request($db, $rid, 'Part A', 'user');
        $b = card_from_request($db, $rid, 'Part B', 'user');
        $c = get_card($db, $a);
        ok($c['col'] === 'todo' && $c['project'] === 'Beta' && $c['request_id'] == $rid
            && $c['request_kind'] === 'do' && $c['request_requester'] === 'Al', json_encode($c));
        move_card($db, $a, 'live', 'user');
        $r = $state();
        ok($r['cards_total'] === 2 && $r['cards_live'] === 1 && $r['state'] === 'in_progress', json_encode($r));
        move_card($db, $b, 'live', 'claude');
        ok($state()['state'] === 'done', json_encode($state()));
        throws(fn() => card_from_request($db, 9999, 'X', 'user'), 'unknown request');
    },
    'delete_request_refuses_with_cards' => function (PDO $db) {
        $rid = add_request($db, 'Remove banner', 'Al', 'Alpha', 'remove', 'user');
        $cid = card_from_request($db, $rid, 'Remove banner', 'user');
        throws(fn() => delete_request($db, $rid, 'user'), 'request with cards');
        delete_card($db, $cid, 'user');
        delete_request($db, $rid, 'user');
        ok($db->query('SELECT COUNT(*) FROM requests')->fetchColumn() == 0, 'request left');
        ok(list_events($db)[0]['action'] === 'request_deleted', list_events($db)[0]['action']);
        throws(fn() => delete_request($db, $rid, 'user'), 'deleted twice');
    },
    'requesters_distinct_sorted' => function (PDO $db) {
        foreach (['Bea', 'Al', 'Bea', 'Cy'] as $who) {
            add_request($db, 'x', $who, 'Alpha', 'do', 'user');
        }
        ok(requesters($db) === ['Al', 'Bea', 'Cy'], implode(',', requesters($db)));
    },
    'move_to_live_sets_live_at_today' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        ok(card($db, $id)['live_at'] === null, 'live_at set on add');
        move_card($db, $id, 'live', 'claude');
        $today = $db->query('SELECT CURDATE()')->fetchColumn();
        ok(card($db, $id)['live_at'] === $today, card($db, $id)['live_at'] . " != $today");
        $db->exec("UPDATE cards SET live_at = '2020-01-01'");
        move_card($db, $id, 'test', 'user');
        ok(card($db, $id)['live_at'] === '2020-01-01', 'live_at changed on moving out');
    },
    'sync_moves_card_named_in_deploy_entry' => function (PDO $db) {
        with_deploy_log($db, '', function ($file) use ($db) {
            $id = add_card($db, 'Fix totals', 'Deploys', 'user');
            set_blocked($db, $id, 'Waiting', 'user');
            $other = add_card($db, 'Other', 'Deploys', 'user');
            rewrite_log($file, "# Deployments\n\n" . entry('2026-11-12 16:15 — abc123 — Fix totals', "- Card: #$id, #$other\n"));
            $db->exec('DELETE FROM events');
            ok(sync_deployments($db) === 2, 'not 2 moved');
            $c = card($db, $id);
            ok($c['col'] === 'live' && $c['live_at'] === '2026-11-12' && $c['blocked'] == 0 && $c['updated_by'] === 'claude', json_encode($c));
            ok(col_order($db, 'live') === ['0:Fix totals', '1:Other'], implode(',', col_order($db, 'live')));
            $e = array_values(array_filter(list_events($db), fn($e) => $e['card_id'] == $id));
            ok(count($e) === 1 && $e[0]['action'] === 'live' && $e[0]['author'] === 'claude'
                && $e[0]['detail'] === '2026-11-12 Fix totals', json_encode($e));
        });
    },
    'sync_ignores_unchanged_file_and_unknown_cards' => function (PDO $db) {
        with_deploy_log($db, '', function ($file) use ($db) {
            $id = add_card($db, 'A', 'Deploys', 'user');
            rewrite_log($file, entry('2026-11-12 16:15 — data only — Fix', "- Card: #99999\n"));
            ok(sync_deployments($db) === 0, 'unknown card moved something');
            $mtime = filemtime($file);
            $same = entry('2026-11-12 16:15 — data only — Fix', '- Card: #' . str_pad($id, 5, '0', STR_PAD_LEFT) . "\n");
            file_put_contents($file, $same);
            touch($file, $mtime); // same mtime and size: sync must not read it again
            clearstatcache();
            ok(sync_deployments($db) === 0, 'unchanged file parsed again');
            ok(card($db, $id)['col'] === 'todo', 'card moved from an unchanged file');
            file_put_contents($file, "$same\n");
            touch($file, $mtime); // rewritten within the same second: the size differs
            clearstatcache();
            ok(sync_deployments($db) === 1 && card($db, $id)['col'] === 'live', 'same-second rewrite not parsed');
        });
    },
    'sync_parses_heading_card_mention' => function (PDO $db) {
        with_deploy_log($db, '', function ($file) use ($db) {
            $id = add_card($db, 'Fix totals', 'Deploys', 'user');
            rewrite_log($file, str_replace("\n", "\r\n", entry("2026-11-12 16:15 — abc123 — Fix totals (CARD #$id)", '')));
            ok(sync_deployments($db) === 1, 'heading mention not moved');
            ok(card($db, $id)['col'] === 'live', 'not live');
            ok(list_events($db)[0]['detail'] === '2026-11-12 Fix totals', list_events($db)[0]['detail']);
        });
    },
    'sync_ignores_unverified_entry' => function (PDO $db) {
        with_deploy_log($db, '', function ($file) use ($db) {
            $a = add_card($db, 'A', 'Deploys', 'user');
            $b = add_card($db, 'B', 'Deploys', 'user');
            rewrite_log($file, entry('2026-11-12 16:15 — abc — A', "- Card: #$a\n", 'none')
                . "## 2026-11-13 09:00 — def — B\n- Card: #$b\n\n");
            ok(sync_deployments($db) === 0, 'unverified entry moved a card');
            ok(card($db, $a)['col'] === 'todo' && card($db, $b)['col'] === 'todo', 'moved');
        });
    },
    'sync_skips_card_of_another_project' => function (PDO $db) {
        with_deploy_log($db, '', function ($file) use ($db) {
            $id = add_card($db, 'A', 'Alpha', 'user');
            rewrite_log($file, entry('2026-11-12 16:15 — abc — A', "- Card: #$id\n"));
            ok(sync_deployments($db) === 0 && card($db, $id)['col'] === 'todo', 'card of another project moved');
        });
    },
    'sync_card_moved_out_of_live_stays_out' => function (PDO $db) {
        with_deploy_log($db, '', function ($file) use ($db) {
            $id = add_card($db, 'A', 'Deploys', 'user');
            $md = entry('2026-11-12 16:15 — abc — A', "- Card: #$id\n");
            rewrite_log($file, $md);
            ok(sync_deployments($db) === 1, 'not moved');
            move_card($db, $id, 'test', 'user');
            rewrite_log($file, entry('2026-11-13 10:00 — def — Other', "- Card: none\n") . $md);
            ok(sync_deployments($db) === 0 && card($db, $id)['col'] === 'test', 'moved back into Live');
        });
    },
    'sync_accepts_verified_sub_bullets' => function (PDO $db) {
        with_deploy_log($db, '', function ($file) use ($db) {
            $a = add_card($db, 'A', 'Deploys', 'user');
            $b = add_card($db, 'B', 'Deploys', 'user');
            rewrite_log($file, "## 2026-11-12 16:15 — abc — A\n- Card: #$a\n- Verified:\n  - live page shows the fix\n  - totals add up\n\n"
                . "## 2026-11-13 09:00 — def — B\n- Card: #$b\n- Verified:\n- Backup: none\n");
            ok(sync_deployments($db) === 1, 'not 1 moved');
            ok(card($db, $a)['col'] === 'live' && card($db, $b)['col'] === 'todo', 'wrong cards moved');
        });
    },
    'sync_rejects_not_verified_wording' => function (PDO $db) {
        with_deploy_log($db, '', function ($file) use ($db) {
            $words = ["not verified (skipped at user's request)", '<what was checked on the live site>', 'Pending',
                'skipped', 'no check yet', "\n  - none"];
            $ids = [];
            $md = '';
            foreach ($words as $i => $v) {
                $ids[] = $id = add_card($db, "C$i", 'Deploys', 'user');
                $md .= entry(sprintf('2026-11-%02d 10:00 — abc — C%d', $i + 1, $i), "- Card: #$id\n", $v);
            }
            $good = add_card($db, 'OK', 'Deploys', 'user');
            rewrite_log($file, $md . entry('2026-11-20 10:00 — abc — OK', "- Card: #$good\n", 'live page checked'));
            ok(sync_deployments($db) === 1 && card($db, $good)['col'] === 'live', 'verified entry not moved');
            foreach ($ids as $i => $id) {
                ok(card($db, $id)['col'] === 'todo', "moved with Verified: $words[$i]");
            }
        });
    },
    'sync_unknown_card_never_applies_later' => function (PDO $db) {
        with_deploy_log($db, '', function ($file) use ($db) {
            $other = add_card($db, 'B', 'Alpha', 'user');
            $next = $other + 1;
            $md = entry('2026-11-12 16:15 — abc — Fix', "- Card: #$next, #$other\n");
            rewrite_log($file, $md);
            ok(sync_deployments($db) === 0, 'moved something');
            $id = add_card($db, 'Typo target', 'Deploys', 'user');
            ok($id === $next, "new card is #$id, not #$next");
            edit_card($db, $other, 'B', 'Deploys', 'user');
            rewrite_log($file, $md);
            ok(sync_deployments($db) === 0 && card($db, $id)['col'] === 'todo' && card($db, $other)['col'] === 'todo',
                'an earlier entry moved a later card');
        });
    },
    'sync_parser_variants' => function (PDO $db) {
        with_deploy_log($db, '', function ($file) use ($db) {
            $a = add_card($db, 'A', 'Deploys', 'user');
            $b = add_card($db, 'B', 'Deploys', 'user');
            $c = add_card($db, 'C', 'Deploys', 'user');
            rewrite_log($file, "\xEF\xBB\xBF## 2026-11-12 16:15 - abc123 - Fix totals\n- Cards: #$a #$b\n- Verified: totals ok\n\n"
                . "##  2026-11-13 09:00 — def — Second\n- Card: #$c\n- Verified: ok\n");
            ok(sync_deployments($db) === 3, 'not 3 moved');
            $live = array_column(array_filter(list_events($db), fn($e) => $e['action'] === 'live'), 'detail');
            ok(in_array('2026-11-12 Fix totals', $live) && in_array('2026-11-13 Second', $live), json_encode($live));
            move_card($db, $a, 'test', 'user');
            rewrite_log($file, "## 2026-11-12 16:15 - abc123 - Fix totals, retitled\n- Cards: #$a #$b\n- Verified: totals ok\n");
            ok(sync_deployments($db) === 0 && card($db, $a)['col'] === 'test', 'retitled entry applied again');
        });
    },
    'stuck_days' => function (PDO $db) {
        // Puts the card's updated_at, notes and events $days back from the DB clock.
        $age = function (int $id, int $days) use ($db) {
            $db->prepare('UPDATE cards SET updated_at = NOW() - INTERVAL ? DAY WHERE id = ?')->execute([$days, $id]);
            $db->prepare('UPDATE notes SET created_at = NOW() - INTERVAL ? DAY WHERE card_id = ?')->execute([$days, $id]);
            $db->prepare('UPDATE events SET created_at = NOW() - INTERVAL ? DAY WHERE card_id = ?')->execute([$days, $id]);
        };
        $stuck = function (int $id) use ($db) {
            foreach (list_cards($db) as $r) {
                if ((int) $r['id'] === $id) {
                    ok(isset($r['last_activity']), "#$id has no last_activity");
                    return $r['stuck_days'] ?? null;
                }
            }
            throw new Exception("#$id not listed");
        };
        $b = add_card($db, 'B', 'Alpha', 'user');
        move_card($db, $b, 'building', 'user');
        add_note($db, $b, 'old', 'user');
        $age($b, 4);
        ok($stuck($b) === 4, 'aged building card: ' . var_export($stuck($b), true));
        $db->prepare("INSERT INTO notes (card_id, author, body, created_at) VALUES (?, 'user', 'new', NOW() - INTERVAL 1 DAY)")->execute([$b]);
        ok($stuck($b) === 0, 'recent note: ' . var_export($stuck($b), true));

        $bare = add_card($db, 'Bare', 'Alpha', 'user');
        move_card($db, $bare, 'review', 'user');
        $age($bare, 5);
        $db->prepare('DELETE FROM events WHERE card_id = ?')->execute([$bare]);
        ok($stuck($bare) === 5, 'card with no notes or events: ' . var_export($stuck($bare), true));

        $t = add_card($db, 'T', 'Alpha', 'user');
        $age($t, 10);
        ok($stuck($t) === 0, 'todo card: ' . var_export($stuck($t), true));

        $l = add_card($db, 'L', 'Alpha', 'user');
        move_card($db, $l, 'live', 'user');
        $age($l, 10);
        ok($stuck($l) === 0, 'live card: ' . var_export($stuck($l), true));

        $w = add_card($db, 'W', 'Alpha', 'user');
        move_card($db, $w, 'building', 'user');
        $age($w, 4);
        $db->prepare('UPDATE cards SET working_since = NOW(), updated_at = updated_at WHERE id = ?')->execute([$w]);
        ok($stuck($w) === 0, 'working card: ' . var_export($stuck($w), true));

        $ev = add_card($db, 'Ev', 'Alpha', 'user');
        move_card($db, $ev, 'building', 'user');
        $age($ev, 4);
        $db->prepare("INSERT INTO events (card_id, card_title, author, action, created_at) VALUES (?, 'Ev', 'user', 'moved', NOW() - INTERVAL 1 DAY)")->execute([$ev]);
        ok($stuck($ev) === 0, 'event yesterday: ' . var_export($stuck($ev), true));

        $two = add_card($db, 'Two', 'Alpha', 'user');
        move_card($db, $two, 'building', 'user');
        $age($two, 2);
        ok($stuck($two) === 0, '2 days idle: ' . var_export($stuck($two), true));

        foreach (['planning', 'test'] as $col) {
            $x = add_card($db, $col, 'Alpha', 'user');
            move_card($db, $x, $col, 'user');
            $age($x, 4);
            ok($stuck($x) === 4, "$col card: " . var_export($stuck($x), true));
        }
    },
    'sections_set_and_clear' => function (PDO $db) {
        ok(array_column(list_sections($db, 'alpha'), 'name') === ['Members', 'Loans', 'Whole System'],
            json_encode(list_sections($db, 'alpha')));
        ok(list_sections($db, 'Gamma') === [], 'Gamma has sections');
        $id = add_card($db, 'A', 'Alpha', 'user');
        ok(get_card($db, $id)['section'] === null, 'section set on add');
        set_section($db, $id, 'Loans', 'claude');
        ok(get_card($db, $id)['section'] === 'Loans', 'Loans not set');
        set_section($db, $id, null, 'claude');
        set_section($db, $id, 'loans', 'claude');
        ok(get_card($db, $id)['section'] === 'Loans', 'lower-case loans not matched');
        $msg = '';
        try {
            set_section($db, $id, 'Nope', 'claude');
        } catch (InvalidArgumentException $e) {
            $msg = $e->getMessage();
        }
        ok(str_contains($msg, 'Sections:') && str_contains($msg, 'Loans'), "message: $msg");
        throws(fn() => set_section($db, $id, 'Payroll', 'claude'), "Beta's section on an Alpha card");
        ok(get_card($db, $id)['section'] === 'Loans', 'bad name changed the section');
        set_section($db, $id, null, 'user');
        ok(get_card($db, $id)['section'] === null, 'not cleared');
        ok(list_events($db)[0]['action'] === 'section' && list_events($db)[0]['detail'] === 'none', json_encode(list_events($db)[0]));
        $b = add_card($db, 'B', 'Alpha', 'claude', null, 'whole system');
        ok(get_card($db, $b)['section'] === 'Whole System', 'section not set by add');
        throws(fn() => add_card($db, 'C', 'Gamma', 'claude', null, 'Loans'), 'section on a project with none');
        ok(count(list_cards($db)) === 2, 'card written with a bad section');
    },
    'edit_card_project_change_clears_section' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user', null, 'Loans');
        edit_card($db, $id, 'A2', 'Alpha', 'user');
        ok(get_card($db, $id)['section'] === 'Loans', 'title edit cleared the section');
        edit_card($db, $id, 'A2', 'Beta', 'user');
        $c = get_card($db, $id);
        ok($c['project'] === 'Beta' && $c['section'] === null && $c['section_id'] === null, json_encode($c));
    },
    'edit_card_refuses_stale_edit' => function (PDO $db) {
        $id = add_card($db, 'A', 'Alpha', 'user');
        edit_card($db, $id, 'A2', 'Alpha', 'claude');
        $refused = false;
        try {
            edit_card($db, $id, 'X', 'Alpha', 'user', 'A', 'Alpha');
        } catch (CardConflict) {
            $refused = true;
        }
        ok($refused, 'stale edit was not refused');
        ok(get_card($db, $id)['title'] === 'A2', 'stale edit overwrote the title');
        move_card($db, $id, 'building', 'claude');
        edit_card($db, $id, 'X', 'Alpha', 'user', 'A2', 'Alpha');
        ok(get_card($db, $id)['title'] === 'X', 'a move made a current edit conflict');
        add_note($db, $id, 'claude note', 'claude');
        edit_card($db, $id, 'Y', 'Alpha', 'user', 'X', 'Alpha');
        ok(get_card($db, $id)['title'] === 'Y', 'a note made a current edit conflict');
        edit_card($db, $id, 'Y', 'Beta', 'claude');
        $refused = false;
        try {
            edit_card($db, $id, 'Z', 'Alpha', 'user', 'Y', 'Alpha');
        } catch (CardConflict) {
            $refused = true;
        }
        ok($refused, 'a project change by Claude was not caught');
        ok(get_card($db, $id)['project'] === 'Beta', 'stale edit moved the project back');
    },
    'report_cards_grouping' => function (PDO $db) {
        $pid = project_id($db, 'Gamma');
        $db->prepare("INSERT INTO sections (project_id, name, position) VALUES (?, 'Sec2', 2), (?, 'Sec1', 1)")->execute([$pid, $pid]);
        $db->prepare("UPDATE projects SET site_name = 'Gamma Site' WHERE id = ?")->execute([$pid]);
        try {
            $card = function (string $title, string $col, ?string $section, ?string $liveAt = null) use ($db) {
                $id = add_card($db, $title, 'Gamma', 'user', null, $section);
                move_card($db, $id, $col, 'user');
                if ($liveAt !== null) {
                    $db->prepare('UPDATE cards SET live_at = ? WHERE id = ?')->execute([$liveAt, $id]);
                }
                return $id;
            };
            $done = $card('Done in range', 'live', 'Sec2', '2026-11-14');
            add_note($db, $done, 'first', 'user');
            add_note($db, $done, 'second', 'claude');
            $card('Done before', 'live', 'Sec2', '2026-10-31');
            $card('Building', 'building', 'Sec1');
            $card('Todo', 'todo', 'Sec1');
            $card('Planning', 'planning', 'Sec1');
            $card('Review', 'review', null);
            add_card($db, 'Other project', 'Alpha', 'user');
            $r = report_cards($db, 'gamma', '2026-11-01', '2026-11-14');
            ok($r['site_name'] === 'Gamma Site' && $r['project'] === 'Gamma' && $r['from'] === '2026-11-01' && $r['to'] === '2026-11-14',
                json_encode($r));
            $s = $r['sections'];
            ok(array_column($s, 'name') === ['Sec1', 'Sec2', 'Other changes'], json_encode(array_column($s, 'name')));
            ok(count($s[0]['underway']) === 1 && count($s[0]['done']) === 0 && $s[0]['underway'][0]['title'] === 'Building', json_encode($s[0]));
            ok(count($s[1]['done']) === 1 && count($s[1]['underway']) === 0, json_encode($s[1]));
            $d = $s[1]['done'][0];
            ok($d === ['id' => $done, 'title' => 'Done in range', 'col' => 'live', 'live_at' => '2026-11-14', 'notes' => ['first', 'second']],
                json_encode($d));
            ok(array_column($s[2]['underway'], 'title') === ['Review'] && $s[2]['done'] === [], json_encode($s[2]));
            ok(array_column($s, 'other') === [false, false, true], json_encode(array_column($s, 'other')));
            // Underway cards count whatever the dates; an old range drops only the Done ones.
            ok(array_column(report_cards($db, 'Gamma', '2026-01-01', '2026-01-02')['sections'], 'name') === ['Sec1', 'Other changes'],
                'old range kept Done cards');
        } finally {
            $db->exec('DELETE FROM cards');
            $db->prepare('DELETE FROM sections WHERE project_id = ?')->execute([$pid]);
            $db->prepare('UPDATE projects SET site_name = NULL WHERE id = ?')->execute([$pid]);
        }
    },
    'report_cards_bad_dates' => function (PDO $db) {
        throws(fn() => report_cards($db, 'Alpha', '2026-11-10', '2026-11-01'), 'from after to');
        throws(fn() => report_cards($db, 'Alpha', 'abc', '2026-11-01'), 'from abc');
        throws(fn() => report_cards($db, 'Alpha', '2026-11-01', '2026-02-30'), 'no such day');
        throws(fn() => report_cards($db, 'Nope', '2026-11-01', '2026-11-02'), 'unknown project');
        ok(report_cards($db, 'Alpha', '2026-11-01', '2026-11-01')['sections'] === [], 'one-day range failed');
    },
    'add_project_with_sections_lists_them_in_order' => function (PDO $db) {
        $dir = dirname(__DIR__);
        with_new_projects($db, function () use ($db, $dir) {
            // Forward slashes and a trailing slash come back as the real path.
            $id = add_project($db, ' Test Site ', str_replace('\\', '/', $dir) . '/', 'green', ' Test Online ',
                [['name' => 'Shop'], ['name' => 'Admin']], 'user');
            $p = project_row($db, 'Test Site');
            ok($p['id'] === $id && $p['folder'] === realpath($dir) && $p['color'] === 'green' && $p['site_name'] === 'Test Online'
                && $p['card_count'] === 0, json_encode($p));
            ok(array_column($p['sections'], 'name') === ['Shop', 'Admin'] && array_column($p['sections'], 'position') === [1, 2],
                json_encode($p['sections']));
            ok(array_column(list_sections($db, 'test site'), 'name') === ['Shop', 'Admin'], 'list_sections disagrees');
            $e = list_events($db);
            ok(count($e) === 2 && $e[1]['action'] === 'project' && $e[1]['card_id'] === null && $e[1]['detail'] === 'added Test Site',
                json_encode($e));
            // No sections and no report name are allowed.
            add_project($db, 'Bare', $dir, 'slate', '', [], 'claude');
            $b = project_row($db, 'Bare');
            ok($b['sections'] === [] && $b['site_name'] === null, json_encode($b));
            // Explorer's "Copy as path" wraps the folder in double quotes.
            add_project($db, 'Quoted', "\"$dir\"", 'slate', null, [], 'user');
            ok(project_row($db, 'Quoted')['folder'] === realpath($dir), project_row($db, 'Quoted')['folder']);
        });
    },
    'add_project_validates' => function (PDO $db) {
        $dir = dirname(__DIR__);
        with_new_projects($db, function () use ($db, $dir) {
            throws(fn() => add_project($db, 'ALPHA', $dir, 'blue', null, [], 'user'), 'duplicate name, other case');
            throws(fn() => add_project($db, ' ', $dir, 'blue', null, [], 'user'), 'blank name');
            throws(fn() => add_project($db, str_repeat('x', 51), $dir, 'blue', null, [], 'user'), '51-char name');
            throws(fn() => add_project($db, 'New', 'C:\nope', 'blue', null, [], 'user'), 'missing folder');
            throws(fn() => add_project($db, 'New', 'tests', 'blue', null, [], 'user'), 'relative folder');
            throws(fn() => add_project($db, 'New', $dir, 'amber', null, [], 'user'), 'bad colour');
            throws(fn() => add_project($db, 'New', $dir, 'blue', null, [['name' => 'A'], ['name' => 'a']], 'user'), 'duplicate section');
            throws(fn() => add_project($db, 'New', $dir, 'blue', null, [['name' => str_repeat('x', 61)]], 'user'), '61-char section');
            // The database's collation judges duplicates: accents differ, names still clash.
            ok(str_contains(error_of(fn() => add_project($db, 'Álpha', $dir, 'blue', null, [], 'user')), 'already exists'), 'accented project');
            ok(str_contains(error_of(fn() => add_project($db, 'New', $dir, 'blue', null, [['name' => 'Café'], ['name' => 'cafe']], 'user')),
                'already exists'), 'accented section');
            // UNC paths are refused (stat'ed every poll); so is a path too long for the column.
            throws(fn() => add_project($db, 'New', '\\\\localhost\\' . str_replace(':', '$', $dir), 'blue', null, [], 'user'), 'UNC folder');
            ok(str_contains(error_of(fn() => add_project($db, 'New', 'C:\\' . str_repeat('x', 260), 'blue', null, [], 'user')), '255'),
                'too-long folder');
            ok(count(list_projects($db)) === 4, 'bad project written');
            ok($db->query('SELECT COUNT(*) FROM sections WHERE project_id > 4')->fetchColumn() == 0, 'section of a refused project written');
        });
    },
    'is_duplicate_key_spots_a_name_race_only' => function (PDO $db) {
        $caught = fn(callable $fn) => (function () use ($fn) { try { $fn(); } catch (Throwable $e) { return $e; } return null; })();
        $dup = $caught(fn() => $db->exec("INSERT INTO projects (name, folder, color) SELECT name, folder, color FROM projects LIMIT 1"));
        ok($dup !== null && is_duplicate_key($dup), 'duplicate project name');
        ok(!is_duplicate_key($caught(fn() => $db->exec('SELECT nope FROM projects'))), 'other SQL error');
        ok(!is_duplicate_key(new InvalidArgumentException('x')), 'not a PDO error');
    },
    'edit_project_keeps_cards' => function (PDO $db) {
        $dir = dirname(__DIR__);
        with_new_projects($db, function () use ($db, $dir) {
            $id = add_project($db, 'Old Name', $dir, 'gold', null, [['name' => 'Shop']], 'user');
            $card = add_card($db, 'A', 'Old Name', 'user', null, 'Shop');
            edit_project($db, $id, 'New Name', $dir . '\tests', 'pink', 'Staff Name', 'user');
            $c = get_card($db, $card);
            ok($c['project'] === 'New Name' && $c['section'] === 'Shop' && $c['folder'] === realpath("$dir\\tests"), json_encode($c));
            ok(project_row($db, 'New Name')['card_count'] === 1 && project_row($db, 'New Name')['site_name'] === 'Staff Name', 'project row');
            ok(list_events($db)[0]['detail'] === 'renamed Old Name to New Name', list_events($db)[0]['detail']);
            throws(fn() => edit_project($db, $id, 'beta', $dir, 'pink', null, 'user'), 'rename onto another project');
            $n = count(list_events($db));
            edit_project($db, $id, 'new name', $dir . '\tests', 'pink', 'Staff Name', 'user'); // own name, other case
            edit_project($db, $id, 'new name', $dir . '\tests', 'pink', 'Staff Name', 'user'); // nothing changed
            ok(count(list_events($db)) === $n + 1, 'unchanged edit logged');
            throws(fn() => edit_project($db, 9999, 'X', $dir, 'pink', null, 'user'), 'unknown project');
        });
    },
    'save_sections_renames_reorders_adds_and_removes' => function (PDO $db) {
        $dir = dirname(__DIR__);
        with_new_projects($db, function () use ($db, $dir) {
            $id = add_project($db, 'Shop', $dir, 'teal', null, [['name' => 'A'], ['name' => 'B'], ['name' => 'C']], 'user');
            [$a, $b, $c] = array_column(project_row($db, 'Shop')['sections'], 'id');
            $card = add_card($db, 'Tagged', 'Shop', 'user', null, 'A');
            // Rename A to B and B to A (a swap), move C first, add D.
            save_sections($db, $id, [['id' => $c, 'name' => 'C'], ['id' => $a, 'name' => 'B'], ['id' => $b, 'name' => 'A'], ['name' => 'D']], 'user');
            $s = project_row($db, 'Shop')['sections'];
            ok(array_map(fn($x) => "{$x['position']}:{$x['name']}", $s) === ['1:C', '2:B', '3:A', '4:D'], json_encode($s));
            ok($s[1]['id'] === $a && $s[1]['card_count'] === 1, json_encode($s[1]));
            ok(get_card($db, $card)['section'] === 'B', 'rename lost the tag: ' . get_card($db, $card)['section']);
            // Removing the used section is refused and names the count; nothing changes.
            $msg = error_of(fn() => save_sections($db, $id, [['id' => $c, 'name' => 'C']], 'user'));
            ok(str_contains($msg, 'B') && str_contains($msg, '1 card'), "message: $msg");
            ok(count(project_row($db, 'Shop')['sections']) === 4, 'refused save changed sections');
            // Removing unused ones works.
            save_sections($db, $id, [['id' => $a, 'name' => 'B']], 'user');
            ok(array_column(project_row($db, 'Shop')['sections'], 'name') === ['B'], json_encode(project_row($db, 'Shop')['sections']));
            // Another project's section id is refused.
            $loans = list_sections($db, 'Alpha')[1]['id'];
            throws(fn() => save_sections($db, $id, [['id' => $loans, 'name' => 'Loans']], 'user'), "Alpha's section");
            ok(array_column(list_sections($db, 'Alpha'), 'name') === ['Members', 'Loans', 'Whole System'], 'Alpha changed');
            // Id 0 would match no row and silently drop the section.
            throws(fn() => save_sections($db, $id, [['id' => $a, 'name' => 'B'], ['id' => 0, 'name' => 'Z']], 'user'), 'section id 0');
            ok(array_column(project_row($db, 'Shop')['sections'], 'name') === ['B'], 'id 0 changed sections');
            $n = count(list_events($db));
            save_sections($db, $id, [['id' => $a, 'name' => 'B']], 'user');
            ok(count(list_events($db)) === $n, 'unchanged sections logged');
        });
    },
    'board_version_tracks_newest_file' => function (PDO $db) {
        $f = dirname(__DIR__) . '/app.js';
        $old = filemtime($f);
        $future = time() + 5;
        touch($f, $future);
        clearstatcache();
        try {
            ok(board_version() === $future, 'version ' . board_version() . " != $future");
        } finally {
            touch($f, $old);
            clearstatcache();
        }
    },
    'backup_db_writes_once_a_day_and_keeps_newest' => function (PDO $db) {
        $dir = sys_get_temp_dir() . '/kanban_backup_test_' . getmypid();
        @mkdir($dir);
        foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $d) {
            file_put_contents("$dir/kanban-$d.sql", 'old');
        }
        file_put_contents("$dir/notes.txt", 'not a backup');
        try {
            $path = backup_db($dir, '2026-11-14', 2, 'kanban_test');
            ok($path === "$dir/kanban-2026-11-14.sql", 'path: ' . var_export($path, true));
            $sql = file_get_contents($path);
            ok(str_contains($sql, 'CREATE TABLE `cards`') && str_contains($sql, '-- Dump completed'), 'not a full dump');
            $left = array_map('basename', glob("$dir/*"));
            sort($left);
            ok($left === ['kanban-2026-11-03.sql', 'kanban-2026-11-14.sql', 'notes.txt'], implode(',', $left));
            ok(backup_db($dir, '2026-11-14', 2, 'kanban_test') === null, 'second backup the same day');
            throws(fn() => backup_db($dir, '14-11-2026', 2, 'kanban_test'), 'bad date');
            ok(glob("$dir/*.tmp") === [], 'temp file left behind');
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    },
    'backup_copy_prunes_to_keep' => function (PDO $db) {
        $src = sys_get_temp_dir() . '/kanban_copy_src_' . getmypid();
        $dir = sys_get_temp_dir() . '/kanban_copy_dst_' . getmypid(); // copy_backup creates it
        @mkdir($src);
        file_put_contents("$src/kanban-2026-11-09.sql", 'X');
        try {
            $first = copy_backup("$src/kanban-2026-11-09.sql", $dir, 7);
            ok(is_file($first), 'copy_backup did not create the folder');
            for ($i = 1; $i <= 8; $i++) {
                file_put_contents("$dir/kanban-2026-11-0$i.sql", 'old');
            }
            $dest = copy_backup("$src/kanban-2026-11-09.sql", $dir, 7);
            ok(str_ends_with($dest, 'kanban-2026-11-09.sql'), "dest: $dest");
            $left = array_map('basename', glob("$dir/*"));
            sort($left);
            ok(count($left) === 7, implode(',', $left));
            ok($left[0] === 'kanban-2026-11-03.sql' && end($left) === 'kanban-2026-11-09.sql', implode(',', $left));
            ok(file_get_contents("$dir/kanban-2026-11-09.sql") === 'X', 'copied content');
            ok(glob("$dir/*.tmp") === [], 'temp file left behind');
        } finally {
            foreach ([$src, $dir] as $d) {
                array_map('unlink', glob("$d/*") ?: []);
                @rmdir($d);
            }
        }
    },
    'backup_status_reads_latest_and_copy' => function (PDO $db) {
        $local = sys_get_temp_dir() . '/kanban_status_local_' . getmypid();
        $copy = sys_get_temp_dir() . '/kanban_status_copy_' . getmypid();
        @mkdir($local);
        @mkdir($copy);
        try {
            ok(backup_status($local, $copy) === ['date' => null, 'copied' => false], 'empty: ' . json_encode(backup_status($local, $copy)));
            file_put_contents("$local/kanban-2026-11-12.sql", 'a');
            file_put_contents("$local/kanban-2026-11-13.sql", 'b');
            file_put_contents("$copy/kanban-2026-11-12.sql", 'a');
            $s = backup_status($local, $copy);
            ok($s === ['date' => '2026-11-13', 'copied' => false], json_encode($s));
            $s = backup_status($local, null);
            ok($s === ['date' => '2026-11-13', 'copied' => null], json_encode($s));
        } finally {
            foreach ([$local, $copy] as $d) {
                array_map('unlink', glob("$d/*") ?: []);
                @rmdir($d);
            }
        }
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
    $db->exec('DELETE FROM requests');
    $db->exec('DELETE FROM events');
    $db->exec('DELETE FROM deploy_sync');
    $db->exec('DELETE FROM deploy_applied');
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
