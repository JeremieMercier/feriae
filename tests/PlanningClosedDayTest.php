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

namespace GlpiPlugin\Feriae\Tests;

use Entity;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Feriae\ImportedHoliday;
use GlpiPlugin\Feriae\PlanningClosedDay;
use Holiday;

final class PlanningClosedDayTest extends DbTestCase
{
    public function testIsRegisteredAsAPlanningType(): void
    {
        /** @var array<string, mixed> $CFG_GLPI */
        global $CFG_GLPI;

        $this->assertContains(PlanningClosedDay::class, $CFG_GLPI['planning_types']);
    }

    public function testEventsOfTheActiveEntitiesAsReadOnlyWholeDays(): void
    {
        $this->login('glpi');
        $this->setEntity('_test_root_entity', true);
        $root    = getItemByTypeName(Entity::class, '_test_root_entity', true);
        $child_1 = getItemByTypeName(Entity::class, '_test_child_1', true);
        $noel = (new Holiday())->add(['name' => 'Noël', 'entities_id' => $root, 'is_recursive' => 1, 'begin_date' => '2019-12-25', 'end_date' => '2019-12-25', 'is_perpetual' => 1]);
        (new Holiday())->add(['name' => 'Pont', 'entities_id' => $child_1, 'is_recursive' => 0, 'begin_date' => '2026-12-28', 'end_date' => '2026-12-29', 'is_perpetual' => 0]);
        // Imported (and renewed) from Moselle, France
        foreach ([2025, 2026] as $year) {
            (new ImportedHoliday())->add(['holidays_id' => $noel, 'source' => 'yasumi', 'region_code' => 'FR-57', 'entities_id' => $root, 'year' => $year, 'holiday_key' => 'christmasDay', 'holiday_date' => $year . '-12-25']);
        }

        $events = PlanningClosedDay::populatePlanning([
            'begin'            => '2026-12-01 00:00:00',
            'end'              => '2026-12-31 23:59:59',
            'who'              => 0,
            'whogroup'         => 0,
            'color'            => '#aabbcc',
            'event_type_color' => '#112233',
        ]);

        $this->assertCount(2, $events);
        $events = array_values($events);
        $this->assertSame('Noël', $events[0]['name']);
        $this->assertSame('2026-12-25 00:00:00', $events[0]['begin']);
        $this->assertSame('2026-12-26 00:00:00', $events[0]['end']);
        $this->assertSame(PlanningClosedDay::class, $events[0]['itemtype']);
        $this->assertFalse($events[0]['editable']);
        $this->assertSame('#112233', $events[0]['color'], 'Colour of the planning filter');
        $this->assertTrue($events[0]['is_perpetual']);
        $this->assertSame('France / Moselle', $events[0]['region'], 'Country or region it was imported from, once');

        $this->assertSame('Pont', $events[1]['name']);
        $this->assertSame('2026-12-28 00:00:00', $events[1]['begin']);
        $this->assertSame('2026-12-30 00:00:00', $events[1]['end'], 'Ends at midnight after the last day');
        $this->assertSame($child_1, $events[1]['entities_id']);
        $this->assertSame('', $events[1]['region'], 'Entered by hand');
    }

    public function testNeverReportedAsAConflict(): void
    {
        $this->login('glpi');
        $root = getItemByTypeName(Entity::class, '_test_root_entity', true);
        (new Holiday())->add(['name' => 'Noël', 'entities_id' => $root, 'is_recursive' => 1, 'begin_date' => '2026-12-25', 'end_date' => '2026-12-25', 'is_perpetual' => 0]);

        $this->assertSame([], PlanningClosedDay::populatePlanning([
            'begin'         => '2026-12-25 00:00:00',
            'end'           => '2026-12-26 00:00:00',
            'who'           => 0,
            'whogroup'      => 0,
            'check_planned' => true,
        ]));
    }

    public function testDefaultFilterColourOnFirstAppearanceOnly(): void
    {
        $this->login('glpi');
        $key = PlanningClosedDay::class;

        // No planning session yet (first visit is handled by GLPI itself): nothing to do
        unset($_SESSION['glpi_plannings']);
        PlanningClosedDay::initDefaultFilterColor();
        $this->assertArrayNotHasKey('glpi_plannings', $_SESSION);

        // Filter missing: created with the default colour, displayed
        $_SESSION['glpi_plannings'] = ['filters' => ['TicketTask' => ['color' => '#E94A31', 'display' => true, 'type' => 'event_filter']]];
        PlanningClosedDay::initDefaultFilterColor();
        $this->assertSame(PlanningClosedDay::DEFAULT_FILTER_COLOR, $_SESSION['glpi_plannings']['filters'][$key]['color']);
        $this->assertTrue($_SESSION['glpi_plannings']['filters'][$key]['display']);
        $this->assertSame('#E94A31', $_SESSION['glpi_plannings']['filters']['TicketTask']['color'], 'Other filters untouched');

        // Existing filter, colour chosen by the user: left alone
        $_SESSION['glpi_plannings']['filters'][$key] = ['color' => '#123456', 'display' => false, 'type' => 'event_filter'];
        PlanningClosedDay::initDefaultFilterColor();
        $this->assertSame('#123456', $_SESSION['glpi_plannings']['filters'][$key]['color']);
        $this->assertFalse($_SESSION['glpi_plannings']['filters'][$key]['display']);
        unset($_SESSION['glpi_plannings']);
    }

    public function testPlanningItemContent(): void
    {
        $this->login('glpi');
        $root = getItemByTypeName(Entity::class, '_test_root_entity', true);
        $val  = ['name' => 'Noël <b>', 'entities_id' => $root, 'is_perpetual' => true, 'region' => 'France / Moselle'];

        $short = PlanningClosedDay::displayPlanningItem($val, 0);
        $this->assertStringContainsString('Noël &lt;b&gt;', $short);
        $this->assertStringNotContainsString('_test_root_entity', $short);

        $full = PlanningClosedDay::displayPlanningItem($val, 0, '', true);
        $this->assertStringContainsString('_test_root_entity', $full);
        $this->assertStringContainsString('Recurrent', $full);
        $this->assertStringContainsString('<i class="ti ti-world me-1"></i>France / Moselle', $full);
        $this->assertStringNotContainsString('ti-world', PlanningClosedDay::displayPlanningItem(['name' => 'Pont', 'entities_id' => $root, 'is_perpetual' => false, 'region' => ''], 0, '', true));
    }
}
