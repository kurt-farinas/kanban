<?php
require __DIR__ . '/lib.php';
$labels = array_combine(COLUMNS, ['To do', 'Planning', 'Building', 'Review', 'You test', 'Live']);
// Version assets so an open app window never keeps running old code after an update.
$version = board_version();
$v = fn(string $f) => $f . '?v=' . $version;
header('Cache-Control: no-cache');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="board-version" content="<?= $version ?>">
    <title>Kanban</title>
    <script>
        // Runs before paint so the saved theme never flashes; storage can be blocked, so default to dark.
        try { if (localStorage.getItem('theme') === 'light') document.documentElement.dataset.theme = 'light'; } catch (e) {}
    </script>
    <link rel="stylesheet" href="<?= $v('style.css') ?>">
</head>
<body>
    <div id="error" class="banner" role="alert" hidden></div>
    <div id="offline" class="banner" hidden><span role="alert">Can't reach the database.</span> <span class="offline-text" aria-live="off"></span> <button type="button" id="start-db" class="banner-btn" hidden>Start database</button></div>
    <div id="update" class="banner banner-update" role="status" hidden>Board updated, reloading when you're done</div>
    <header class="topbar">
        <h1>Kanban</h1>
        <label>Project
            <select id="filter"><option value="">All projects</option></select>
        </label>
        <button type="button" id="activity-toggle" class="bar-btn" aria-expanded="false" aria-controls="activity">Activity</button>
        <button type="button" id="theme" class="bar-btn" aria-label="Switch theme">Light</button>
        <section id="activity" class="activity" aria-label="Activity" hidden>
            <h2>Activity</h2>
            <ul class="events"></ul>
        </section>
    </header>
    <main class="board">
        <?php foreach ($labels as $col => $label): ?>
            <section class="column" data-col="<?= $col ?>">
                <h2><?= $label ?> <span class="count">0</span></h2>
                <div class="cards"></div>
                <?php if ($col === 'todo'): ?>
                    <button type="button" id="add-open" class="add-open" data-needs-db>+ Add card</button>
                    <form id="add" class="add" hidden novalidate>
                        <input name="title" maxlength="200" placeholder="Card title" aria-label="Card title">
                        <p class="field-error" hidden>Enter a title.</p>
                        <select name="project" aria-label="Project"></select>
                        <button type="submit" data-needs-db>Add</button>
                    </form>
                <?php endif ?>
            </section>
        <?php endforeach ?>
    </main>
    <div id="overlay" class="overlay" hidden></div>
    <aside id="panel" class="panel" role="dialog" aria-modal="true" aria-labelledby="panel-heading" hidden>
        <div class="panel-head">
            <h2 id="panel-heading">Card</h2>
            <button type="button" class="close" aria-label="Close">&#x2715;</button>
        </div>
        <form id="edit" class="stack" novalidate>
            <input name="title" maxlength="200" aria-label="Card title">
            <p class="field-error" hidden>Enter a title.</p>
            <div class="row">
                <select name="project" aria-label="Project"></select>
                <button type="submit" class="primary" data-needs-db>Save</button>
            </div>
        </form>
        <p class="working" hidden><span class="dot"></span>Claude is working on this</p>
        <p class="panel-status"></p>
        <h3>Notes</h3>
        <ul class="notes"></ul>
        <form id="note" class="stack" novalidate>
            <textarea name="body" maxlength="2000" rows="3" placeholder="Write a note" aria-label="Note"></textarea>
            <p class="field-error" hidden>Write a note first.</p>
            <button type="submit" class="primary" data-needs-db>Add note</button>
        </form>
        <h3>Plan</h3>
        <div class="plan md"></div>
        <div class="panel-actions">
            <button type="button" id="plan-btn" class="secondary" data-needs-db>Change plan with Claude</button>
            <button type="button" id="delete" class="danger" data-needs-db>Delete card</button>
        </div>
    </aside>
    <div id="confirm" class="confirm-wrap" hidden>
        <div class="confirm" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-body">
            <h2 id="confirm-title"></h2>
            <p id="confirm-body"></p>
            <div class="confirm-actions">
                <button type="button" class="secondary" data-ok="0">Cancel</button>
                <button type="button" data-ok="1">OK</button>
            </div>
        </div>
    </div>
    <div id="toasts" class="toasts"></div>
    <script src="<?= $v('app.js') ?>"></script>
    <script>
        (function () {
            const btn = document.getElementById('theme');
            const sync = () => { btn.textContent = document.documentElement.dataset.theme === 'light' ? 'Dark' : 'Light'; };
            btn.addEventListener('click', () => {
                const light = document.documentElement.dataset.theme !== 'light';
                if (light) document.documentElement.dataset.theme = 'light'; else delete document.documentElement.dataset.theme;
                try { localStorage.setItem('theme', light ? 'light' : 'dark'); } catch (e) {}
                sync();
            });
            sync();
        })();
    </script>
</body>
</html>
