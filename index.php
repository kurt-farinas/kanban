<?php
require __DIR__ . '/lib.php';
$labels = array_combine(COLUMNS, ['To do', 'Planning', 'Building', 'Review', 'You test', 'Live']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kanban</title>
    <script>
        // Runs before paint so the saved theme never flashes; storage can be blocked, so default to dark.
        try { if (localStorage.getItem('theme') === 'light') document.documentElement.dataset.theme = 'light'; } catch (e) {}
    </script>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div id="error" class="banner" role="alert" hidden></div>
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
                    <button type="button" id="add-open" class="add-open">+ Add card</button>
                    <form id="add" class="add" hidden novalidate>
                        <input name="title" maxlength="200" placeholder="Card title" aria-label="Card title">
                        <p class="field-error" hidden>Enter a title.</p>
                        <select name="project" aria-label="Project"></select>
                        <button type="submit">Add</button>
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
                <button type="submit" class="primary">Save</button>
            </div>
        </form>
        <p class="working" hidden><span class="dot"></span>Claude is working on this</p>
        <p class="panel-status"></p>
        <h3>Notes</h3>
        <ul class="notes"></ul>
        <form id="note" class="stack" novalidate>
            <textarea name="body" maxlength="2000" rows="3" placeholder="Write a note" aria-label="Note"></textarea>
            <p class="field-error" hidden>Write a note first.</p>
            <button type="submit" class="primary">Add note</button>
        </form>
        <h3>Plan</h3>
        <div class="plan md"></div>
        <div class="panel-actions">
            <button type="button" id="plan-btn" class="secondary">Change plan with Claude</button>
            <button type="button" id="delete" class="danger">Delete card</button>
        </div>
    </aside>
    <script src="app.js"></script>
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
