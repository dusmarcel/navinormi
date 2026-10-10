/**
 * navinormi: keyboard shortcuts for the navigation bar
 *
 *   +  next provision
 *   -  previous provision
 *   i  table of contents
 */
document.addEventListener('keydown', function (event) {
    if (event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey) {
        return;
    }

    // never while typing (search box, forms, editor)
    var target = event.target;
    if (target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName))) {
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
