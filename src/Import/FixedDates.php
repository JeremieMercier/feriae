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

/**
 * Tells the holidays falling on the same date every year (New Year's Day,
 * Christmas...) from the moving ones (Easter Monday, Ascension...), so
 * that the former can become recurrent closing periods.
 *
 * Sources do not carry that information: it is inferred by computing the
 * holidays on the years around the import and comparing the month and
 * day. A holiday observed on another day when it falls on a weekend (New
 * Year's Day in the United Kingdom) moves on some years and is rightly
 * not considered fixed.
 */
final class FixedDates
{
    /**
     * Keys of the holidays fixed over the years to import, plus one year
     * on each side so that a single year is still compared with others.
     *
     * @param list<int> $years
     *
     * @return array<string, true>
     */
    public static function detect(HolidaySource $source, string $region_code, array $years, string $locale): array
    {
        if ($years === []) {
            return [];
        }

        $window = range(min($years) - 1, max($years) + 1);
        $dates  = [];
        foreach ($window as $year) {
            foreach ($source->getHolidays($region_code, $year, $locale) as $entry) {
                $dates[$entry->key][] = $entry->date->format('m-d');
            }
        }

        $fixed = [];
        foreach ($dates as $key => $month_days) {
            if (count($month_days) === count($window) && count(array_unique($month_days)) === 1) {
                $fixed[$key] = true;
            }
        }

        return $fixed;
    }
}
