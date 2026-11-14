// Board page. All user and file text goes in via textContent / DOM properties, never innerHTML.
const banner = document.getElementById('error');
const filter = document.getElementById('filter');
const addForm = document.getElementById('add');
const addOpen = document.getElementById('add-open');
const titleInput = addForm.elements.title;
const projectSelect = addForm.elements.project;
const titleError = addForm.querySelector('.field-error');
const panel = document.getElementById('panel');
const overlay = document.getElementById('overlay');
const editForm = document.getElementById('edit');
const noteForm = document.getElementById('note');
const activity = document.getElementById('activity');
const activityToggle = document.getElementById('activity-toggle');
const LABELS = Object.fromEntries([...document.querySelectorAll('.column')]
    .map(s => [s.dataset.col, s.querySelector('h2').firstChild.textContent.trim()]));
const WORKING_MS = 30 * 60 * 1000; // a dot older than this without a refresh is a crashed session
let cards = new Map(); // id -> card row from the last render
let dragging = null; // card element being dragged
let dropped = false;
let dragged = false; // a drag started since the last mousedown, so the click is not a panel open
let seq = 0; // only the newest list response renders
let openId = null; // card shown in the details panel
let openCard = null;
let blockedIds = null; // blocked card ids at the last poll; null = record silently
let unseenBlocks = 0;
let workingIds = null; // card ids with the working dot at the last render; null = record silently
let lastEventId = null; // newest event already seen; null = not fetched yet, so old events never toast
let eventsPolling = false;
const pendingDeletes = new Set(); // card ids deleted in the UI whose request waits behind an Undo toast
let opening = 0; // Claude links waiting behind an Undo toast
// Columns whose move opens a Claude session (lib.php claude_link), with the hint shown while dragging over them.
const CLAUDE_HINTS = {
    planning: 'Drop to plan with Claude',
    building: 'Drop to start building with Claude',
    review: 'Drop to review with Claude',
    live: 'Drop to deploy with Claude',
};

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

// Background (poll) failures use the banner; the user's own actions report through toast().
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

// All claude:// navigation goes through here.
function openLink(url) {
    window.location.href = url;
}

// --- Toasts, confirm popup, saving state ---

const toastBox = document.getElementById('toasts');
const TOAST_MAX = 3;

// kind: 'user' (gray), 'claude' (clay), 'error' (red, stays until closed unless ms is given).
// action: {label, run} adds a button that closes the toast and runs. Returns a function that closes it.
function toast(text, { kind = 'user', action, ms } = {}) {
    const t = el('div', `toast toast-${kind}`);
    t.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    if (kind === 'claude') {
        const mark = el('span', 'toast-mark', '✻');
        mark.setAttribute('aria-hidden', 'true');
        t.append(mark);
    }
    t.append(el('span', 'toast-text', text));
    let timer = null;
    const close = () => {
        clearTimeout(timer);
        t.remove();
    };
    if (action) {
        const b = el('button', 'toast-action', action.label);
        b.type = 'button';
        b.addEventListener('click', () => { close(); action.run(); });
        t.append(b);
    }
    const x = el('button', 'close', '✕');
    x.type = 'button';
    x.setAttribute('aria-label', 'Dismiss');
    x.addEventListener('click', close);
    t.append(x);
    toastBox.append(t);
    // Over the limit the oldest goes early, errors last.
    while (toastBox.children.length > TOAST_MAX) {
        (toastBox.querySelector('.toast:not(.toast-error)') || toastBox.firstElementChild).remove();
    }
    ms ??= kind === 'error' ? null : 4000;
    if (ms !== null) timer = setTimeout(close, ms);
    return close;
}

const toastError = err => toast(err.message, { kind: 'error' });

const confirmWrap = document.getElementById('confirm');
const [confirmCancel, confirmOk] = confirmWrap.querySelectorAll('button');
let confirmDone = null; // resolves the open popup

// Styled replacement for the native confirm dialog. Esc / backdrop = cancel; Enter acts on the focused button.
// A second call while one is open resolves false at once.
function confirmBox({ title, body = '', ok = 'OK', danger = false }) {
    if (confirmDone) return Promise.resolve(false);
    document.getElementById('confirm-title').textContent = title;
    document.getElementById('confirm-body').textContent = body;
    confirmOk.textContent = ok;
    confirmOk.className = danger ? 'danger' : 'primary';
    const back = document.activeElement;
    confirmWrap.hidden = false;
    (danger ? confirmCancel : confirmOk).focus();
    return new Promise(resolve => {
        confirmDone = value => {
            confirmDone = null;
            confirmWrap.hidden = true;
            if (back && back.isConnected) back.focus();
            resolve(value);
        };
    });
}

confirmWrap.addEventListener('click', e => {
    const b = e.target.closest('button');
    if (b) confirmDone?.(b.dataset.ok === '1');
    else if (e.target === confirmWrap) confirmDone?.(false);
});

document.addEventListener('keydown', e => {
    if (!confirmDone) return;
    if (e.key === 'Escape') {
        e.stopImmediatePropagation(); // closes the popup only, not the panel behind it
        confirmDone(false);
    } else if (e.key === 'Tab') {
        e.preventDefault(); // keep focus inside the popup
        (document.activeElement === confirmOk ? confirmCancel : confirmOk).focus();
    }
}, true);

// Disables the button and shows "Saving…" while the request runs; returns the request's result.
async function busy(button, promise) {
    const label = button.textContent;
    button.disabled = true;
    button.textContent = 'Saving…';
    try {
        return await promise;
    } finally {
        button.disabled = false;
        button.textContent = label;
    }
}

// MySQL "YYYY-MM-DD HH:MM:SS" in server local time; the browser runs on the same PC.
const parseTime = ts => new Date(ts.replace(' ', 'T')).getTime();

function ago(ts) {
    const s = Math.max(0, (Date.now() - parseTime(ts)) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return `${Math.floor(s / 60)} min ago`;
    if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
    return `${Math.floor(s / 86400)} d ago`;
}

const isWorking = c => !!c.working_since && Date.now() - parseTime(c.working_since) < WORKING_MS;
const who = author => author === 'claude' ? 'Claude' : 'You';

// --- Markdown: a small subset, built as DOM nodes. Anything else stays plain text. ---

const INLINE = /`([^`]+)`|\*\*(.+?)\*\*|\[([^\]]+)\]\(([^)\s]+)\)/g;
const TABLE_SEP = /^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/;

function inline(text) {
    const out = [];
    let last = 0;
    for (const m of text.matchAll(INLINE)) {
        out.push(text.slice(last, m.index));
        last = m.index + m[0].length;
        if (m[1] !== undefined) {
            out.push(el('code', '', m[1]));
        } else if (m[2] !== undefined) {
            const b = document.createElement('strong');
            b.append(...inline(m[2]));
            out.push(b);
        } else if (/^https?:\/\//i.test(m[4])) {
            const a = el('a', '', m[3]);
            a.href = m[4];
            a.target = '_blank';
            a.rel = 'noopener';
            out.push(a);
        } else {
            out.push(m[0]); // non-http link: shown as typed
        }
    }
    out.push(text.slice(last));
    return out;
}

const cells = line => line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(s => s.trim());

function md(src) {
    const out = document.createDocumentFragment();
    const lines = src.replace(/\r\n?/g, '\n').split('\n');
    let para = [];
    let list = null;
    const flush = () => {
        if (para.length) {
            const p = document.createElement('p');
            p.append(...inline(para.join(' ')));
            out.append(p);
            para = [];
        }
        list = null;
    };
    const row = (line, tag) => {
        const tr = document.createElement('tr');
        for (const cell of cells(line)) {
            const td = document.createElement(tag);
            td.append(...inline(cell));
            tr.append(td);
        }
        return tr;
    };
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        let m;
        if (/^\s*```/.test(line)) {
            flush();
            const code = [];
            while (++i < lines.length && !/^\s*```/.test(lines[i])) code.push(lines[i]);
            const pre = document.createElement('pre');
            pre.append(el('code', '', code.join('\n')));
            out.append(pre);
        } else if ((m = /^(#{1,3})\s+(.*)$/.exec(line))) {
            flush();
            const h = document.createElement('h' + (m[1].length + 3)); // panel already uses h2/h3
            h.append(...inline(m[2]));
            out.append(h);
        } else if (/^\s*\|/.test(line) && TABLE_SEP.test(lines[i + 1] || '')) {
            flush();
            const table = document.createElement('table');
            const head = document.createElement('thead');
            const body = document.createElement('tbody');
            head.append(row(line, 'th'));
            i++; // separator
            while (/^\s*\|/.test(lines[i + 1] || '')) body.append(row(lines[++i], 'td'));
            table.append(head, body);
            out.append(table);
        } else if ((m = /^\s*([-*]|\d+[.)])\s+(.*)$/.exec(line))) {
            if (para.length) flush();
            const tag = /\d/.test(m[1]) ? 'OL' : 'UL';
            if (!list || list.tagName !== tag) {
                list = document.createElement(tag);
                out.append(list);
            }
            const li = document.createElement('li');
            const box = /^\[([ xX])\]\s+(.*)$/.exec(m[2]);
            if (box) {
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.disabled = true;
                cb.checked = box[1] !== ' ';
                li.className = 'task';
                li.append(cb, ' ', ...inline(box[2]));
            } else {
                li.append(...inline(m[2]));
            }
            list.append(li);
        } else if (line.trim() === '') {
            flush();
        } else {
            list = null;
            para.push(line.trim());
        }
    }
    flush();
    return out;
}

// --- Board ---

function fillProjects(projects) {
    if (filter.options.length > 1) return;
    for (const p of projects) {
        filter.add(new Option(p.name, p.name));
        projectSelect.add(new Option(p.name, p.name));
        editForm.elements.project.add(new Option(p.name, p.name));
    }
}

function cardEl(c) {
    const card = el('article', 'card');
    card.draggable = true;
    card.tabIndex = 0;
    card.dataset.id = c.id;
    card.title = 'Click for details · drag to move · right-click for more';
    const title = el('div', 'title');
    if (isWorking(c)) {
        const dot = el('span', 'dot');
        dot.title = 'Claude is working on this';
        title.append(dot);
    }
    title.append(c.title);
    card.append(title);
    if (c.status) card.append(el('div', 'status', c.status));
    const tags = el('div', 'tags');
    const pill = el('span', 'pill', c.project);
    pill.dataset.color = c.color;
    tags.append(pill);
    if (c.blocked) {
        const b = el('span', 'pill blocked', 'Blocked');
        b.title = c.blocked_reason || '';
        tags.append(b);
    }
    if (c.note_count > 0) {
        const n = el('span', 'note-count', `✎ ${c.note_count}`);
        n.title = c.note_count === 1 ? '1 note' : `${c.note_count} notes`;
        tags.append(n);
    }
    card.append(tags);
    const actions = el('div', 'card-actions');
    for (const [act, icon, verb] of [['edit', '✎', 'Edit'], ['delete', '🗑', 'Delete']]) {
        const b = el('button', 'card-act', icon);
        b.type = 'button';
        b.dataset.act = act;
        b.setAttribute('aria-label', `${verb} card #${c.id}`);
        actions.append(b);
    }
    card.append(actions);
    return card;
}

function render(data) {
    fillProjects(data.projects);
    cards = new Map(data.cards.map(c => [String(c.id), c]));
    const shown = data.cards.filter(c => !pendingDeletes.has(String(c.id)));
    for (const section of document.querySelectorAll('.column')) {
        const mine = shown.filter(c => c.col === section.dataset.col);
        section.querySelector('.cards').replaceChildren(...mine.map(cardEl));
        section.querySelector('.count').textContent = mine.length;
    }
    noticeBlocks(data.cards);
    noticeWorking(data.cards);
}

// "Claude started" toast when a card's working dot appears since the last render.
function noticeWorking(list) {
    const now = new Set(list.filter(isWorking).map(c => c.id));
    for (const c of list) {
        if (workingIds && now.has(c.id) && !workingIds.has(c.id)) {
            toast(`Claude started on #${c.id}${c.status ? `: ${c.status}` : ''}`, { kind: 'claude' });
        }
    }
    workingIds = now;
}

// --- Blocked alerts: tab title + beep for cards blocked since the last poll ---

function noticeBlocks(list) {
    const now = new Set(list.filter(c => c.blocked).map(c => c.id));
    const fresh = blockedIds ? [...now].filter(id => !blockedIds.has(id)).length : 0;
    blockedIds = now;
    if (!fresh) return;
    unseenBlocks += fresh;
    document.title = `(${unseenBlocks}) Kanban`;
    beep();
}

function seen() {
    if (document.visibilityState !== 'visible' || !document.hasFocus()) return;
    unseenBlocks = 0;
    document.title = 'Kanban';
}

let audio = null; // one context for the page; a new one per beep piles up when autoplay keeps them suspended

function beep() {
    try {
        const ctx = audio ??= new AudioContext();
        ctx.resume().catch(() => {});
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.frequency.value = 880;
        gain.gain.setValueAtTime(0.15, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
        osc.connect(gain).connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.3);
    } catch {
        // Audio blocked or unsupported: the title change still shows.
    }
}

window.addEventListener('focus', seen);
document.addEventListener('visibilitychange', seen);
document.addEventListener('pointerdown', seen);

// Auto-update: reload when the board's code changes. A new version must show on two polls in a row
// (a file may be mid-save), and the reload waits while the user types or drags so nothing is lost.
const pageVersion = document.querySelector('meta[name="board-version"]')?.content || null;
let seenVersion = null;
let updatePending = false;

function userBusy() {
    const el = document.activeElement;
    const typing = el && el.matches('input, textarea, select') && el.value !== '' && el.id !== 'filter';
    return dragging || typing || pendingDeletes.size > 0 || opening > 0 || confirmDone;
}

function tryReload() {
    if (!updatePending) return;
    if (userBusy()) document.getElementById('update').hidden = false;
    else location.reload();
}

function checkVersion(v) {
    if (!pageVersion || v === undefined) return; // no version info: never auto-reload
    v = String(v);
    if (v === pageVersion) { seenVersion = null; return; }
    if (seenVersion === v) updatePending = true;
    seenVersion = v;
    tryReload();
}

document.addEventListener('focusout', () => setTimeout(tryReload, 0));
document.addEventListener('dragend', () => setTimeout(tryReload, 0));

async function load() {
    const my = ++seq;
    try {
        const data = await api({ a: 'list', project: filter.value });
        checkVersion(data.version);
        if (my === seq && !dragging && !confirmDone) render(data); // a drop waiting on the popup keeps its preview
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

const board = document.querySelector('.board');

function openQuestion(c, col) {
    if (col === 'live') {
        return {
            title: `Deploy #${c.id} (${c.project}) to its live site?`,
            body: "Claude follows that project's deploy rules: backup, log, then check the site.",
            ok: 'Start deploy chat',
            danger: true,
        };
    }
    const verb = { planning: 'Plan', building: 'Start building', review: 'Review' }[col];
    return {
        title: `${verb} #${c.id} with Claude?`,
        body: `"${c.title}" moves to ${LABELS[col]} and a new Claude chat opens in ${c.project}'s folder.`,
        ok: 'Move and open Claude',
    };
}

// Moves into a Claude column ask first; the move is saved at once and the link opens after OPEN_MS
// unless Undo moves the card back (best effort: its old column and position).
const OPEN_MS = 3000;

async function moveCard(params) {
    const c = cards.get(String(params.id));
    const to = LABELS[params.col] || params.col;
    const opens = !!c && c.col !== params.col && params.col in CLAUDE_HINTS;
    if (opens && !(await confirmBox(openQuestion(c, params.col)))) {
        load(); // put the card back
        return;
    }
    try {
        const res = await api(params, true);
        if (opens && res.link) openSoon(c, res.link);
        else toast(c?.col === params.col ? `Reordered in ${to}` : `Moved to ${to}`);
    } catch (err) {
        toastError(err);
    }
    load();
}

function openSoon(c, link) {
    opening++;
    let close = null;
    const timer = setTimeout(() => {
        opening--;
        close(); // the Undo goes before the link opens
        openLink(link);
    }, OPEN_MS);
    close = toast(`Opening Claude for #${c.id}…`, {
        ms: OPEN_MS,
        action: {
            label: 'Undo',
            run: async () => {
                clearTimeout(timer);
                opening--;
                try {
                    await api({ a: 'move', id: c.id, col: c.col, position: c.position }, true);
                    toast(`Moved back to ${LABELS[c.col] || c.col}`);
                } catch (err) {
                    toastError(err);
                }
                load();
            },
        },
    });
}

// One hint element, moved into whichever Claude column the drag is over.
const dropHint = el('p', 'drop-hint');

function showHint(section) {
    const text = section && CLAUDE_HINTS[section.dataset.col];
    if (!text) {
        dropHint.remove();
        document.querySelector('.column.drop-claude')?.classList.remove('drop-claude');
        return;
    }
    if (dropHint.parentNode === section) return;
    showHint(null);
    dropHint.textContent = text;
    section.querySelector('h2').after(dropHint);
    section.classList.add('drop-claude');
}

board.addEventListener('mousedown', () => { dragged = false; });

board.addEventListener('dragstart', e => {
    const card = e.target.closest('.card');
    if (!card) return;
    dragging = card;
    dropped = false;
    dragged = true;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', card.dataset.id);
    card.classList.add('dragging');
});

board.addEventListener('dragend', () => {
    showHint(null);
    if (!dragging) return;
    dragging.classList.remove('dragging');
    dragging = null;
    if (!dropped) load(); // cancelled: undo the preview
});

// A button inside a draggable card would start the card's drag, so switch dragging off while it is pressed.
board.addEventListener('pointerdown', e => {
    const card = e.target.closest('.card');
    if (card && e.target.closest('.card-actions')) {
        card.draggable = false;
        document.addEventListener('pointerup', () => { card.draggable = true; }, { once: true });
    }
});

board.addEventListener('click', e => {
    const card = e.target.closest('.card');
    if (!card || dragged) return;
    const act = e.target.closest('.card-act');
    if (!act) openPanel(card.dataset.id);
    else if (act.dataset.act === 'edit') openPanel(card.dataset.id, true);
    else deleteCard(card.dataset.id);
});

board.addEventListener('dblclick', e => {
    const card = e.target.closest('.card');
    if (card && !e.target.closest('.card-actions')) openPanel(card.dataset.id, true);
});

board.addEventListener('contextmenu', e => {
    const card = e.target.closest('.card');
    if (!card) return;
    e.preventDefault();
    openMenu(card, e.clientX, e.clientY);
});

// --- Right-click menu ---

let menu = null;

function closeMenu(refocus = false) {
    if (!menu) return;
    const card = menu.card;
    menu.remove();
    menu = null;
    if (refocus) card.focus();
}

function openMenu(card, x, y) {
    closeMenu();
    const id = card.dataset.id;
    menu = el('div', 'ctx-menu');
    menu.card = card;
    menu.setAttribute('role', 'menu');
    const item = (text, fn, className = 'ctx-item') => {
        const b = el('button', className, text);
        b.type = 'button';
        b.setAttribute('role', 'menuitem');
        b.addEventListener('click', () => { closeMenu(); fn(); });
        menu.append(b);
    };
    item('Open details', () => openPanel(id));
    item('Edit title', () => openPanel(id, true));
    menu.append(el('div', 'ctx-label', 'Move to ▸'));
    for (const col of Object.keys(LABELS)) {
        if (col !== cards.get(id)?.col) {
            item(LABELS[col] + (col in CLAUDE_HINTS ? '…' : ''), () => moveCard({ a: 'move', id, col }), 'ctx-item ctx-sub');
        }
    }
    menu.append(el('hr', 'ctx-sep'));
    item('Delete…', () => deleteCard(id), 'ctx-item ctx-danger');
    document.body.append(menu);
    // Keep the menu inside the viewport.
    menu.style.left = Math.max(0, Math.min(x, innerWidth - menu.offsetWidth)) + 'px';
    menu.style.top = Math.max(0, Math.min(y, innerHeight - menu.offsetHeight)) + 'px';
    menu.querySelector('button').focus();
}

document.addEventListener('pointerdown', e => { if (menu && !menu.contains(e.target)) closeMenu(); });
document.addEventListener('scroll', () => closeMenu(), true);
window.addEventListener('blur', () => closeMenu());
document.addEventListener('keydown', e => {
    if (!menu) return;
    const items = [...menu.querySelectorAll('button')];
    const i = items.indexOf(document.activeElement);
    if (e.key === 'Escape') {
        e.stopImmediatePropagation(); // closes the menu only, not the panel behind it
        closeMenu(true);
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length].focus();
    }
}, true);

board.addEventListener('keydown', e => {
    const card = e.target.closest('.card');
    if (card && e.target === card && (e.key === 'Enter' || e.key === ' ')) {
        e.preventDefault();
        openPanel(card.dataset.id);
    }
});

for (const section of document.querySelectorAll('.column')) {
    const list = section.querySelector('.cards');
    section.addEventListener('dragover', e => {
        if (!dragging) return;
        e.preventDefault();
        showHint(section);
        const before = [...list.children].find(c => c !== dragging
            && e.clientY < c.getBoundingClientRect().top + c.offsetHeight / 2);
        list.insertBefore(dragging, before || null);
    });
    section.addEventListener('dragleave', e => {
        if (!section.contains(e.relatedTarget)) showHint(null);
    });
    section.addEventListener('drop', async e => {
        if (!dragging) return;
        e.preventDefault();
        showHint(null);
        dropped = true;
        const params = { a: 'move', id: dragging.dataset.id, col: section.dataset.col };
        const pos = dropPosition();
        if (pos !== null) params.position = pos;
        moveCard(params);
    });
}

filter.addEventListener('change', () => {
    blockedIds = null; // other cards come into view; they are not new blocks
    workingIds = null;
    load();
});

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
        toast(titleError.textContent, { kind: 'error' });
        return;
    }
    try {
        await busy(addForm.querySelector('button[type=submit]'),
            api({ a: 'add', title: titleInput.value, project: projectSelect.value }, true));
        titleInput.value = '';
        toast('Card added');
        load();
    } catch (err) {
        toastError(err);
    }
});

// --- Details panel ---

const planLink = c => 'claude://code/new?prompt='
    + encodeURIComponent(`Update the plan for card #${c.id} (${c.project}): `)
    + '&folder=' + encodeURIComponent(c.folder);

function openPanel(id, editTitle = false) {
    openId = String(id);
    openCard = null;
    editForm.querySelector('.field-error').hidden = true;
    noteForm.querySelector('.field-error').hidden = true;
    noteForm.elements.body.value = '';
    // Blank the last card's details so they never show under the new heading while loading.
    document.getElementById('panel-heading').textContent = `Card #${id}`;
    editForm.elements.title.value = '';
    panel.querySelector('.panel-status').textContent = '';
    panel.querySelector('.working').hidden = true;
    panel.querySelector('.notes').replaceChildren();
    panel.querySelector('.plan').replaceChildren();
    panel.hidden = overlay.hidden = false;
    (editTitle ? editForm.elements.title : panel.querySelector('.close')).focus();
    showCard(true, editTitle);
}

function closePanel() {
    if (openId === null) return;
    const id = openId;
    openId = openCard = null;
    panel.hidden = overlay.hidden = true;
    const card = board.querySelector(`.card[data-id="${CSS.escape(id)}"]`);
    if (card) card.focus();
}

// fill = also reset the edit form (on open and after a save), so a poll never wipes typing.
async function showCard(fill, select = false) {
    const id = openId;
    let data;
    try {
        data = await api({ a: 'card', id });
    } catch (e) {
        if (id === openId) {
            closePanel();
            showError(e);
        }
        return;
    }
    if (id !== openId) return;
    const c = openCard = data.card;
    document.getElementById('panel-heading').textContent = `Card #${c.id}`;
    if (fill) {
        editForm.elements.title.value = c.title;
        editForm.elements.project.value = c.project;
        if (select && !panel.hidden) editForm.elements.title.select();
    }
    panel.querySelector('.working').hidden = !isWorking(c);
    const status = [LABELS[c.col] || c.col];
    if (c.blocked) status.push(`Blocked: ${c.blocked_reason || ''}`);
    if (c.status) status.push(c.status);
    panel.querySelector('.panel-status').textContent = status.join(' · ');
    panel.querySelector('.notes').replaceChildren(...(data.notes.length ? data.notes.map(noteEl)
        : [el('li', 'empty', 'No notes yet.')]));
    let plan;
    try {
        plan = md((await api({ a: 'plan', id })).markdown);
    } catch (e) {
        plan = el('p', 'empty', e.message); // 404 says "No plan yet"
    }
    if (id === openId) panel.querySelector('.plan').replaceChildren(plan);
}

function noteEl(n) {
    const li = el('li', 'note');
    const head = el('div', 'note-head');
    head.append(el('span', `badge ${n.author}`, who(n.author)));
    const time = el('time', '', ago(n.created_at));
    time.title = n.created_at;
    head.append(time);
    li.append(head, el('p', 'note-body', n.body));
    return li;
}

panel.querySelector('.close').addEventListener('click', closePanel);
// The second click of a double-click lands on the overlay the first one opened: treat it as "edit title".
overlay.addEventListener('click', e => {
    if (e.detail > 1 && openId !== null) editForm.elements.title.focus();
    else closePanel();
});

document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (openId !== null) closePanel();
    else if (!activity.hidden) toggleActivity(false);
});

editForm.elements.title.addEventListener('input', () => { editForm.querySelector('.field-error').hidden = true; });

editForm.addEventListener('submit', async e => {
    e.preventDefault();
    const title = editForm.elements.title.value;
    const error = editForm.querySelector('.field-error');
    if (title.trim() === '') {
        error.hidden = false;
        toast(error.textContent, { kind: 'error' });
        return;
    }
    try {
        await busy(editForm.querySelector('button[type=submit]'),
            api({ a: 'edit', id: openId, title, project: editForm.elements.project.value }, true));
        toast('Card saved');
        showCard(true);
        refresh();
    } catch (err) {
        toastError(err);
    }
});

noteForm.elements.body.addEventListener('input', () => { noteForm.querySelector('.field-error').hidden = true; });

noteForm.addEventListener('submit', async e => {
    e.preventDefault();
    const body = noteForm.elements.body.value;
    const error = noteForm.querySelector('.field-error');
    if (body.trim() === '') {
        error.hidden = false;
        toast(error.textContent, { kind: 'error' });
        return;
    }
    try {
        await busy(noteForm.querySelector('button[type=submit]'), api({ a: 'note', id: openId, body }, true));
        noteForm.elements.body.value = '';
        toast('Note added');
        showCard(false);
        refresh();
    } catch (err) {
        toastError(err);
    }
});

document.getElementById('plan-btn').addEventListener('click', () => {
    if (openCard) openLink(planLink(openCard));
});

// Delete is held back for DELETE_MS behind an Undo toast; the request is only sent once that ends,
// so Undo (or closing the window) keeps the card.
const DELETE_MS = 5000;

async function deleteCard(id) {
    id = String(id);
    const c = cards.get(id);
    const ok = await confirmBox({
        title: `Delete card #${id}?`,
        body: c ? `"${c.title}" and its notes will be removed.` : 'The card and its notes will be removed.',
        ok: 'Delete',
        danger: true,
    });
    if (!ok || pendingDeletes.has(id)) return;
    pendingDeletes.add(id);
    if (openId === id) closePanel();
    board.querySelector(`.card[data-id="${CSS.escape(id)}"]`)?.remove();
    load(); // re-render so counts and empty hints match
    const timer = setTimeout(async () => {
        try {
            await api({ a: 'delete', id }, true);
        } catch (err) {
            toastError(err);
        }
        pendingDeletes.delete(id);
        refresh();
    }, DELETE_MS);
    toast('Card deleted', {
        ms: DELETE_MS,
        action: { label: 'Undo', run: () => { clearTimeout(timer); pendingDeletes.delete(id); load(); } },
    });
}

document.getElementById('delete').addEventListener('click', () => deleteCard(openId));

// --- Activity ---

function eventText(e) {
    const n = `#${e.card_id}`;
    switch (e.action) {
        case 'added': return `added ${n} to ${e.detail}`;
        case 'moved': {
            const [from, to] = e.detail.split(' → ');
            return from === to ? `reordered ${n} in ${LABELS[to] || to}` : `moved ${n} to ${LABELS[to] || to}`;
        }
        case 'blocked': return `blocked ${n}: ${e.detail}`;
        case 'unblocked': return `unblocked ${n}`;
        case 'edited': return `edited ${n}`;
        case 'deleted': return `deleted ${n}`;
        case 'note': return `added a note to ${n}`;
        case 'plan': return `set the plan for ${n}`;
        default: return `${e.action} ${n}`;
    }
}

function eventEl(e) {
    const li = el('li', 'event');
    const line = el('div', '');
    line.append(el('span', `badge ${e.author}`, who(e.author)), ` ${eventText(e)}`);
    const time = el('time', '', ` · ${ago(e.created_at)}`);
    time.title = e.created_at;
    line.append(time);
    li.append(line, el('div', 'event-title', e.card_title));
    return li;
}

async function loadEvents() {
    try {
        const { events } = await api({ a: 'events' });
        activity.querySelector('.events').replaceChildren(...(events.length ? events.map(eventEl)
            : [el('li', 'empty', 'Nothing yet.')]));
    } catch (e) {
        showError(e);
    }
}

// Claude's writes since the last poll, one toast per card. The first call only records where the log stands.
async function pollClaude() {
    if (eventsPolling) return;
    eventsPolling = true;
    try {
        if (lastEventId === null) {
            const { events } = await api({ a: 'events' });
            lastEventId = events.length ? events[0].id : 0;
            return;
        }
        const { events } = await api({ a: 'events', after: lastEventId });
        if (!events.length) return;
        lastEventId = events[events.length - 1].id;
        const byCard = Map.groupBy(events.filter(e => e.author === 'claude'), e => e.card_id);
        for (const [id, list] of byCard) {
            const e = list[0];
            toast(list.length > 1 ? `Claude made ${list.length} changes on #${id}`
                : e.action === 'blocked' ? `Claude needs you on #${id}: ${e.detail}`
                : `Claude ${eventText(e)}`, { kind: 'claude' });
        }
    } catch (e) {
        showError(e);
    } finally {
        eventsPolling = false;
    }
}

function toggleActivity(open = activity.hidden) {
    activity.hidden = !open;
    activityToggle.setAttribute('aria-expanded', open);
    if (open) loadEvents();
}

activityToggle.addEventListener('click', () => toggleActivity());

function refresh() {
    load();
    if (!activity.hidden) loadEvents();
}

load();
pollClaude();
setInterval(() => {
    pollClaude();
    if (dragging) return;
    refresh();
    if (openId !== null) showCard(false);
}, 10000);
