# Kanban

A local kanban board for working with Claude Code. One board for all your projects; Claude moves the cards as it works, and you can drag them too. Dragging a card into a work column opens a new Claude desktop session in that project's folder with the right message typed in.

![Kanban board with cards across To do, Planning, Building, Review, You test and Live](docs/screenshot.png)

```
To do → Planning → Building → Review → You test → Live
```

Plain PHP + MySQL + vanilla JS. No framework, no dependencies. Built for XAMPP on Windows.

## Setup

1. Copy this folder to `C:\xampp\htdocs\kanban` and start Apache and MySQL in XAMPP.
2. Create the database:
   ```
   C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE kanban CHARACTER SET utf8mb4"
   C:\xampp\mysql\bin\mysql.exe -uroot kanban < schema.sql
   ```
3. Add your projects: copy `seed.example.sql` to `seed.local.sql`, put in your project names and folders, then:
   ```
   C:\xampp\mysql\bin\mysql.exe -uroot kanban < seed.local.sql
   ```
4. Open http://localhost/kanban.

Database settings other than XAMPP's default (`root`, no password) go in `config.local.php` (git-ignored); see `config.php`.

## Command line (used by Claude)

```
php board.php add "<title>" <project>     # prints #id
php board.php move <id> <column> [pos]    # todo, planning, building, review, test, live
php board.php block <id> "<question>"
php board.php unblock <id>
php board.php find "<text>"
```

To have Claude keep the board current, add a rule to your `~/.claude/CLAUDE.md` telling it to run these commands as work moves on.

## How the Claude link works

Dropping a card into Planning, Building, Review or Live opens
`claude://code/new?prompt=<message>&folder=<project folder>`. The message is typed in, not sent: you press Enter. Claude desktop asks you to confirm the folder each time. Note: `prompt=` works; `q=` did not fill the message in testing.

## Safety

The board only answers local requests (`Require local`, `localhost` host check, same-origin writes). The CLI, config, schema, tests and `.git` are not served over HTTP.

## Tests

```
php tests/lib_test.php
```

Creates and drops a `kanban_test` database.

Not affiliated with Anthropic.
