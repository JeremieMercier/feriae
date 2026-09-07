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

use GlpiPlugin\Feriae\Source\HolidaySource;
use GlpiPlugin\Feriae\Source\HolidayType;

/**
 * What an import would find: the holidays of a region for a year, counted
 * by type. Sources do not type holidays the same way from one country to
 * the next (Yasumi files most British days as bank holidays and most
 * Swiss days as "other"), so users need to see the counts before choosing
 * the types to import.
 */
final class ImportPreview
{
    /**
     * Counts and names by type, every type present even when empty, in
     * the order of {@see HolidayType::cases()}.
     *
     * @return array<string, array{label: string, count: int, names: list<string>}>
     */
    public static function forRegion(HolidaySource $source, string $region_code, int $year, string $locale): array
    {
        $preview = [];
        foreach (HolidayType::cases() as $type) {
            $preview[$type->value] = ['label' => $type->getLabel(), 'count' => 0, 'names' => []];
        }

        foreach ($source->getHolidays($region_code, $year, $locale) as $entry) {
            $preview[$entry->type->value]['count']++;
            $preview[$entry->type->value]['names'][] = $entry->name;
        }

        return $preview;
    }

    /**
     * "Bank holiday: 10, Other: 1", non-empty types only; empty string
     * when the region has no holiday at all.
     *
     * @param array<string, array{label: string, count: int, names: list<string>}> $preview
     */
    public static function describe(array $preview): string
    {
        $parts = [];
        foreach ($preview as $type) {
            if ($type['count'] > 0) {
                $parts[] = sprintf('%s: %d', $type['label'], $type['count']);
            }
        }

        return implode(', ', $parts);
    }
}
