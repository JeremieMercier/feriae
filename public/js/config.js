/**
 * -------------------------------------------------------------------------
 * Feriae plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Feriae plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/JeremieMercier/feriae
 * -------------------------------------------------------------------------
 */

/* global GLPIPlanning */

/* global bootstrap */

/**
 * Plugin configuration page (import form and lists of imports).
 *
 * - Preview: counts of holidays by type for the selected region and year,
 *   shown next to the type checkboxes as soon as the selection changes.
 * - Details modal: the days or the calendars of a previous import, loaded
 *   on demand.
 * - Child entities switch of a previous import: asks for confirmation,
 *   then submits its form.
 *
 * URLs and messages come from `data-feriae-*` attributes set by the
 * template, so that this file holds no translation.
 */
(() => {
    'use strict';

    const format = (text, ...values) => text.replace(/%(\d)\$d/g, (match, index) => values[index - 1]);

    const setupPreview = () => {
        const form = document.querySelector('form[data-feriae-preview-url]');
        if (form === null) {
            return;
        }

        const region   = form.querySelector('select[name="region_code"]');
        const year     = form.querySelector('input[name="year_from"]');
        const source   = form.querySelector('[name="source"]');
        const preview  = form.querySelector('#feriae-preview');
        const badges   = form.querySelectorAll('[data-feriae-count]');
        const boxes    = form.querySelectorAll('input[name="types[]"]');
        const messages = JSON.parse(form.dataset.feriaePreviewMessages);

        const render = (types) => {
            let total = 0;
            let selected = 0;
            badges.forEach((badge) => {
                const count = types[badge.dataset.feriaeCount]?.count ?? 0;
                badge.textContent = count;
                badge.hidden = false;
                total += count;
            });
            boxes.forEach((box) => {
                if (box.checked) {
                    selected += types[box.value]?.count ?? 0;
                }
            });
            preview.hidden = false;
            preview.classList.toggle('text-warning', total > 0 && selected === 0);
            if (total === 0) {
                preview.textContent = messages.nothing;
            } else if (selected === 0) {
                preview.textContent = messages.none;
            } else {
                preview.textContent = format(messages.selected, selected, total);
            }
        };

        let current = null;
        const refresh = async () => {
            if (!region.value) {
                badges.forEach((badge) => { badge.hidden = true; });
                preview.hidden = true;
                current = null;
                return;
            }
            const params = new URLSearchParams({
                source: source.value,
                region_code: region.value,
                year: year.value,
            });
            try {
                const response = await fetch(`${form.dataset.feriaePreviewUrl}?${params.toString()}`, {
                    headers: {'Accept': 'application/json'},
                });
                if (!response.ok) {
                    throw new Error(response.statusText);
                }
                current = (await response.json()).types;
                render(current);
            } catch {
                preview.hidden = true;
                current = null;
            }
        };

        // Select2 fires its change event through jQuery
        $(region).on('change', refresh);
        year.addEventListener('change', refresh);
        if (source instanceof HTMLSelectElement) {
            $(source).on('change', refresh);
        }
        boxes.forEach((box) => box.addEventListener('change', () => {
            if (current !== null) {
                render(current);
            }
        }));
        refresh();
    };

    const setupDetailsModal = () => {
        const modal = document.getElementById('feriae-modal');
        if (modal === null) {
            return;
        }

        const title   = modal.querySelector('[data-feriae-modal-body-title]');
        const body    = modal.querySelector('[data-feriae-modal-body]');
        const failure = modal.dataset.feriaeModalFailure;

        document.querySelectorAll('[data-feriae-modal-url]').forEach((button) => {
            button.addEventListener('click', async () => {
                title.textContent = button.dataset.feriaeModalTitle;
                body.innerHTML = '<div class="text-center p-3"><span class="spinner-border spinner-border-sm"></span></div>';
                bootstrap.Modal.getOrCreateInstance(modal).show();
                try {
                    const response = await fetch(button.dataset.feriaeModalUrl, {headers: {'Accept': 'text/html'}});
                    if (!response.ok) {
                        throw new Error(response.statusText);
                    }
                    body.innerHTML = await response.text();
                } catch {
                    body.innerHTML = '';
                    body.textContent = failure;
                }
            });
        });
    };

    // Child entities switch of a previous import: confirm, then submit
    const setupRecursiveSwitches = () => {
        document.querySelectorAll('input[data-feriae-recursive]').forEach((box) => {
            box.addEventListener('change', () => {
                const message = box.checked ? box.dataset.feriaeConfirmOn : box.dataset.feriaeConfirmOff;
                if (!window.confirm(message)) {
                    box.checked = !box.checked;
                    return;
                }
                box.form.requestSubmit();
            });
        });
    };

    const setup = () => {
        setupPreview();
        setupDetailsModal();
        setupRecursiveSwitches();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
