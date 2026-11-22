<?php
/**
 * Reusable client-side table filter (replaces the two copy-pasted scripts).
 *
 * Markup contract:
 *   <input type="search" data-table-search="#someTbody" data-empty-message="#someEmptyBox">
 *   <tbody id="someTbody"><tr data-searchable> ... </tr></tbody>
 *   <div id="someEmptyBox" hidden>No matches</div>
 *
 * Include this file once, just before footer.php.
 */
?>
<script>
(function () {
    document.querySelectorAll('[data-table-search]').forEach(function (input) {
        var body = document.querySelector(input.dataset.tableSearch);
        var empty = input.dataset.emptyMessage ? document.querySelector(input.dataset.emptyMessage) : null;
        if (!body) { return; }

        // Read each row's text once, not on every keystroke.
        var rows = Array.prototype.map.call(body.querySelectorAll('tr[data-searchable]'), function (row) {
            return { el: row, text: row.textContent.toLowerCase().replace(/\s+/g, ' ') };
        });

        var timer;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var query = input.value.toLowerCase().trim();
                var visible = 0;
                rows.forEach(function (row) {
                    var match = row.text.indexOf(query) !== -1;
                    row.el.hidden = !match;
                    if (match) { visible++; }
                });
                if (empty) { empty.hidden = visible !== 0; }
            }, 80);
        });
    });
})();
</script>