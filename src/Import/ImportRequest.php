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

use GlpiPlugin\Feriae\Source\HolidayType;

/**
 * What to import: a region of a source, for some years, into an entity,
 * optionally linked to calendars.
 */
final readonly class ImportRequest
{
    /**
     * @param list<int>         $years
     * @param list<HolidayType> $types        holiday types to import (others are skipped)
     * @param list<int>         $calendars_id calendars the closing periods are attached to
     * @param bool              $replace      first remove what a previous import of the same
     *                                        source / region / years created
     * @param bool              $skip_existing reuse a closing period of the entity that already
     *                                        covers the date (entered by hand or by another import)
     *                                        instead of creating a duplicate
     * @param bool              $fixed_as_recurrent import the holidays falling on the same date every
     *                                        year as recurrent closing periods, shared by the years
     *                                        of the import, rather than one dated period per year
     */
    public function __construct(
        public string $source,
        public string $region_code,
        public array $years,
        public array $types,
        public int $entities_id,
        public bool $is_recursive,
        public array $calendars_id,
        public bool $replace,
        public string $locale,
        public bool $skip_existing = true,
        public bool $fixed_as_recurrent = true,
    ) {}

    /**
     * Years an import may start from. Yasumi itself computes from 1000 to
     * 9999; the bounds keep the form and the requests within the years a
     * GLPI calendar may care about.
     */
    public const MIN_YEAR = 1970;

    public const MAX_YEAR = 2100;

    public static function isValidYear(int $year): bool
    {
        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }

    public function accepts(HolidayType $type): bool
    {
        return in_array($type, $this->types, true);
    }

    /**
     * The years from $from, $count of them at most, never beyond MAX_YEAR.
     *
     * @return list<int>
     */
    public static function yearsRange(int $from, int $count): array
    {
        $count = max(1, min($count, self::MAX_YEAR - $from + 1));
        return range($from, $from + $count - 1);
    }
}
