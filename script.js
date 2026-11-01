/**
 * navinormi: keyboard shortcuts and quick jump dialog
 *
 *   +    next provision
 *   -    previous provision
 *   i    table of contents
 *   Backspace, b, r, z  back to the regular wiki page visited before
 *   v, f  forward again after going back
 *   0-9  open the quick jump dialog ("5 AufenthG" + Enter opens § 5 AufenthG)
 *
 * Letters work regardless of case.
 */
(function () {
    var MAX_RESULTS = 100;

    var config = (window.JSINFO && JSINFO.navinormi) || {lang: {}};
    var lang = config.lang || {};

    /** true while the user types somewhere (search box, forms, editor) */
    function isTyping(target) {
        return target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
    }

    /* -------------------------------------------------------------- history */

    var HISTORY_KEY = 'navinormi_history';
    var HISTORY_MAX = 50;

    /** current URL without anchor */
    var here = window.location.href.split('#')[0];

    /** regular wiki page: no edit, diff, revisions, old revision, search, admin, … */
    function isRegularWikiPage() {
        var params = new URLSearchParams(window.location.search);
        var action = (window.JSINFO && JSINFO.ACT) || params.get('do') || 'show';
        return action === 'show' && !params.has('rev') && !params.has('at');
    }

    /**
     * regular pages visited in this tab, oldest first, and the position of
     * the current one; pages after it are reachable by going forward
     */
    function readHistory() {
        var stored = null;
        try {
            stored = JSON.parse(sessionStorage.getItem(HISTORY_KEY));
        } catch (error) {
            // unreadable: start over
        }
        if (Array.isArray(stored)) {
            // earlier format: plain list, current page last
            return {pages: stored, pos: stored.length - 1};
        }
        if (stored && Array.isArray(stored.pages) && typeof stored.pos === 'number') {
            return stored;
        }
        return {pages: [], pos: -1};
    }

    function writeHistory(history) {
        var excess = Math.max(0, history.pages.length - HISTORY_MAX);
        try {
            sessionStorage.setItem(HISTORY_KEY, JSON.stringify({
                pages: history.pages.slice(excess),
                pos: Math.max(-1, history.pos - excess)
            }));
        } catch (error) {
            // no storage (private mode): going back and forward stays unavailable
        }
    }

    /** index of the entry for here nearest to the current position, -1 if none */
    function findNearest(history) {
        for (var distance = 0; distance < history.pages.length; distance++) {
            if (history.pages[history.pos - distance] === here) {
                return history.pos - distance;
            }
            if (history.pages[history.pos + distance] === here) {
                return history.pos + distance;
            }
        }
        return -1;
    }

    /** true if the page was reached with the browser's back or forward button */
    function isHistoryTraversal() {
        var entries = window.performance && performance.getEntriesByType
            ? performance.getEntriesByType('navigation') : [];
        return entries.length > 0 && entries[0].type === 'back_forward';
    }

    /**
     * Record the current page. Reloads and pages reached with the back and
     * forward shortcuts are already at the current position. The browser's
     * back and forward buttons move the position to the page they show, so
     * the shortcuts and the buttons agree on what "before" and "after" mean.
     * Any other new page drops the pages after the current one, as in the
     * browser.
     */
    function recordVisit(traversal) {
        if (!isRegularWikiPage()) {
            return;
        }
        var history = readHistory();
        if (history.pages[history.pos] === here) {
            return;
        }
        var index = traversal ? findNearest(history) : -1;
        if (index !== -1) {
            history.pos = index;
        } else {
            history.pages = history.pages.slice(0, history.pos + 1);
            history.pages.push(here);
            history.pos = history.pages.length - 1;
        }
        writeHistory(history);
    }

    recordVisit(isHistoryTraversal());

    // pages restored from the back/forward cache do not run this script again
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            recordVisit(true);
        }
    });

    /**
     * go to the regular page visited before (step -1) or after (step 1) this
     * one; false if there is none
     */
    function goTo(step) {
        var history = readHistory();
        var target = history.pos + step;
        // on an edit, diff, … page, going back returns to the page it belongs to
        if (step < 0 && history.pages[history.pos] !== here) {
            target = history.pos;
        }
        if (target < 0 || target >= history.pages.length) {
            return false;
        }
        // the target becomes the current position, so it is not recorded again
        // on arrival; replace() keeps the browser's history from growing with
        // every step
        history.pos = target;
        writeHistory(history);
        window.location.replace(history.pages[target]);
        return true;
    }

    /* ------------------------------------------------------------ shortcuts */

    document.addEventListener('keydown', function (event) {
        if (event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey || isTyping(event.target)) {
            return;
        }

        var key = event.key.toLowerCase();
        if (key === 'backspace' || key === 'b' || key === 'r' || key === 'z') {
            if (goTo(-1)) {
                event.preventDefault();
            }
            return;
        }
        if (key === 'v' || key === 'f') {
            if (goTo(1)) {
                event.preventDefault();
            }
            return;
        }

        var jumpStart = /^[0-9]$/.test(key) ? key
            : key === '§' ? '§ '
            : key === 'a' ? 'Art. '
            : key === 's' || key === '/' ? ''
            : null;
        if (jumpStart !== null) {
            event.preventDefault();
            openJump(jumpStart);
            return;
        }

        var selectors = {'+': 'a.navinormi_next', '-': 'a.navinormi_prev', 'i': 'a.navinormi_toc'};
        var selector = selectors[key];
        if (!selector) {
            return;
        }
        var link = document.querySelector('nav.plugin_navinormi ' + selector);
        if (!link) {
            return;
        }
        event.preventDefault();
        window.location.href = link.href;
    });

    /* ---------------------------------------------------------------- index */

    var index = null;      // {regs, entries} once loaded
    var indexRequest = null;

    /** lowercase, punctuation as spaces: "AMM-VO" -> "amm vo" */
    function normalize(text) {
        return (' ' + text.toLowerCase().replace(/[^0-9a-zäöüß]+/g, ' ') + ' ').replace(/ +/g, ' ');
    }

    function loadIndex(callback) {
        if (index) {
            callback();
            return;
        }
        if (!indexRequest) {
            indexRequest = fetch(DOKU_BASE + 'lib/exe/ajax.php?call=plugin_navinormi', {credentials: 'same-origin'})
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    var current = data.regs.findIndex(function (reg) { return reg.slug === config.reg; });
                    data.regs.forEach(function (reg, r) {
                        // the current page's regulation first, then normi's order
                        reg.rank = r === current ? -1 : r;
                        reg.text = normalize(reg.syn.join(' '));
                    });
                    data.entries.forEach(function (entry, i) {
                        entry.pos = i;  // TOC order within a regulation
                        if (entry.t) {
                            entry.h = lang.toc || '';
                        }
                        entry.num = parseInt(entry.a, 10);
                        entry.labelText = normalize(entry.l) + data.regs[entry.r].text;
                        entry.headingText = normalize(entry.h);
                    });
                    index = data;
                })
                .catch(function () {
                    indexRequest = null;  // retry when the dialog is opened again
                });
        }
        indexRequest.then(callback);
    }

    /* --------------------------------------------------------------- search */

    /**
     * Ranked matches for a query like "5", "50 amm", "§ 5 aufenthg" or "ausreise".
     *
     * Order: exact number before number prefix (5 before 5a, 50), matches of the
     * regulation name before matches in the heading only, current regulation first
     * (for prefix matches only within the same number).
     */
    function search(query) {
        // "§" only finds paragraphs, "Art."/"Artikel" only articles
        var kind = null;
        var words = normalize(query).trim().split(' ').filter(function (word) {
            if (word === 'art' || word === 'artikel') {
                kind = 'Art.';
                return false;
            }
            return word !== '';
        });
        if (query.indexOf('§') !== -1) {
            kind = kind === null ? '§' : null;
        }
        var number = null;
        var texts = [];
        words.forEach(function (word) {
            if (number === null && /^[0-9]+[a-z]?$/.test(word)) {
                number = word;
            } else {
                texts.push(' ' + word);
            }
        });

        var results = [];
        index.entries.forEach(function (entry) {
            // start pages, recitals and annexes have no number
            if (!entry.a ? number !== null || kind !== null : kind !== null && entry.l.indexOf(kind) !== 0) {
                return;
            }
            var numberGroup = 2;
            if (number !== null) {
                if (entry.a === number) {
                    numberGroup = 0;
                } else if (entry.a.indexOf(number) === 0) {
                    numberGroup = 1;
                } else {
                    return;
                }
            }
            var inHeadingOnly = 0;
            for (var i = 0; i < texts.length; i++) {
                if (entry.labelText.indexOf(texts[i]) !== -1) {
                    continue;
                }
                if (entry.headingText.indexOf(texts[i]) === -1) {
                    return;
                }
                inHeadingOnly = 1;
            }
            var rank = index.regs[entry.r].rank;
            // exact numbers grouped by regulation; prefix matches by number first ("§ 50 AufenthG, § 50 AsylG, § 51 …");
            // without a number, tables of contents first, then everything in TOC order
            var key = numberGroup === 1
                ? [numberGroup, inHeadingOnly, entry.num, entry.a, rank]
                : numberGroup === 0
                    ? [numberGroup, inHeadingOnly, rank, entry.num, entry.a]
                    : [numberGroup, inHeadingOnly, entry.t ? 0 : 1, rank, entry.pos];
            results.push({entry: entry, key: key});
        });

        results.sort(function (a, b) {
            for (var i = 0; i < a.key.length; i++) {
                if (a.key[i] < b.key[i]) return -1;
                if (a.key[i] > b.key[i]) return 1;
            }
            return 0;
        });
        return results.slice(0, MAX_RESULTS).map(function (result) { return result.entry; });
    }

    /* --------------------------------------------------------------- dialog */

    var dialog = null;     // {backdrop, input, list, matches, selected}

    function element(tag, className, text) {
        var el = document.createElement(tag);
        if (className) el.className = className;
        if (text) el.textContent = text;
        return el;
    }

    function openJump(initial) {
        if (!dialog) {
            createDialog();
        }
        dialog.input.value = initial;
        dialog.backdrop.hidden = false;
        dialog.input.focus();
        dialog.input.setSelectionRange(initial.length, initial.length);
        showMessage(lang.loading);
        loadIndex(update);
    }

    function closeJump() {
        dialog.backdrop.hidden = true;
    }

    function createDialog() {
        var backdrop = element('div', 'navinormi_jump_backdrop');
        var box = element('div', 'navinormi_jump');
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-label', lang.aria || '');

        var input = element('input');
        input.type = 'text';
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.placeholder = lang.placeholder || '';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', 'navinormi_jump_list');
        input.setAttribute('aria-expanded', 'true');

        var list = element('ul');
        list.id = 'navinormi_jump_list';
        list.setAttribute('role', 'listbox');

        box.appendChild(input);
        box.appendChild(list);
        box.appendChild(element('div', 'navinormi_jump_hint', lang.hint));
        backdrop.appendChild(box);
        document.body.appendChild(backdrop);

        dialog = {backdrop: backdrop, input: input, list: list, matches: [], selected: 0};

        input.addEventListener('input', function () {
            if (index) update();
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeJump();
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                select(dialog.selected + (event.key === 'ArrowDown' ? 1 : -1));
            } else if (event.key === 'Enter') {
                event.preventDefault();
                if (dialog.matches[dialog.selected]) {
                    window.location.href = dialog.matches[dialog.selected].u;
                }
            }
        });
        backdrop.addEventListener('mousedown', function (event) {
            if (event.target === backdrop) closeJump();
        });
    }

    function showMessage(text) {
        dialog.matches = [];
        dialog.list.textContent = '';
        dialog.list.appendChild(element('li', 'navinormi_jump_message', text));
        dialog.input.removeAttribute('aria-activedescendant');
    }

    function update() {
        var query = dialog.input.value.trim();
        if (query === '') {
            showMessage(lang.placeholder);
            return;
        }
        // last option, reached with ↑ from the first: hand the query to DokuWiki's full text search
        var matches = search(query).concat({
            l: lang.fulltext,
            h: query,
            u: DOKU_BASE + 'doku.php?id=' + encodeURIComponent((window.JSINFO && JSINFO.id) || '')
                + '&do=search&q=' + encodeURIComponent(query),
            fulltext: true
        });
        dialog.matches = matches;
        dialog.list.textContent = '';
        matches.forEach(function (entry, i) {
            var item = element('li', entry.fulltext ? 'navinormi_jump_fulltext' : '');
            item.id = 'navinormi_jump_' + i;
            item.setAttribute('role', 'option');
            item.appendChild(element('span', 'navinormi_jump_label', entry.l));
            if (entry.h) {
                item.appendChild(element('span', 'navinormi_jump_heading', entry.h));
            }
            item.addEventListener('mousedown', function (event) {
                event.preventDefault();
                window.location.href = entry.u;
            });
            item.addEventListener('mousemove', function () {
                if (dialog.selected !== i) select(i);
            });
            dialog.list.appendChild(item);
        });
        select(0);
    }

    function select(i) {
        var count = dialog.matches.length;
        if (!count) return;
        dialog.selected = (i + count) % count;
        Array.prototype.forEach.call(dialog.list.children, function (item, j) {
            item.setAttribute('aria-selected', j === dialog.selected ? 'true' : 'false');
        });
        var item = dialog.list.children[dialog.selected];
        dialog.input.setAttribute('aria-activedescendant', item.id);
        item.scrollIntoView({block: 'nearest'});
    }
})();
