<?php

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

namespace GlpiPlugin\Feriae\Import;

/**
 * Outcome of an import, for the user message and the tests.
 */
final class ImportReport
{
    /** Holidays of the selected types found in the source, whatever became of them. */
    public int $found = 0;

    public int $created = 0;

    /** Existing closing periods whose name or date was refreshed. */
    public int $updated = 0;

    /** Already imported, nothing to do. */
    public int $unchanged = 0;

    /** Imported before but deleted by hand since: left alone. */
    public int $skipped = 0;

    /** Removed by the "replace" option before importing. */
    public int $removed = 0;

    /** Dates already covered by an existing closing period, reused rather than duplicated. */
    public int $duplicates = 0;

    /** New links between a closing period and a calendar. */
    public int $linked = 0;

    public function getSummary(): string
    {
        $parts = [
            sprintf(__('%d created', 'feriae'), $this->created),
            sprintf(__('%d updated', 'feriae'), $this->updated),
            sprintf(__('%d unchanged', 'feriae'), $this->unchanged),
        ];
        if ($this->skipped > 0) {
            $parts[] = sprintf(__('%d skipped (deleted by hand)', 'feriae'), $this->skipped);
        }

        if ($this->removed > 0) {
            $parts[] = sprintf(__('%d removed', 'feriae'), $this->removed);
        }

        if ($this->duplicates > 0) {
            $parts[] = sprintf(__('%d already covered by an existing close time', 'feriae'), $this->duplicates);
        }

        if ($this->linked > 0) {
            $parts[] = sprintf(__('%d calendar links', 'feriae'), $this->linked);
        }

        return implode(', ', $parts);
    }
}
