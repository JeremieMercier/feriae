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

/**
 * Planning: keep the closing periods on top of the events of a day, so
 * that a closed day is visible at a glance even when the day is busy.
 *
 * FullCalendar orders the events of a day with `eventOrder`; GLPI leaves
 * the default (start, duration, all-day, title). A comparator putting the
 * plugin planning type first is prepended to that default.
 */
(() => {
    'use strict';

    const CLOSING_ITEMTYPE = 'GlpiPlugin\\Feriae\\PlanningClosedDay';

    const isClosingPeriod = (event) => event.itemtype === CLOSING_ITEMTYPE;

    const eventOrder = [
        (a, b) => Number(isClosingPeriod(b)) - Number(isClosingPeriod(a)),
        'start',
        '-duration',
        'allDay',
        'title',
    ];

    const applyOrder = () => {
        if (typeof GLPIPlanning === 'undefined' || !GLPIPlanning.calendar) {
            return false;
        }
        GLPIPlanning.calendar.setOption('eventOrder', eventOrder);
        return true;
    };

    document.addEventListener('DOMContentLoaded', () => {
        if (applyOrder() || typeof GLPIPlanning === 'undefined') {
            return;
        }

        // The calendar is not built yet: apply the order once it is
        const display = GLPIPlanning.display;
        GLPIPlanning.display = function (...args) {
            const result = display.apply(this, args);
            applyOrder();
            return result;
        };
    });
})();
