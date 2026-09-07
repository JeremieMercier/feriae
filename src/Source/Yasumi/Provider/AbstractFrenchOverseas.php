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

namespace GlpiPlugin\Feriae\Source\Yasumi\Provider;

use Safe\DateTime;
use Yasumi\Holiday;
use Yasumi\Provider\France;
use Yasumi\Provider\DateTimeZoneFactory;

/**
 * Base class for the French overseas departments and collectivities, which
 * observe the national holidays plus their own local ones (most notably
 * the local Abolition of Slavery day).
 *
 * Dates cross-checked against the official French open data
 * (https://calendrier.api.gouv.fr, Licence Ouverte) and the MIT licensed
 * spatie/holidays France provider.
 */
abstract class AbstractFrenchOverseas extends France
{
    /** Local IANA timezone of the territory. */
    abstract protected function getLocalTimezone(): string;

    /** Add the territory specific holidays; called after the national ones. */
    abstract protected function addLocalHolidays(): void;

    public function initialize(): void
    {
        parent::initialize();
        $this->timezone = $this->getLocalTimezone();
        $this->addLocalHolidays();
    }

    public function getSources(): array
    {
        return array_merge(parent::getSources(), [
            'https://calendrier.api.gouv.fr/jours-feries/',
            'https://fr.wikipedia.org/wiki/F%C3%AAtes_et_jours_f%C3%A9ri%C3%A9s_en_France#Outre-mer',
        ]);
    }

    /** Abolition of Slavery is commemorated on a different date in each territory. */
    protected function addAbolitionOfSlavery(int $month, int $day, int $since = 1983): void
    {
        $this->addFixedHoliday('abolitionOfSlavery', [
            'en' => 'Abolition of Slavery',
            'fr' => "Abolition de l'esclavage",
        ], $month, $day, $since);
    }

    /** @param array<string, string> $names */
    protected function addFixedHoliday(string $key, array $names, int $month, int $day, int $since = 1000, string $type = Holiday::TYPE_OFFICIAL): void
    {
        if ($this->year < $since) {
            return;
        }

        $this->addHoliday(new Holiday(
            $key,
            $names,
            new DateTime(sprintf('%d-%02d-%02d', $this->year, $month, $day), DateTimeZoneFactory::getDateTimeZone($this->timezone)),
            $this->locale,
            $type,
        ));
    }
}
