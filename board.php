<?php
// CLI for Claude: php board.php add|move|block|unblock|find|status|note|notes|plan|sync ...
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

const USAGE = 'Usage: php board.php add "<title>" <project> | move <id> <column> [position]'
    . ' | block <id> "<reason>" | unblock <id> | find "<text>"'
    . ' | status <id> "<text>" | note <id> "<text>" | notes <id> | plan <id> <path> | sync';

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
try {
    try {
        $db = db();
    } catch (PDOException $e) {
        throw new RuntimeException("Can't reach the database. Is MySQL running in XAMPP?");
    }
    switch ($cmd) {
        case 'add':
            need($a, $b);
            echo '#', add_card($db, $a, $b, 'claude'), "\n";
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
