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
        <button type="button" id="theme" class="theme" aria-label="Switch theme">Light</button>
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
