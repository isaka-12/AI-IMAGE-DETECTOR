(() => {
    'use strict';

    const MAX = 10 * 1024 * 1024;
    const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    // level from the server => badge text, colour tone and table filter group
    const LEVELS = {
        ai: { text: 'AI declared', tone: 'ai', group: 'ai' },
        likely: { text: 'Likely AI', tone: 'likely', group: 'likely' },
        hint: { text: 'Weak hints', tone: 'none', group: 'inconclusive' },
        none: { text: 'Inconclusive', tone: 'none', group: 'inconclusive' },
        camera: { text: 'Camera data', tone: 'real', group: 'camera' },
        real: { text: 'Likely real', tone: 'real', group: 'camera' },
        legacy: { text: 'Old scan', tone: 'none', group: 'inconclusive' },
    };
    const GROUPS = [
        { key: 'ai', label: 'AI declared', tone: 'ai' },
        { key: 'likely', label: 'Likely AI', tone: 'likely' },
        { key: 'camera', label: 'Real / camera', tone: 'real' },
        { key: 'inconclusive', label: 'Inconclusive', tone: 'none' },
    ];

    const $ = (id) => document.getElementById(id);
    const el = (tag, cls, text) => {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    };
    const NS = 'http://www.w3.org/2000/svg';
    const svg = (tag, attrs) => {
        const n = document.createElementNS(NS, tag);
        for (const k in attrs) n.setAttribute(k, attrs[k]);
        return n;
    };
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const input = $('file'), errEl = $('error');
    const idle = $('idle'), skeleton = $('skeleton'), result = $('result');
    const csrf = document.querySelector('meta[name="csrf"]').content;
    const levelOf = (level) => LEVELS[level] || LEVELS.none;
    let objectUrl = null;
    let busy = false;
    let scans = [];
    let total = 0;
    let filter = 'all';

    const fmtBytes = (n) => n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
    const share = (n, total) => total ? Math.round((n / total) * 100) : 0;
    const num = new Intl.NumberFormat();

    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    function ago(iso) {
        let v = (Date.parse(iso) - Date.now()) / 1000;
        const steps = [[60, 'second'], [60, 'minute'], [24, 'hour'], [7, 'day'], [4.35, 'week'], [12, 'month'], [Infinity, 'year']];
        for (const [size, unit] of steps) {
            if (Math.abs(v) < size) return rtf.format(Math.round(v), unit);
            v /= size;
        }
        return '';
    }

    function showError(msg) { errEl.textContent = msg; errEl.hidden = false; }
    function clearError() { errEl.hidden = true; errEl.textContent = ''; }

    function showStatus(msg) {
        const s = $('status');
        s.textContent = msg;
        s.hidden = false;
        clearTimeout(showStatus.timer);
        showStatus.timer = setTimeout(() => { s.hidden = true; }, 5000);
    }

    /** JSON or a readable error, even when Apache answers with an HTML error page */
    async function readJson(res) {
        const type = res.headers.get('content-type') || '';
        if (!type.includes('application/json')) {
            throw new Error(res.status === 413 ? 'That file is too large.' : 'Unexpected server response (' + res.status + ').');
        }
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.error || 'Request failed.');
        return data;
    }

    // ---------- thumbnails: kept in this browser only, never sent to the server ----------

    const THUMBS_KEY = 'scan-thumbs';
    const MAX_THUMBS = 60;
    let thumbs = {};
    try { thumbs = JSON.parse(localStorage.getItem(THUMBS_KEY)) || {}; } catch { thumbs = {}; }

    function saveThumbs() {
        // newest ids win when there are too many
        const keep = Object.keys(thumbs).map(Number).sort((a, b) => b - a).slice(0, MAX_THUMBS);
        thumbs = Object.fromEntries(keep.map((id) => [id, thumbs[id]]));
        try {
            localStorage.setItem(THUMBS_KEY, JSON.stringify(thumbs));
        } catch {
            // storage full or blocked: thumbnails are optional
        }
    }

    function makeThumb(url) {
        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => {
                const size = 96;
                const scale = Math.max(size / img.naturalWidth, size / img.naturalHeight);
                const w = img.naturalWidth * scale, h = img.naturalHeight * scale;
                const c = document.createElement('canvas');
                c.width = c.height = size;
                const ctx = c.getContext('2d');
                ctx.fillStyle = '#fff';   // transparent PNGs would turn black as JPEG
                ctx.fillRect(0, 0, size, size);
                ctx.drawImage(img, (size - w) / 2, (size - h) / 2, w, h);
                try { resolve(c.toDataURL('image/jpeg', 0.7)); } catch { resolve(null); }
            };
            img.onerror = () => resolve(null);
            img.src = url;
        });
    }

    // ---------- visual building blocks ----------

    function badge(level) {
        const l = levelOf(level);
        const b = el('span', 'badge ' + l.tone);
        b.append(el('span', 'dot'), document.createTextNode(l.text));
        return b;
    }

    /** Circular progress ring; score null = unknown (dashed track only) */
    function ring(score, tone, size, width) {
        const r = (size - width) / 2;
        const c = 2 * Math.PI * r;
        const mid = size / 2;
        const s = svg('svg', { viewBox: `0 0 ${size} ${size}`, width: size, height: size, class: 'ring ' + tone, 'aria-hidden': 'true' });
        s.appendChild(svg('circle', { cx: mid, cy: mid, r, class: 'ring-track', 'stroke-width': width }));
        // a zero-length arc with round caps would still draw a dot
        if (typeof score === 'number' && score > 0) {
            const arc = svg('circle', {
                cx: mid, cy: mid, r, class: 'ring-arc', 'stroke-width': width,
                'stroke-dasharray': `${reduceMotion ? (c * score) / 100 : 0} ${c}`,
                transform: `rotate(-90 ${mid} ${mid})`,
            });
            s.appendChild(arc);
            if (!reduceMotion) requestAnimationFrame(() => requestAnimationFrame(() => arc.setAttribute('stroke-dasharray', `${(c * score) / 100} ${c}`)));
        }
        return s;
    }

    function gauge(target, score, tone, caption) {
        const known = typeof score === 'number';
        const center = el('div', 'gauge-center');
        center.append(el('b', null, known ? score + '%' : 'N/A'), el('span', null, caption));
        target.className = 'gauge ' + (known ? tone : 'unknown');
        target.replaceChildren(ring(score, known ? tone : 'none', 176, 14), center);
    }

    const SOURCES = { pixel: 'Sightengine', rules: 'rules' };

    function scoreCell(score, tone, source) {
        const known = typeof score === 'number';
        const wrap = el('div', 'score ' + (known ? tone : 'unknown'));
        const text = el('div', 'score-text');
        text.append(el('span', 'score-val', known ? score + '%' : 'N/A'));
        if (known) text.append(el('span', 'score-src', SOURCES[source] || 'rules'));
        wrap.append(ring(score, known ? tone : 'none', 34, 4), text);
        return wrap;
    }

    function donut(target, parts) {
        const size = 168, width = 18, gap = 3;
        const r = (size - width) / 2, c = 2 * Math.PI * r, mid = size / 2;
        const total = parts.reduce((a, p) => a + p.value, 0);
        const s = svg('svg', { viewBox: `0 0 ${size} ${size}`, width: size, height: size, class: 'donut', 'aria-hidden': 'true' });
        s.appendChild(svg('circle', { cx: mid, cy: mid, r, class: 'ring-track', 'stroke-width': width }));
        let offset = 0;
        const visible = parts.filter((p) => p.value > 0);
        visible.forEach((p) => {
            const len = (c * p.value) / total;
            s.appendChild(svg('circle', {
                cx: mid, cy: mid, r, class: 'ring-arc ' + p.tone, 'stroke-width': width,
                'stroke-dasharray': `${visible.length > 1 ? Math.max(len - gap, 1) : len} ${c}`,
                'stroke-dashoffset': -offset,
                transform: `rotate(-90 ${mid} ${mid})`,
            }));
            offset += len;
        });
        target.replaceChildren(s);
    }

    // ---------- scanning ----------

    function setBusy(on) {
        busy = on;
        document.querySelectorAll('[data-action="upload"]').forEach((b) => { b.disabled = on; });
        $('dropzone').classList.toggle('busy', on);
        $('drop-busy').hidden = !on;
    }

    /** The upload area keeps showing the chosen image; clicking it again replaces it */
    function showInDropzone(file) {
        $('drop-img').src = objectUrl;
        $('drop-name').textContent = file.name;
        $('drop-size').textContent = fmtBytes(file.size);
        $('drop-empty').hidden = true;
        $('drop-filled').hidden = false;
        $('dropzone').classList.add('filled');
        $('dropzone').setAttribute('aria-label', 'Replace image (current: ' + file.name + ')');
    }

    function openPicker() {
        if (!busy) input.click();
    }

    async function scan(file) {
        if (busy) return;
        clearError();
        // the server checks the real type; an empty browser type is left for it to decide
        if (file.type && !TYPES.includes(file.type)) return showError('Only JPEG, PNG or WebP images are accepted.');
        if (file.size > MAX) return showError('That file is over 10 MB.');

        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = URL.createObjectURL(file);
        showInDropzone(file);
        $('fname').textContent = file.name;
        const thumb = makeThumb(objectUrl);

        const pixel = $('pixel');
        const usePixel = pixel.checked && !pixel.disabled;
        $('step-pixel').hidden = !usePixel;

        // right card: loader until the answer arrives
        idle.hidden = true;
        result.hidden = true;
        skeleton.hidden = false;
        // on narrow screens the result card sits below the upload card
        $('current').scrollIntoView({ block: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
        setBusy(true);

        const fd = new FormData();
        fd.append('image', file);
        fd.append('pixel', usePixel ? '1' : '0');
        try {
            const res = await fetch('scan.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf } });
            const d = await readJson(res);
            render(d);
            const t = await thumb;
            if (t && d.id) {
                thumbs[d.id] = t;
                saveThumbs();
            }
            loadHistory(true);
        } catch (e) {
            skeleton.hidden = true;
            idle.hidden = false;
            showError(e.message || 'Something went wrong. Try again.');
        } finally {
            setBusy(false);
            input.value = '';
        }
    }

    function render(d) {
        const tone = levelOf(d.level).tone;
        $('badge').replaceChildren(badge(d.level));
        const caption = typeof d.score !== 'number' ? 'No evidence' : d.score_source === 'pixel' ? 'Sightengine score' : 'AI score (rules)';
        gauge($('r-gauge'), d.score, tone, caption);
        $('verdict').textContent = d.verdict;
        $('r-file').textContent = d.mime.replace('image/', '').toUpperCase() + ' · ' + d.width + '×' + d.height + ' · ' + fmtBytes(d.bytes);
        renderMetadata(d.metadata || [], d.sha256);

        const list = $('r-evidence');
        list.replaceChildren();
        d.evidence.forEach((e) => {
            const li = el('li', 'ev ' + e.strength);
            const text = el('div');
            text.append(el('strong', null, e.label), el('small', null, e.source + ' · ' + e.detail));
            li.append(el('span', 'tag', e.strength), text);
            list.appendChild(li);
        });
        if (!d.evidence.length) list.appendChild(el('li', 'muted', 'Nothing found in the metadata.'));

        skeleton.hidden = true;
        result.hidden = false;
        $('verdict').focus({ preventScroll: true });
    }

    /** Every field the server read, grouped by source (File, EXIF, IPTC, XMP, PNG text, C2PA) */
    function renderMetadata(groups, sha256) {
        const box = $('r-meta');
        box.replaceChildren();
        let count = 0;
        groups.forEach((g) => {
            const rows = g.group === 'File' ? [...g.rows, ['SHA-256', sha256]] : g.rows;
            const section = el('section', 'meta-group');
            const dl = el('dl');
            rows.forEach(([k, v]) => {
                const dd = el('dd', k === 'SHA-256' ? 'mono' : null, v);
                dl.append(el('dt', null, k), dd);
            });
            count += rows.length;
            section.append(el('h4', null, g.group), dl);
            box.appendChild(section);
        });
        $('r-meta-count').textContent = String(count);
    }

    // ---------- statistics + history ----------

    async function loadHistory(flashNewest = false) {
        try {
            const d = await readJson(await fetch('history.php', { headers: { Accept: 'application/json' } }));
            scans = d.scans;
            total = d.stats.total;
            renderStats(d.stats);
            renderFilters();
            renderTable(flashNewest);
            renderClear();
            $('history-error').hidden = true;
        } catch (e) {
            $('history-error').textContent = e.message || 'Scan history is unavailable.';
            $('history-error').hidden = false;
        }
    }

    function renderStats(s) {
        $('t-total').textContent = num.format(s.total);
        $('s-total').textContent = num.format(s.total);
        $('s-today').textContent = num.format(s.today) + ' in the last 24 h';

        donut($('donut'), GROUPS.map((g) => ({ value: s[g.key], tone: g.tone })));
        const legend = $('legend');
        legend.replaceChildren();
        GROUPS.forEach((g) => {
            const pct = share(s[g.key], s.total);
            const li = el('li', g.tone);
            li.append(el('span', 'dot'), el('span', 'legend-label', g.label), el('b', null, num.format(s[g.key])),
                el('span', 'legend-pct', pct + '%'));
            legend.appendChild(li);
            $('s-' + g.key).textContent = num.format(s[g.key]);
            $('m-' + g.key).replaceChildren(ring(s.total ? pct : null, g.tone, 56, 6), el('span', null, pct + '%'));
        });
    }

    function renderFilters() {
        const box = $('filters');
        box.replaceChildren();
        box.hidden = scans.length === 0;
        const counts = { all: scans.length };
        scans.forEach((r) => {
            const g = levelOf(r.level).group;
            counts[g] = (counts[g] || 0) + 1;
        });
        [{ key: 'all', label: 'All' }, ...GROUPS].forEach((g) => {
            const b = el('button', 'tab');
            b.type = 'button';
            b.setAttribute('aria-pressed', String(filter === g.key));
            b.append(document.createTextNode(g.label), el('span', 'count', String(counts[g.key] || 0)));
            b.addEventListener('click', () => {
                filter = g.key;
                renderFilters();
                renderTable(false);
            });
            box.appendChild(b);
        });
    }

    function cell(label, ...children) {
        const td = el('td');
        td.dataset.label = label;
        td.append(...children);
        return td;
    }

    function imageCell(r, tone) {
        const file = el('div', 'file');
        let pic;
        if (r.mine && thumbs[r.id]) {
            pic = el('img', 'row-thumb');
            pic.src = thumbs[r.id];
            pic.alt = '';
        } else {
            pic = el('span', 'file-icon ' + tone, r.mime.replace('image/', '').toUpperCase().replace('JPEG', 'JPG'));
        }
        const name = el('span', 'file-name', r.filename || (r.mine ? 'Your image' : 'Scan #' + r.id));
        if (r.mine) name.appendChild(el('span', 'you', 'You'));
        const facts = [];
        if (r.width) facts.push(r.width + '×' + r.height);
        facts.push(fmtBytes(r.bytes));
        const text = el('div', 'file-text');
        text.append(name, el('span', 'sub', facts.join(' · ')));
        file.append(pic, text);
        return file;
    }

    function renderTable(flashNewest) {
        const body = $('history-body');
        body.replaceChildren();
        $('empty').hidden = scans.length > 0;
        $('table-wrap').hidden = scans.length === 0;

        const rows = scans.filter((r) => filter === 'all' || levelOf(r.level).group === filter);
        $('filter-empty').hidden = rows.length > 0;

        rows.forEach((r, i) => {
            const tone = levelOf(r.level).tone;
            const tr = el('tr', 'row');

            const time = el('time', null, ago(r.created_at));
            time.dateTime = r.created_at;
            time.title = new Date(r.created_at).toLocaleString();

            const detail = el('tr', 'detail');
            detail.hidden = true;
            const dtd = el('td');
            dtd.colSpan = 6;
            const box = el('div', 'detail-box');
            const ul = el('ul', 'signals');
            r.signals.forEach((s) => ul.appendChild(el('li', null, s)));
            box.append(el('p', 'verdict-line', r.verdict), ul, el('p', 'mono', 'SHA-256 ' + r.sha256));
            dtd.appendChild(box);
            detail.appendChild(dtd);

            let evidence;
            if (r.signals.length) {
                evidence = el('button', 'link', r.signals.length + (r.signals.length === 1 ? ' item' : ' items'));
                evidence.type = 'button';
                evidence.setAttribute('aria-expanded', 'false');
                evidence.addEventListener('click', () => {
                    detail.hidden = !detail.hidden;
                    evidence.setAttribute('aria-expanded', String(!detail.hidden));
                    tr.classList.toggle('open', !detail.hidden);
                });
            } else {
                evidence = el('span', 'muted', 'None');
            }

            tr.append(
                cell('Image', imageCell(r, tone)),
                cell('Result', badge(r.level)),
                cell('AI score', scoreCell(r.score, tone, r.score_source)),
                cell('Evidence', evidence),
                cell('When', time),
                cell('Actions', deleteButton(r, r.filename || 'scan #' + r.id)),
            );
            if (flashNewest && i === 0 && r.mine && filter === 'all') tr.classList.add('flash');
            body.append(tr, detail);
        });
    }

    // ---------- deleting: one scan, or the whole history ----------

    async function deleteScans(params) {
        const d = await readJson(await fetch('delete.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf },
            body: new URLSearchParams(params),
        }));
        return d.deleted;
    }

    function trashIcon() {
        const s = svg('svg', { viewBox: '0 0 24 24', 'aria-hidden': 'true' });
        s.appendChild(svg('path', { d: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3' }));
        return s;
    }

    /** Row button: the first click asks, the second click deletes */
    function deleteButton(r, name) {
        const b = el('button', 'icon-btn');
        b.type = 'button';
        b.setAttribute('aria-label', 'Delete ' + name);
        b.title = 'Delete this scan';
        const reset = () => {
            b.classList.remove('confirm');
            b.replaceChildren(trashIcon());
            b.setAttribute('aria-label', 'Delete ' + name);
        };
        reset();
        let timer;
        b.addEventListener('click', async () => {
            if (!b.classList.contains('confirm')) {
                b.classList.add('confirm');
                b.replaceChildren(document.createTextNode('Delete?'));
                b.setAttribute('aria-label', 'Confirm: delete ' + name);
                clearTimeout(timer);
                timer = setTimeout(reset, 4000);
                return;
            }
            clearTimeout(timer);
            b.disabled = true;
            try {
                await deleteScans({ id: r.id });
                delete thumbs[r.id];
                saveThumbs();
                showStatus('Deleted ' + name + '.');
                await loadHistory();
            } catch (e) {
                showError(e.message || 'Could not delete the scan.');
                b.disabled = false;
                reset();
            }
        });
        b.addEventListener('blur', () => { clearTimeout(timer); reset(); });
        return b;
    }

    function renderClear() {
        $('clear-box').hidden = total === 0;
        $('clear').hidden = false;
        $('clear-confirm').hidden = true;
    }

    $('clear').addEventListener('click', () => {
        $('clear-question').textContent = 'Delete all ' + total + (total === 1 ? ' scan?' : ' scans?') + ' This cannot be undone.';
        $('clear').hidden = true;
        $('clear-confirm').hidden = false;
        $('clear-no').focus();
    });

    $('clear-no').addEventListener('click', () => {
        renderClear();
        $('clear').focus();
    });

    $('clear-yes').addEventListener('click', async () => {
        const yes = $('clear-yes');
        yes.disabled = true;
        try {
            const n = await deleteScans({ all: '1' });
            thumbs = {};
            saveThumbs();
            showStatus('Deleted ' + n + (n === 1 ? ' scan.' : ' scans.'));
            filter = 'all';
            await loadHistory();
        } catch (e) {
            showError(e.message || 'Could not clear the history.');
            renderClear();
        } finally {
            yes.disabled = false;
        }
    });

    // ---------- events ----------

    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-action="upload"]')) openPicker();
        const nav = e.target.closest('.sidebar nav a');
        if (nav) document.querySelectorAll('.sidebar nav a').forEach((a) => a.classList.toggle('active', a === nav));
    });
    input.addEventListener('change', () => { if (input.files[0]) scan(input.files[0]); });

    // remember the pixel-analysis choice in this browser
    const pixelBox = $('pixel');
    try { pixelBox.checked = !pixelBox.disabled && localStorage.getItem('pixel') === '1'; } catch { /* storage blocked */ }
    pixelBox.addEventListener('change', () => {
        try { localStorage.setItem('pixel', pixelBox.checked ? '1' : '0'); } catch { /* storage blocked */ }
    });

    // drop a file anywhere on the page
    const mask = $('dropmask');
    let dragDepth = 0;
    const hasFiles = (e) => Array.from(e.dataTransfer ? e.dataTransfer.types : []).includes('Files');
    window.addEventListener('dragenter', (e) => {
        if (!hasFiles(e)) return;
        e.preventDefault();
        dragDepth++;
        mask.hidden = false;
    });
    window.addEventListener('dragover', (e) => { if (hasFiles(e)) e.preventDefault(); });
    window.addEventListener('dragleave', (e) => {
        if (!hasFiles(e)) return;
        dragDepth = Math.max(0, dragDepth - 1);
        if (!dragDepth) mask.hidden = true;
    });
    window.addEventListener('drop', (e) => {
        if (!hasFiles(e)) return;
        e.preventDefault();
        dragDepth = 0;
        mask.hidden = true;
        if (e.dataTransfer.files[0]) scan(e.dataTransfer.files[0]);
    });

    // keep "5 minutes ago" accurate while the page stays open
    setInterval(() => {
        document.querySelectorAll('time[datetime]').forEach((t) => { t.textContent = ago(t.dateTime); });
    }, 60000);

    gauge($('idle-gauge'), null, 'none', 'Awaiting image');
    loadHistory();
})();
