// Board page. All user text goes in via textContent / DOM properties, never innerHTML.
const banner = document.getElementById('error');
const filter = document.getElementById('filter');
const addForm = document.getElementById('add');
const addOpen = document.getElementById('add-open');
const titleInput = addForm.elements.title;
const projectSelect = addForm.elements.project;
const titleError = addForm.querySelector('.field-error');
let cards = new Map(); // id -> card row from the last render
let dragging = null; // card element being dragged
let dropped = false;
let seq = 0; // only the newest list response renders

async function api(params, post = false) {
    let res, data;
    try {
        res = await fetch(post ? 'api.php' : 'api.php?' + new URLSearchParams(params),
            post ? { method: 'POST', body: new URLSearchParams(params) } : {});
        data = await res.json();
    } catch {
        throw new Error(res ? `Server error (${res.status}).` : "Can't reach the server.");
    }
    if (!res.ok) throw new Error(data.error || `Server error (${res.status}).`);
    banner.hidden = true;
    return data;
}

function showError(e) {
    banner.textContent = e.message;
    banner.hidden = false;
}

function el(tag, className, text) {
    const e = document.createElement(tag);
    e.className = className;
    if (text !== undefined) e.textContent = text;
    return e;
}

function fillProjects(projects) {
    if (filter.options.length > 1) return;
    for (const p of projects) {
        filter.add(new Option(p.name, p.name));
        projectSelect.add(new Option(p.name, p.name));
    }
}

function cardEl(c) {
    const card = el('article', 'card');
    card.draggable = true;
    card.dataset.id = c.id;
    card.append(el('div', 'title', c.title));
    const tags = el('div', 'tags');
    const pill = el('span', 'pill', c.project);
    pill.dataset.color = c.color;
    tags.append(pill);
    if (c.blocked) {
        const b = el('span', 'pill blocked', 'Blocked');
        b.title = c.blocked_reason || '';
        tags.append(b);
    }
    card.append(tags);
    return card;
}

function render(data) {
    fillProjects(data.projects);
    cards = new Map(data.cards.map(c => [String(c.id), c]));
    for (const section of document.querySelectorAll('.column')) {
        const mine = data.cards.filter(c => c.col === section.dataset.col);
        section.querySelector('.cards').replaceChildren(...mine.map(cardEl));
        section.querySelector('.count').textContent = mine.length;
    }
}

async function load() {
    const my = ++seq;
    try {
        const data = await api({ a: 'list', project: filter.value });
        if (my === seq && !dragging) render(data);
    } catch (e) {
        showError(e);
    }
}

// Server position = index among the column's other cards, so place before the next visible card.
// Works with a project filter on, where hidden cards sit between visible ones.
function dropPosition() {
    const moved = cards.get(dragging.dataset.id);
    const next = dragging.nextElementSibling && cards.get(dragging.nextElementSibling.dataset.id);
    if (!next) return null; // end of column
    const sameCol = moved.col === next.col;
    return next.position - (sameCol && moved.position < next.position ? 1 : 0);
}

document.querySelector('.board').addEventListener('dragstart', e => {
    const card = e.target.closest('.card');
    if (!card) return;
    dragging = card;
    dropped = false;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', card.dataset.id);
    card.classList.add('dragging');
});

document.querySelector('.board').addEventListener('dragend', () => {
    if (!dragging) return;
    dragging.classList.remove('dragging');
    dragging = null;
    if (!dropped) load(); // cancelled: undo the preview
});

for (const section of document.querySelectorAll('.column')) {
    const list = section.querySelector('.cards');
    section.addEventListener('dragover', e => {
        if (!dragging) return;
        e.preventDefault();
        const before = [...list.children].find(c => c !== dragging
            && e.clientY < c.getBoundingClientRect().top + c.offsetHeight / 2);
        list.insertBefore(dragging, before || null);
    });
    section.addEventListener('drop', async e => {
        if (!dragging) return;
        e.preventDefault();
        dropped = true;
        const params = { a: 'move', id: dragging.dataset.id, col: section.dataset.col };
        const pos = dropPosition();
        if (pos !== null) params.position = pos;
        try {
            const res = await api(params, true);
            if (res.link) window.location.href = res.link;
        } catch (err) {
            showError(err);
        }
        load();
    });
}

filter.addEventListener('change', load);

addOpen.addEventListener('click', () => {
    addForm.hidden = false;
    addOpen.hidden = true;
    titleInput.focus();
});

titleInput.addEventListener('input', () => { titleError.hidden = true; });

addForm.addEventListener('submit', async e => {
    e.preventDefault();
    if (titleInput.value.trim() === '') {
        titleError.hidden = false;
        return;
    }
    try {
        await api({ a: 'add', title: titleInput.value, project: projectSelect.value }, true);
        titleInput.value = '';
        load();
    } catch (err) {
        showError(err);
    }
});

load();
setInterval(() => { if (!dragging) load(); }, 10000);
