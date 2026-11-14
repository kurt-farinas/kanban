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
let filledSection = ''; // the section the edit form was last filled with; the 10s poll must not change it
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
    const n = ++apiSeq;
    let res, data;
    try {
        res = await fetch(post ? 'api.php' : 'api.php?' + new URLSearchParams(params),
            post ? { method: 'POST', body: new URLSearchParams(params) } : {});
        data = await res.json();
    } catch {
        // No response at all = Apache is down.
        throw goOffline(new Error(res ? `Server error (${res.status}).` : "Can't reach the server."), !res || res.status === 503, false, n);
    }
    if (!res.ok) throw goOffline(new Error(data.error || `Server error (${res.status}).`), res.status === 503, data.db_down, n);
    banner.hidden = true;
    if (params.a !== 'start_db') { // start_db answers without the database
        okSeq = Math.max(okSeq, n);
        if (n > failSeq) goOnline(); // a success sent before the newest failure says nothing about now
    }
    return data;
}

// --- Offline mode: banner with a countdown, a retry of a=list every RETRY_S, editing paused ---

const offlineBar = document.getElementById('offline');
const startDb = document.getElementById('start-db');
const RETRY_S = 5;
let offline = false;
let offlineRun = 0; // a new offline spell gets a new countdown; an old one stops
let apiSeq = 0;
let okSeq = 0; // newest request that reached the database
let failSeq = 0; // newest request that found the board unreachable

// Marks the error as an outage (so the red banner skips it) and enters offline mode; returns the error.
function goOffline(err, down, dbDown, n) {
    if (!down) return err;
    err.offline = true;
    if (n < okSeq) return err; // sent before a newer request got through (e.g. a slow connect timeout): stale
    failSeq = Math.max(failSeq, n);
    startDb.hidden = !dbDown; // the button only helps when Apache answered and MySQL did not
    if (offline) return err;
    offline = true;
    document.body.classList.add('offline');
    for (const b of document.querySelectorAll('[data-needs-db]')) b.disabled = true;
    closeMenu();
    banner.hidden = true;
    offlineBar.hidden = false;
    countdown(++offlineRun, RETRY_S);
    return err;
}

function countdown(run, left) {
    if (!offline || run !== offlineRun) return;
    const text = offlineBar.querySelector('.offline-text');
    if (left > 0) {
        text.textContent = `Retrying in ${left} s…`;
        setTimeout(countdown, 1000, run, left - 1);
    } else {
        text.textContent = 'Retrying…';
        load().then(() => countdown(run, RETRY_S)); // load() never rejects
    }
}

function goOnline() {
    if (!offline) return;
    offline = false;
    document.body.classList.remove('offline');
    for (const b of document.querySelectorAll('[data-needs-db]')) b.disabled = false;
    offlineBar.hidden = true;
    toast('Back online');
}

startDb.addEventListener('click', async () => {
    try {
        const { started } = await busy(startDb, api({ a: 'start_db' }, true), 'Starting…');
        toast(started ? 'Starting the database…' : 'The database is already starting');
    } catch (err) {
        if (!err.offline) toastError(err);
    }
});

// Background (poll) failures use the banner, outages the offline banner; the user's own actions report through toast().
function showError(e) {
    if (e.offline) return;
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
// xUndo: the ✕ runs the action too (for a toast that holds something back until it ends).
function toast(text, { kind = 'user', action, ms, xUndo = false } = {}) {
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
        t.dataset.action = '';
        const b = el('button', 'toast-action', action.label);
        b.type = 'button';
        b.addEventListener('click', () => { close(); action.run(); });
        t.append(b);
    }
    const x = el('button', 'close', '✕');
    x.type = 'button';
    x.setAttribute('aria-label', 'Dismiss');
    x.addEventListener('click', () => { close(); if (action && xUndo) action.run(); });
    t.append(x);
    toastBox.append(t);
    // Over the limit the oldest goes early, errors last; a toast with an Undo never does (the limit stretches).
    while (toastBox.children.length > TOAST_MAX) {
        const old = toastBox.querySelector('.toast:not(.toast-error):not([data-action])') || toastBox.querySelector('.toast:not([data-action])');
        if (!old) break;
        old.remove();
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
async function busy(button, promise, text = 'Saving…') {
    const label = button.textContent;
    button.disabled = true;
    button.textContent = text;
    try {
        return await promise;
    } finally {
        button.disabled = offline && 'needsDb' in button.dataset; // offline mode wins
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
        requestForm.elements.project.add(new Option(p.name, p.name));
    }
}

const KINDS = { do: 'Do', change: 'Change', remove: 'Remove' };

let projectInfo = new Map(); // name -> {name, color, folder, site_name, sections} from the last list

// Fills a section dropdown with that project's sections; hidden for a project without any.
function fillSections(select, project, value = '') {
    const names = [...(projectInfo.get(project)?.sections ?? [])];
    if (value && !names.includes(value)) names.push(value); // a poll hasn't caught up with a new section yet
    select.replaceChildren(new Option('No section', ''), ...names.map(n => new Option(n, n)));
    select.value = names.includes(value) ? value : '';
    select.hidden = names.length === 0;
}

// "2026-11-12" -> "12 Nov"
const shortDate = d => new Date(d + 'T00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });

// Live cards show as one-line rows; ids expanded by this viewer are kept in localStorage.
const LIVE_KEY = 'kanban.liveExpanded';
const liveExpanded = new Set((() => {
    try {
        const ids = JSON.parse(localStorage.getItem(LIVE_KEY));
        return Array.isArray(ids) ? ids.map(String) : [];
    } catch {
        return [];
    }
})());

function saveExpanded() {
    try { localStorage.setItem(LIVE_KEY, JSON.stringify([...liveExpanded])); } catch { /* private mode: in-memory only */ }
}

// Drops ids that are no longer Live. Under a project filter, cards of other projects are not in the list: keep theirs.
function pruneExpanded(list, all) {
    const live = new Set(list.filter(c => c.col === 'live').map(c => String(c.id)));
    const listed = new Set(list.map(c => String(c.id)));
    const before = liveExpanded.size;
    for (const id of liveExpanded) {
        if (!live.has(id) && (all || listed.has(id))) liveExpanded.delete(id);
    }
    if (liveExpanded.size !== before) saveExpanded();
}

// Rebuilds inside the same element, so a double-click's second click still lands on the card.
function toggleLive(card, fromButton = false) {
    const id = card.dataset.id;
    if (!liveExpanded.delete(id)) liveExpanded.add(id);
    saveExpanded();
    const fresh = cardEl(cards.get(id));
    card.className = fresh.className;
    card.title = fresh.title;
    card.replaceChildren(...fresh.childNodes);
    if (fromButton) card.querySelector('.live-toggle').focus();
}

function liveToggle(c, expanded) {
    const b = el('button', 'live-toggle', expanded ? '▴' : '▾');
    b.type = 'button';
    b.setAttribute('aria-expanded', String(expanded));
    b.setAttribute('aria-label', `${expanded ? 'Collapse' : 'Expand'} card #${c.id}`);
    b.title = expanded ? 'Collapse' : 'Expand';
    return b;
}

function actionsEl(c) {
    const actions = el('div', 'card-actions');
    const acts = [['edit', '✎', `Edit card #${c.id}`], ['delete', '🗑', `Delete card #${c.id}`]];
    if (c.has_chat) acts.unshift(['chat', '💬', `Open chat for card #${c.id}`]);
    for (const [act, icon, label] of acts) {
        const b = el('button', 'card-act', icon);
        b.type = 'button';
        b.dataset.act = act;
        b.setAttribute('aria-label', label);
        if (act === 'chat') b.title = 'Open chat';
        actions.append(b);
    }
    return actions;
}

function cardEl(c) {
    const live = c.col === 'live';
    const expanded = live && liveExpanded.has(String(c.id));
    const card = el('article', live && !expanded ? 'card compact' : 'card');
    if (c.has_chat) card.classList.add('has-chat'); // a third action button: the chevron moves over (style.css)
    card.draggable = true;
    card.tabIndex = 0;
    card.dataset.id = c.id;
    card.title = (live && !expanded ? 'Click to expand' : 'Click for details') + ' · drag to move · right-click for more';
    if (live && !expanded) {
        const dot = el('span', 'proj-dot');
        dot.dataset.color = c.color;
        dot.title = c.project;
        const line = el('span', 'title-line', c.title);
        line.title = c.title;
        // Two short lines: the title gets the whole top row, details sit underneath.
        const meta = el('div', 'compact-meta');
        meta.append(el('span', 'card-id', `#${c.id}`));
        if (c.section) {
            const s = el('span', 'pill section-pill', c.section);
            s.title = c.section;
            meta.append(s);
        }
        if (c.blocked) {
            const b = el('span', 'blocked-mini');
            b.title = `Blocked: ${c.blocked_reason || ''}`;
            meta.append(b);
        }
        if (c.live_at) meta.append(el('span', 'live-since', shortDate(c.live_at)));
        meta.append(actionsEl(c));
        card.append(dot, line, liveToggle(c, false), meta);
        return card;
    }
    if (c.stuck_days > 0) card.classList.add('stuck');
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
    if (c.section) tags.append(el('span', 'pill section-pill', c.section));
    if (c.request_kind) {
        const r = el('span', 'pill kind-tag', `${KINDS[c.request_kind]} · ${c.request_requester}`);
        r.dataset.kind = c.request_kind;
        r.title = 'Request card';
        tags.append(r);
    }
    if (c.col === 'live' && c.live_at) tags.append(el('span', 'live-since', `Live since ${shortDate(c.live_at)}`));
    if (c.blocked) {
        const b = el('span', 'pill blocked', 'Blocked');
        b.title = c.blocked_reason || '';
        tags.append(b);
    }
    if (c.stuck_days > 0) {
        const s = el('span', 'pill stuck', `Stuck ${c.stuck_days}d`);
        s.title = `No activity for ${c.stuck_days} days`;
        tags.append(s);
    }
    if (c.note_count > 0) {
        const n = el('span', 'note-count', `✎ ${c.note_count}`);
        n.title = c.note_count === 1 ? '1 note' : `${c.note_count} notes`;
        tags.append(n);
    }
    card.append(tags);
    if (live) card.append(liveToggle(c, true));
    card.append(actionsEl(c));
    return card;
}

function render(data) {
    fillProjects(data.projects);
    projectInfo = new Map(data.projects.map(p => [p.name, p]));
    cards = new Map(data.cards.map(c => [String(c.id), c]));
    pruneExpanded(data.cards, filter.value === '');
    const shown = data.cards.filter(c => !pendingDeletes.has(String(c.id)));
    for (const section of document.querySelectorAll('.column')) {
        const mine = shown.filter(c => c.col === section.dataset.col);
        section.querySelector('.cards').replaceChildren(...mine.map(cardEl));
        section.querySelector('.count').textContent = mine.length;
    }
    noticeBlocks(data.cards);
    noticeWorking(data.cards);
}

// "Claude started" toast when a card's working dot appears since the last render. The hook clears the dot
// at the end of every turn, so a card seen working in the last STARTED_QUIET_MS is a follow-up turn: no toast.
const STARTED_QUIET_MS = 5 * 60 * 1000;
const workingSeen = new Map(); // card id -> last render time it had the dot

function noticeWorking(list) {
    const now = new Set(list.filter(isWorking).map(c => c.id));
    const t = Date.now();
    for (const c of list) {
        const recent = t - (workingSeen.get(c.id) ?? 0) < STARTED_QUIET_MS;
        if (now.has(c.id)) workingSeen.set(c.id, t);
        if (workingIds && now.has(c.id) && !workingIds.has(c.id) && !recent) {
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
    return dragging || typing || pendingDeletes.size > 0 || opening > 0 || confirmDone || reportDialog.open;
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
        xUndo: true,
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
    if (offline) { e.preventDefault(); return; }
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
    if (card && e.target.closest('.card-actions, .live-toggle')) {
        card.draggable = false;
        document.addEventListener('pointerup', () => { card.draggable = true; }, { once: true });
    }
});

let expandedByClick = null; // compact card the last click expanded, to spot a double-click's second click

board.addEventListener('click', e => {
    const card = e.target.closest('.card');
    if (!card || dragged) return;
    const act = e.target.closest('.card-act');
    if (e.target.closest('.live-toggle')) toggleLive(card, true);
    else if (!act && card.classList.contains('compact')) {
        toggleLive(card);
        expandedByClick = card;
    } else if (!act && e.detail === 2 && expandedByClick === card) {
        // Second click of a double-click on a compact row: fold it back and edit, as a double-click does elsewhere.
        expandedByClick = null;
        toggleLive(card);
        openPanel(card.dataset.id, true);
    } else if (!act) openPanel(card.dataset.id);
    else if (act.dataset.act === 'edit') openPanel(card.dataset.id, true);
    else if (act.dataset.act === 'chat') openChat(card.dataset.id);
    else deleteCard(card.dataset.id);
});

board.addEventListener('dblclick', e => {
    const card = e.target.closest('.card');
    if (card && !e.target.closest('.card-actions, .live-toggle')) openPanel(card.dataset.id, true);
});

board.addEventListener('contextmenu', e => {
    const card = e.target.closest('.card');
    if (!card || offline) return;
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
        if (card.classList.contains('compact')) toggleLive(card);
        else openPanel(card.dataset.id);
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
    fillSections(addForm.elements.section, projectSelect.value);
    titleInput.focus();
});

projectSelect.addEventListener('change', () => fillSections(addForm.elements.section, projectSelect.value));

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
            api({ a: 'add', title: titleInput.value, project: projectSelect.value, section: addForm.elements.section.value }, true));
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

const chatBtn = document.getElementById('chat-btn');

function openPanel(id, editTitle = false) {
    openId = String(id);
    openCard = null;
    editForm.querySelector('.field-error').hidden = true;
    noteForm.querySelector('.field-error').hidden = true;
    noteForm.elements.body.value = '';
    // Blank the last card's details so they never show under the new heading while loading.
    document.getElementById('panel-heading').textContent = `Card #${id}`;
    editForm.elements.title.value = '';
    fillSections(editForm.elements.section, null);
    panel.querySelector('.panel-status').textContent = '';
    panel.querySelector('.working').hidden = true;
    chatBtn.hidden = true;
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
        if (id === openId && !e.offline) { // offline: the panel stays, read-only
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
        fillSections(editForm.elements.section, c.project, filledSection = c.section ?? '');
        if (select && !panel.hidden) editForm.elements.title.select();
        showChatBtn(c); // not on every poll: the lookup reads the app's chat files
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
    if (e.key !== 'Escape' || reportDialog.open) return; // the dialog closes itself
    if (openId !== null) closePanel();
    else if (!activity.hidden) toggleActivity(false);
    else if (!requestsBox.hidden) toggleRequests(false);
});

editForm.elements.title.addEventListener('input', () => { editForm.querySelector('.field-error').hidden = true; });
// Keeps the chosen name when the new project has one of the same name (e.g. Whole System).
editForm.elements.project.addEventListener('change', () =>
    fillSections(editForm.elements.section, editForm.elements.project.value, editForm.elements.section.value));

editForm.addEventListener('submit', async e => {
    e.preventDefault();
    const title = editForm.elements.title.value;
    const error = editForm.querySelector('.field-error');
    if (title.trim() === '') {
        error.hidden = false;
        toast(error.textContent, { kind: 'error' });
        return;
    }
    const f = editForm.elements;
    const id = openId;
    const before = filledSection;
    const sameProject = openCard && openCard.project === f.project.value;
    try {
        await busy(editForm.querySelector('button[type=submit]'), (async () => {
            await api({ a: 'edit', id, title, project: f.project.value }, true);
            // edit clears the section when the project changes, so compare with what the server now holds.
            const now = sameProject ? before : '';
            if (f.section.value !== now) await api({ a: 'section', id, section: f.section.value }, true);
        })());
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

// Looks the chat up on each click, so a chat archived since the board loaded gives the toast, not the wrong place.
async function openChat(id) {
    try {
        openLink((await api({ a: 'chat', id })).url);
        toast('Opening chat…');
    } catch (err) {
        if (!err.offline) toastError(err);
    }
}

// The panel's button names the chat; it stays hidden when the card has none.
async function showChatBtn(c) {
    let title = null;
    try {
        if (c.has_chat) title = (await api({ a: 'chat', id: c.id })).title;
    } catch {
        // 404: the chat was archived, deleted or not found, so there is nothing to open.
    }
    if (String(c.id) !== openId) return;
    chatBtn.textContent = title ? `Open chat · ${title}` : 'Open chat';
    chatBtn.title = chatBtn.textContent;
    chatBtn.hidden = title === null;
}

chatBtn.addEventListener('click', () => {
    if (openCard) openChat(openCard.id);
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
    const fromPanel = openId === id;
    if (fromPanel) closePanel();
    const gone = board.querySelector(`.card[data-id="${CSS.escape(id)}"]`);
    const next = gone?.nextElementSibling || gone?.previousElementSibling;
    const nextId = next?.dataset.id;
    const column = gone?.closest('.column');
    gone?.remove();
    load().then(() => { // re-render so counts and empty hints match; the render replaces the cards, so focus after it
        if (!fromPanel) return;
        const target = (nextId && board.querySelector(`.card[data-id="${CSS.escape(nextId)}"]`)) || column;
        if (!target) return;
        if (target === column) column.tabIndex = -1;
        target.focus();
    });
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
        case 'section': return e.detail === 'none' ? `cleared the section of ${n}` : `put ${n} under ${e.detail}`;
        case 'request': return `added a request (${e.detail})`;
        case 'request_deleted': return 'deleted a request';
        case 'live': return `${n} went live — ${e.detail}`;
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
        const byCard = new Map();
        for (const e of events) {
            if (e.author !== 'claude') continue;
            if (!byCard.has(e.card_id)) byCard.set(e.card_id, []);
            byCard.get(e.card_id).push(e);
        }
        for (const [id, list] of byCard) {
            const e = list[0];
            const blocked = list.findLast(b => b.action === 'blocked'); // a question outranks the rest
            toast(blocked ? `Claude needs you on #${id}: ${blocked.detail}${list.length > 1 ? ` (+${list.length - 1} more)` : ''}`
                : list.some(b => b.action === 'live') ? `Claude: #${id} is live`
                : list.length > 1 ? `Claude made ${list.length} changes on #${id}`
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
    if (open) {
        toggleRequests(false);
        loadEvents();
    }
}

activityToggle.addEventListener('click', () => toggleActivity());

// --- Requests inbox: what people asked for, each turned into one or more request cards ---

const requestsBox = document.getElementById('requests');
const requestsToggle = document.getElementById('requests-toggle');
const requestForm = document.getElementById('request-add');
const STATES = [['new', 'New'], ['in_progress', 'In progress'], ['done', 'Done']];

function requestEl(r) {
    const li = el('li', 'request');
    const head = el('div', 'request-head');
    const kind = el('span', 'pill kind-tag', KINDS[r.kind]);
    kind.dataset.kind = r.kind;
    const project = el('span', 'pill', r.project);
    project.dataset.color = r.color;
    head.append(kind, el('span', 'request-who', r.requester), project);
    if (r.cards_total > 0) head.append(el('span', 'request-progress', `${r.cards_live} of ${r.cards_total} Live`));
    li.append(head, el('p', 'request-body', r.body));

    const actions = el('div', 'request-actions');
    const make = el('button', 'secondary', 'Make card');
    make.type = 'button';
    make.dataset.needsDb = '';
    make.disabled = offline;
    const form = el('form', 'row');
    form.hidden = true;
    form.noValidate = true;
    const title = el('input', '');
    title.maxLength = 200;
    title.setAttribute('aria-label', 'Card title');
    const add = el('button', 'primary', 'Add');
    add.type = 'submit';
    add.dataset.needsDb = '';
    form.append(title, add);
    make.addEventListener('click', () => {
        form.hidden = false;
        make.hidden = true;
        title.value = r.body.slice(0, 200);
        title.select();
    });
    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (title.value.trim() === '') {
            toast('Enter a title.', { kind: 'error' });
            return;
        }
        try {
            const { id } = await busy(add, api({ a: 'request_card', id: r.id, title: title.value }, true));
            toast(`Card #${id} added to To do`);
            loadRequests();
            load();
        } catch (err) {
            toastError(err);
        }
    });
    actions.append(make, form);
    if (r.cards_total === 0) {
        const del = el('button', 'close', 'Delete');
        del.type = 'button';
        del.dataset.needsDb = '';
        del.disabled = offline;
        del.addEventListener('click', async () => {
            if (!(await confirmBox({ title: 'Delete this request?', body: `"${r.body}" from ${r.requester}`, ok: 'Delete', danger: true }))) return;
            try {
                await api({ a: 'request_delete', id: r.id }, true);
                toast('Request deleted');
            } catch (err) {
                toastError(err);
            }
            loadRequests();
        });
        actions.append(del);
    }
    li.append(actions);
    return li;
}

async function loadRequests() {
    try {
        const { requests, requesters } = await api({ a: 'requests' });
        document.getElementById('requesters').replaceChildren(...requesters.map(n => new Option(n)));
        const groups = STATES.map(([state, label]) => {
            const mine = requests.filter(r => r.state === state);
            if (!mine.length) return null;
            const g = el('div', 'request-group');
            const list = el('ul', 'requests-list');
            list.append(...mine.map(requestEl));
            g.append(el('h3', '', `${label} ${mine.length}`), list);
            return g;
        }).filter(Boolean);
        requestsBox.querySelector('.request-list').replaceChildren(...(groups.length ? groups : [el('p', 'empty', 'No requests yet.')]));
    } catch (e) {
        showError(e);
    }
}

function toggleRequests(open = requestsBox.hidden) {
    requestsBox.hidden = !open;
    requestsToggle.setAttribute('aria-expanded', open);
    if (open) {
        toggleActivity(false);
        loadRequests();
    }
}

requestsToggle.addEventListener('click', () => toggleRequests());

requestForm.addEventListener('input', () => { requestForm.querySelector('.field-error').hidden = true; });

requestForm.addEventListener('submit', async e => {
    e.preventDefault();
    const f = requestForm.elements;
    const error = requestForm.querySelector('.field-error');
    if (f.body.value.trim() === '' || f.requester.value.trim() === '') {
        error.hidden = false;
        toast(error.textContent, { kind: 'error' });
        return;
    }
    try {
        await busy(requestForm.querySelector('button[type=submit]'), api({
            a: 'request_add', body: f.body.value, requester: f.requester.value, project: f.project.value, kind: f.kind.value,
        }, true));
        f.body.value = '';
        toast('Request added');
        loadRequests();
    } catch (err) {
        toastError(err);
    }
});

// --- Make report: pick a website and dates, then open Claude in its folder with the request typed in ---

const reportDialog = document.getElementById('report');
const reportForm = reportDialog.querySelector('form');
const reportOk = reportForm.querySelector('button[type=submit]');
const BOARD_CMD = 'C:\\xampp\\php\\php.exe C:\\xampp\\htdocs\\kanban\\board.php';
const PREVIEW_MS = 300;
let previewSeq = 0; // only the newest preview answer counts
let previewTimer = null;

const localToday = () => new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD in local time

function schedulePreview(ms = PREVIEW_MS) {
    const my = ++previewSeq;
    reportOk.disabled = true;
    clearTimeout(previewTimer);
    previewTimer = setTimeout(() => preview(my), ms);
}

async function preview(my) {
    const f = reportForm.elements;
    const text = reportForm.querySelector('.report-preview');
    const warn = reportForm.querySelector('.report-warn');
    warn.hidden = true;
    const problem = !f.from.value ? 'Pick a start date.' : !f.to.value ? 'Pick an end date.'
        : f.from.value > f.to.value ? 'The start date is after the end date.' : null;
    if (problem) {
        text.textContent = problem;
        return;
    }
    text.textContent = 'Counting…';
    try {
        const r = await api({ a: 'report_preview', project: f.project.value, from: f.from.value, to: f.to.value });
        if (my !== previewSeq) return;
        text.textContent = `${r.done} done · ${r.underway} underway · ${r.no_section} with no section`;
        if (r.no_section > 0) {
            warn.textContent = `${r.no_section} ${r.no_section === 1 ? 'card has' : 'cards have'} no section; they'll go under Other changes.`;
            warn.hidden = false;
        }
        reportOk.disabled = r.done + r.underway === 0;
    } catch (err) {
        if (my === previewSeq) text.textContent = err.message;
    }
}

document.getElementById('report-open').addEventListener('click', () => {
    const sites = [...projectInfo.values()].filter(p => p.sections.length);
    if (!sites.length) {
        toast('No website has sections yet.', { kind: 'error' });
        return;
    }
    const f = reportForm.elements;
    f.project.replaceChildren(...sites.map(p => new Option(p.site_name || p.name, p.name)));
    if (sites.some(p => p.name === filter.value)) f.project.value = filter.value;
    f.from.value = '';
    f.to.value = localToday();
    schedulePreview(0);
    reportDialog.showModal();
    f.from.focus();
});

reportForm.addEventListener('input', () => schedulePreview());
document.getElementById('report-cancel').addEventListener('click', () => reportDialog.close());
reportDialog.addEventListener('click', e => { if (e.target === reportDialog) reportDialog.close(); }); // backdrop

reportForm.addEventListener('submit', e => {
    e.preventDefault();
    if (reportOk.disabled) return;
    const f = reportForm.elements;
    const p = projectInfo.get(f.project.value);
    const site = p.site_name || p.name;
    const prompt = `Make the president report for ${site} from ${f.from.value} to ${f.to.value} using the weekly-report-for-president skill. `
        + `Get the cards with: ${BOARD_CMD} report "${p.name}" ${f.from.value} ${f.to.value}`;
    reportDialog.close();
    openLink('claude://code/new?prompt=' + encodeURIComponent(prompt) + '&folder=' + encodeURIComponent(p.folder));
    toast(`Opening Claude for the ${site} report…`);
});

function refresh() {
    load();
    if (!activity.hidden) loadEvents();
    // A re-render would wipe a half-typed card title, so wait while focus is inside the inbox.
    if (!requestsBox.hidden && !requestsBox.contains(document.activeElement)) loadRequests();
}

load();
pollClaude();
setInterval(() => {
    if (offline) return; // the offline countdown does the retrying
    pollClaude();
    if (dragging) return;
    refresh();
    if (openId !== null) showCard(false);
}, 10000);
