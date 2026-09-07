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

namespace GlpiPlugin\Feriae\Tests\Source;

use GlpiPlugin\Feriae\Source\HolidayEntry;
use GlpiPlugin\Feriae\Source\HolidayType;
use GlpiPlugin\Feriae\Source\Yasumi\YasumiSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The plugin providers for the French overseas territories. Expected dates
 * for 2026 come from the official open data at calendrier.api.gouv.fr.
 */
final class FrenchOverseasTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, string>}> */
    public static function territories(): iterable
    {
        yield 'Guadeloupe'       => ['FR-971', ['2026-05-27' => "Abolition de l'esclavage"]];
        yield 'Martinique'       => ['FR-972', ['2026-05-22' => "Abolition de l'esclavage"]];
        yield 'Guyane'           => ['FR-973', ['2026-06-10' => "Abolition de l'esclavage"]];
        yield 'La Réunion'       => ['FR-974', ['2026-12-20' => "Abolition de l'esclavage"]];
        yield 'Mayotte'          => ['FR-976', ['2026-04-27' => "Abolition de l'esclavage"]];
        yield 'Saint-Barthélemy' => ['FR-BL', ['2026-10-09' => "Abolition de l'esclavage"]];
        yield 'Saint-Martin'     => ['FR-MF', ['2026-05-27' => "Abolition de l'esclavage"]];
        yield 'Saint-Pierre-et-Miquelon' => ['FR-PM', []];
        yield 'Polynésie française' => ['FR-PF', ['2026-03-05' => 'Arrivée de l\'Évangile', '2026-06-29' => 'Fête de l\'autonomie']];
        yield 'Nouvelle-Calédonie'  => ['FR-NC', ['2026-09-24' => 'Fête de la citoyenneté']];
        yield 'Wallis-et-Futuna'    => ['FR-WF', ['2026-04-28' => 'Saint-Pierre-Chanel', '2026-07-29' => 'Fête du territoire']];
    }

    /** @param array<string, string> $local */
    #[DataProvider('territories')]
    public function testNationalHolidaysPlusLocalOnes(string $code, array $local): void
    {
        $source   = new YasumiSource();
        $holidays = $source->getHolidays($code, 2026, 'fr_FR');

        $this->assertCount(11 + count($local), $holidays);

        $by_date = [];
        foreach ($holidays as $holiday) {
            $by_date[$holiday->getDateString()] = $holiday;
        }

        // National ones are inherited from the France provider
        $this->assertSame('La Fête nationale', $by_date['2026-07-14']->name);
        $this->assertSame('Noël', $by_date['2026-12-25']->name);
        $this->assertArrayNotHasKey('2026-04-03', $by_date, 'Good Friday is specific to Alsace-Moselle');

        foreach ($local as $date => $name) {
            $this->assertArrayHasKey($date, $by_date, sprintf('%s expected on %s', $name, $date));
            $this->assertSame($name, $by_date[$date]->name);
            $this->assertSame(HolidayType::OFFICIAL, $by_date[$date]->type);
            $this->assertSame($code, $by_date[$date]->regionCode);
        }
    }

    public function testLocalNamesAreTranslatedInEnglish(): void
    {
        $holidays = (new YasumiSource())->getHolidays('FR-971', 2026, 'en_GB');
        $names    = array_map(static fn(HolidayEntry $h): string => $h->name, $holidays);
        $this->assertContains('Abolition of Slavery', $names);
    }

    public function testLocalHolidaysRespectTheirStartingYear(): void
    {
        $dates = array_map(
            static fn(HolidayEntry $h): string => $h->getDateString(),
            (new YasumiSource())->getHolidays('FR-971', 1970, 'fr_FR'),
        );
        $this->assertNotContains('1970-05-27', $dates, 'Abolition day was made a holiday in 1983');
    }

    public function testOverseasRegionsAreListedUnderFrance(): void
    {
        $regions = (new YasumiSource())->getRegions('fr_FR');
        foreach (self::territories() as [$code]) {
            $this->assertArrayHasKey($code, $regions);
            $this->assertSame('FR', $regions[$code]->parentCode);
            $this->assertSame('FR', $regions[$code]->countryCode);
        }
    }
}
