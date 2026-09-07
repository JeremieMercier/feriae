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

use Glpi\Tests\DbTestCase;
use GlpiPlugin\Feriae\Import\ImportPreview;
use GlpiPlugin\Feriae\Source\Yasumi\YasumiSource;

final class ImportPreviewTest extends DbTestCase
{
    public function testCountsHolidaysByTypeInTheOrderOfTheTypes(): void
    {
        $preview = ImportPreview::forRegion(new YasumiSource(), 'FR', 2026, 'fr_FR');

        $this->assertSame(['official', 'observance', 'bank', 'season', 'other'], array_keys($preview));
        $this->assertSame(10, $preview['official']['count']);
        $this->assertSame('Public holiday', $preview['official']['label']);
        $this->assertContains('La Fête nationale', $preview['official']['names']);
        $this->assertSame(1, $preview['observance']['count']);
        $this->assertSame(['Lundi de Pentecôte'], $preview['observance']['names']);
        $this->assertSame(0, $preview['bank']['count']);
        $this->assertSame([], $preview['bank']['names']);
    }

    public function testScotlandHasBankHolidaysButNoPublicHoliday(): void
    {
        $preview = ImportPreview::forRegion(new YasumiSource(), 'GB-SCT', 2026, 'en_GB');

        $this->assertSame(0, $preview['official']['count']);
        $this->assertSame(10, $preview['bank']['count']);
        $this->assertSame('Bank holiday: 10, Other: 1', ImportPreview::describe($preview));
    }

    public function testDescribeIsEmptyWithoutHolidays(): void
    {
        $preview = ImportPreview::forRegion(new YasumiSource(), 'FR', 2026, 'en_GB');
        foreach ($preview as &$type) {
            $type['count'] = 0;
        }

        unset($type);

        $this->assertSame('', ImportPreview::describe($preview));
    }
}
