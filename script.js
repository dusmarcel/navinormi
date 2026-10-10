/**
 * navinormi: keyboard shortcuts and quick jump dialog
 *
 *   +    next provision
 *   -    previous provision
 *   i    table of contents
 *   0-9  open the quick jump dialog ("5 AufenthG" + Enter opens § 5 AufenthG)
 */
(function () {
    var MAX_RESULTS = 100;

    var config = (window.JSINFO && JSINFO.navinormi) || {lang: {}};
    var lang = config.lang || {};

    /** true while the user types somewhere (search box, forms, editor) */
    function isTyping(target) {
        return target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
    }

    document.addEventListener('keydown', function (event) {
        if (event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey || isTyping(event.target)) {
            return;
        }

        var jumpStart = /^[0-9]$/.test(event.key) ? event.key
            : event.key === '§' ? '§ '
            : event.key === 'a' || event.key === 'A' ? 'Art. '
            : null;
        if (jumpStart !== null) {
            event.preventDefault();
            openJump(jumpStart);
            return;
        }

        var selectors = {'+': 'a.navinormi_next', '-': 'a.navinormi_prev', 'i': 'a.navinormi_toc'};
        var selector = selectors[event.key];
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
                    data.entries.forEach(function (entry) {
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
            if (kind !== null && entry.l.indexOf(kind) !== 0) {
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
            // exact numbers grouped by regulation; prefix matches by number first ("§ 50 AufenthG, § 50 AsylG, § 51 …")
            var key = numberGroup === 1
                ? [numberGroup, inHeadingOnly, entry.num, entry.a, rank]
                : [numberGroup, inHeadingOnly, rank, entry.num, entry.a];
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
        var matches = search(query);
        if (!matches.length) {
            showMessage(lang.empty);
            return;
        }
        dialog.matches = matches;
        dialog.list.textContent = '';
        matches.forEach(function (entry, i) {
            var item = element('li');
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
