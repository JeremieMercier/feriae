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
use GlpiPlugin\Feriae\Source\HolidaySource;
use GlpiPlugin\Feriae\Source\HolidayType;
use GlpiPlugin\Feriae\Source\Region;
use GlpiPlugin\Feriae\Source\UnknownRegionException;
use GlpiPlugin\Feriae\Source\Yasumi\YasumiSource;
use PHPUnit\Framework\TestCase;
use Session;

final class YasumiSourceTest extends TestCase
{
    private YasumiSource $source;

    protected function setUp(): void
    {
        $this->source = new YasumiSource();
    }

    public function testImplementsTheSourceContract(): void
    {
        $this->assertInstanceOf(HolidaySource::class, $this->source);
        $this->assertSame('yasumi', $this->source->getKey());
    }

    public function testRegionsMixCountriesAndSubdivisions(): void
    {
        $regions = $this->source->getRegions('fr_FR');

        $this->assertArrayHasKey('FR', $regions);
        $this->assertArrayHasKey('FR-57', $regions);
        $this->assertArrayHasKey('FR-971', $regions);
        $this->assertArrayHasKey('DE-BY', $regions);
        $this->assertArrayNotHasKey('US-NYSE', $regions, 'A stock exchange is not a region');

        $france = $regions['FR'];
        $this->assertInstanceOf(Region::class, $france);
        $this->assertTrue($france->isCountry());
        $this->assertSame('France', $france->name);
        $this->assertSame('FR', $france->countryCode);

        $moselle = $regions['FR-57'];
        $this->assertFalse($moselle->isCountry());
        $this->assertSame('FR', $moselle->parentCode);
        $this->assertSame('Moselle', $moselle->name);
        $this->assertSame('Guadeloupe', $regions['FR-971']->name);
    }

    public function testCountryNamesFollowTheLocale(): void
    {
        $this->assertSame('Allemagne', $this->source->getRegions('fr_FR')['DE']->name);
        $this->assertSame('Germany', $this->source->getRegions('en_GB')['DE']->name);
    }

    public function testSubdivisionLabelsAreDerivedFromProviderNames(): void
    {
        $regions = $this->source->getRegions('en_GB');
        $this->assertSame('Baden-Württemberg', $regions['DE-BW']->name, 'Fixed by hand');
        $this->assertSame('Bavaria', $regions['DE-BY']->name, 'Humanized class name');
        $this->assertSame('Circular Head', $regions['AU-TAS-NW-CH']->name);
        $this->assertSame('AU-TAS', $regions['AU-TAS-NE']->parentCode, 'Nested subdivisions keep their direct parent');
    }

    public function testSubdivisionLabelsAreTranslatedInTheSessionLanguage(): void
    {
        $previous = $_SESSION['glpilanguage'] ?? 'en_GB';
        $_SESSION['glpilanguage'] = Session::loadLanguage('fr_FR');
        try {
            $regions = $this->source->getRegions('fr_FR');
        } finally {
            $_SESSION['glpilanguage'] = Session::loadLanguage($previous);
        }

        $this->assertSame('Écosse', $regions['GB-SCT']->name);
        $this->assertSame('Bavière', $regions['DE-BY']->name);
        $this->assertSame('Bade-Wurtemberg', $regions['DE-BW']->name);
        $this->assertSame('Nouvelle-Écosse', $regions['CA-NS']->name);
        $this->assertSame('Moselle', $regions['FR-57']->name);
    }

    public function testRegionsAreSortedByCountryThenSubdivision(): void
    {
        $codes = array_keys($this->source->getRegions('fr_FR'));
        $this->assertLessThan(array_search('FR-57', $codes, true), array_search('FR', $codes, true));
        $this->assertLessThan(array_search('FR', $codes, true), array_search('DE', $codes, true), 'Allemagne < France');
    }

    public function testSupportsRegionIsCaseInsensitive(): void
    {
        $this->assertTrue($this->source->supportsRegion('FR'));
        $this->assertTrue($this->source->supportsRegion('fr-57'));
        $this->assertFalse($this->source->supportsRegion('XX'));
    }

    public function testUnknownRegionThrows(): void
    {
        $this->expectException(UnknownRegionException::class);
        $this->source->getHolidays('XX', 2026, 'fr_FR');
    }

    public function testFrenchNationalHolidays2026(): void
    {
        $holidays = $this->source->getHolidays('FR', 2026, 'fr_FR');

        $this->assertCount(11, $holidays);
        $this->assertContainsOnlyInstancesOf(HolidayEntry::class, $holidays);

        $by_date = $this->indexByDate($holidays);
        $this->assertSame([
            '2026-01-01', '2026-04-06', '2026-05-01', '2026-05-08', '2026-05-14', '2026-05-25',
            '2026-07-14', '2026-08-15', '2026-11-01', '2026-11-11', '2026-12-25',
        ], array_keys($by_date), 'Sorted by date');

        $this->assertSame('Jour de l’An', $by_date['2026-01-01']->name);
        $this->assertSame('La Fête nationale', $by_date['2026-07-14']->name);
        $this->assertSame(HolidayType::OFFICIAL, $by_date['2026-07-14']->type);
        $this->assertSame(HolidayType::OBSERVANCE, $by_date['2026-05-25']->type, 'Pentecost Monday is a solidarity day since 2004');
        $this->assertSame('FR', $by_date['2026-01-01']->regionCode);
        $this->assertSame('00:00:00', $by_date['2026-01-01']->date->format('H:i:s'));
    }

    public function testAlsaceMoselleAddsTwoDays(): void
    {
        $by_date = $this->indexByDate($this->source->getHolidays('FR-57', 2026, 'fr_FR'));

        $this->assertCount(13, $by_date);
        $this->assertSame('Vendredi Saint', $by_date['2026-04-03']->name);
        $this->assertSame('Saint-Étienne', $by_date['2026-12-26']->name);
    }

    public function testNamesFollowTheLocaleWithEnglishFallback(): void
    {
        $en = $this->indexByDate($this->source->getHolidays('FR', 2026, 'en_GB'));
        $this->assertSame('Bastille Day', $en['2026-07-14']->name);

        // German Unity Day is only translated in German in Yasumi: that name
        // must be used rather than the raw "germanUnityDay" key.
        $de = $this->indexByDate($this->source->getHolidays('DE', 2026, 'fr_FR'));
        $this->assertSame('Tag der Deutschen Einheit', $de['2026-10-03']->name);
        $this->assertSame('Fête du Travail', $de['2026-05-01']->name);
    }

    public function testUnknownLocaleFallsBackToEnglish(): void
    {
        $holidays = $this->indexByDate($this->source->getHolidays('FR', 2026, 'xx_XX'));
        $this->assertSame('New Year’s Day', $holidays['2026-01-01']->name);
    }

    /** @return array<string, HolidayEntry> */
    private function indexByDate(array $holidays): array
    {
        $indexed = [];
        foreach ($holidays as $holiday) {
            $indexed[$holiday->getDateString()] = $holiday;
        }

        return $indexed;
    }
}
