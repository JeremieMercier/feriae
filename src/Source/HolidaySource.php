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

namespace GlpiPlugin\Feriae\Source;

/**
 * Contract every holiday data source must fulfil.
 *
 * The rest of the plugin (configuration, import into GLPI closing periods,
 * planning display, cron) only ever talks to this interface, so the
 * underlying library or web service can be swapped without touching them.
 */
interface HolidaySource
{
    /** Short machine key, stored in configuration (e.g. "yasumi"). */
    public function getKey(): string;

    /** Human readable name shown in the configuration UI. */
    public function getName(): string;

    /**
     * Every region this source can compute holidays for, countries and
     * subdivisions alike, indexed by region code. Country names follow
     * $locale; subdivision names, which intl does not provide, follow the
     * GLPI session language through the plugin translations.
     *
     * @return array<string, Region>
     */
    public function getRegions(string $locale): array;

    public function supportsRegion(string $regionCode): bool;

    /**
     * Holidays of a region for a given year, sorted by date.
     *
     * @return list<HolidayEntry>
     *
     * @throws UnknownRegionException
     */
    public function getHolidays(string $regionCode, int $year, string $locale): array;
}
