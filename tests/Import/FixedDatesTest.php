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

namespace GlpiPlugin\Feriae\Tests\Import;

use GlpiPlugin\Feriae\Import\FixedDates;
use GlpiPlugin\Feriae\Source\Yasumi\YasumiSource;
use PHPUnit\Framework\TestCase;

final class FixedDatesTest extends TestCase
{
    public function testFrenchFixedAndMovingHolidays(): void
    {
        $fixed = FixedDates::detect(new YasumiSource(), 'FR', [2026], 'fr_FR');

        $this->assertSame(
            ['newYearsDay', 'internationalWorkersDay', 'victoryInEuropeDay', 'bastilleDay', 'assumptionOfMary', 'allSaintsDay', 'armisticeDay', 'christmasDay'],
            array_keys($fixed),
        );
        $this->assertArrayNotHasKey('easterMonday', $fixed);
        $this->assertArrayNotHasKey('ascensionDay', $fixed);
    }

    public function testAHolidayObservedOnAnotherDaySomeYearsIsNotFixed(): void
    {
        // New Year's Day 2028 falls on a Saturday: England observes it on Monday
        $fixed = FixedDates::detect(new YasumiSource(), 'GB-ENG', [2027], 'en_GB');

        $this->assertArrayNotHasKey('newYearsDay', $fixed);
        $this->assertArrayHasKey('christmasDay', $fixed);
    }

    public function testNoYearsNoFixedDates(): void
    {
        $this->assertSame([], FixedDates::detect(new YasumiSource(), 'FR', [], 'fr_FR'));
    }
}
