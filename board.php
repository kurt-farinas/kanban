<?php
// CLI for Claude: php board.php add|move|block|unblock|find|status|note|notes|plan|section|sections|projects|report|sync|backup ...
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

const USAGE = 'Usage: php board.php add "<title>" <project> [section] | move <id> <column> [position]'
    . ' | block <id> "<reason>" | unblock <id> | find "<text>"'
    . ' | status <id> "<text>" | note <id> "<text>" | notes <id> | plan <id> <path>'
    . ' | section <id> "<name>"|none | sections <project> | projects | report <project> <from> <to> | sync | backup';

function int_arg(?string $s): int
{
    if ($s === null || !ctype_digit($s)) {
        throw new InvalidArgumentException("Expected a number, got '" . ($s ?? '') . "'. " . USAGE);
    }
    return (int) $s;
}

function need(?string ...$args): void
{
    if (in_array(null, $args, true)) {
        throw new InvalidArgumentException(USAGE);
    }
}

[$cmd, $a, $b, $c] = array_pad(array_slice($argv, 1), 4, null);

// Daily backup (Windows Task Scheduler runs this): quiet when the database is off, a toast when a dump fails.
if ($cmd === 'backup') {
    try {
        $today = db()->query('SELECT CURDATE()')->fetchColumn(); // the PC's local date (PHP's timezone may differ)
    } catch (PDOException $e) {
        fwrite(STDERR, "Database not running; backup skipped.\n");
        exit(0);
    }
    try {
        $path = backup_db(backup_dir(), $today);
        echo $path === null ? "Today's backup already exists.\n" : "Saved $path\n";
        exit(0);
    } catch (Throwable $e) {
        notify('Kanban backup failed', $e->getMessage());
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

try {
    try {
        $db = db();
    } catch (PDOException $e) {
        throw new RuntimeException("Can't reach the database. Is MySQL running in XAMPP?");
    }
    switch ($cmd) {
        case 'add':
            need($a, $b);
            echo '#', add_card($db, $a, $b, 'claude', null, $c), "\n";
            break;
        case 'move':
            need($a, $b);
            move_card($db, int_arg($a), $b, 'claude', $c === null ? null : int_arg($c));
            break;
        case 'block':
            need($a, $b);
            if (trim($b) === '') {
                throw new InvalidArgumentException('Give a reason to block (use unblock to clear).');
            }
            set_blocked($db, int_arg($a), $b, 'claude');
            break;
        case 'unblock':
            set_blocked($db, int_arg($a), null, 'claude');
            break;
        case 'find':
            need($a);
            foreach (find_cards($db, $a) as $r) {
                echo "#{$r['id']} [{$r['col']}] {$r['project']}: {$r['title']}\n";
            }
            break;
        case 'status':
            set_status($db, int_arg($a), $b, 'claude');
            break;
        case 'note':
            need($a, $b);
            add_note($db, int_arg($a), $b, 'claude');
            break;
        case 'notes':
            foreach (list_notes($db, int_arg($a)) as $n) {
                echo "[{$n['author']} {$n['created_at']}] {$n['body']}\n";
            }
            break;
        case 'plan':
            need($a, $b);
            set_plan($db, int_arg($a), $b, 'claude');
            break;
        case 'section':
            need($a, $b);
            set_section($db, int_arg($a), strtolower(trim($b)) === 'none' ? null : $b, 'claude');
            break;
        case 'sections':
            need($a);
            foreach (list_sections($db, $a) as $s) {
                echo $s['name'], "\n";
            }
            break;
        case 'projects':
            foreach (list_projects($db) as $p) {
                echo "{$p['name']} | {$p['folder']} | sections: ", implode(', ', array_column($p['sections'], 'name')) ?: 'none', "\n";
            }
            break;
        case 'report':
            need($a, $b, $c);
            echo json_encode(report_cards($db, $a, $b, $c), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
            break;
        case 'sync':
            echo sync_deployments($db), "\n";
            break;
        default:
            throw new InvalidArgumentException(USAGE);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
