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

use Throwable;
use Calendar;
use Calendar_Holiday;
use CronTask;
use Entity;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Feriae\AutomaticImport;
use GlpiPlugin\Feriae\Import\ImportRequest;
use GlpiPlugin\Feriae\ImportedHoliday;
use GlpiPlugin\Feriae\Source\HolidayType;

final class AutomaticImportTest extends DbTestCase
{
    private function request(array $overrides = []): ImportRequest
    {
        $values = array_merge([
            'source'        => 'yasumi',
            'region_code'   => 'FR',
            'years'         => [2020, 2021],
            'types'         => [HolidayType::OFFICIAL],
            'entities_id'   => 0,
            'is_recursive'  => true,
            'calendars_id'  => [getItemByTypeName(Calendar::class, 'Default', true)],
            'replace'       => false,
            'locale'        => 'fr_FR',
            'skip_existing' => true,
        ], $overrides);

        return new ImportRequest(...$values);
    }

    private function cronTask(): CronTask
    {
        $task = new CronTask();
        $this->assertTrue($task->getFromDBbyName(AutomaticImport::class, AutomaticImport::CRON_NAME), 'The automatic action is registered at install');
        return $task;
    }

    public function testAutomaticImportsCannotBeEditedByUsers(): void
    {
        $this->login('glpi');
        $import = AutomaticImport::saveFromRequest($this->request());

        $this->assertTrue($import->can($import->getID(), READ));
        $this->assertFalse($import->can($import->getID(), UPDATE), 'Not through the generic form nor the API, even as super-admin');
        $this->assertFalse($import->can(-1, CREATE));
        $this->assertFalse($import->can($import->getID(), DELETE));
        $this->assertFalse($import->can($import->getID(), PURGE));
    }

    public function testARunKeepsTheCalendarsChosenAtSaveTimeAndDropsTheDeletedOnes(): void
    {
        $this->login('glpi');
        $child_1 = getItemByTypeName(Entity::class, '_test_child_1', true);
        $child   = (new Calendar())->add(['name' => 'Child calendar', 'entities_id' => $child_1, 'is_recursive' => 0]);
        $deleted = (new Calendar())->add(['name' => 'Deleted calendar', 'entities_id' => 0, 'is_recursive' => 1]);
        $root    = getItemByTypeName(Calendar::class, 'Default', true);
        // From the root entity, a child calendar is a legitimate choice on the form
        $import = AutomaticImport::saveFromRequest($this->request(['entities_id' => 0, 'years' => [2026], 'calendars_id' => [$child, $root, $deleted]]));
        $this->assertTrue((new Calendar())->delete(['id' => $deleted], true));

        $this->assertSame([$child, $root], $import->toRequest(2026)->calendars_id, 'The calendars chosen, the deleted one apart; a child calendar is not second-guessed');
        $import->run(2026);
        $this->assertGreaterThan(0, countElementsInTable(Calendar_Holiday::getTable(), ['calendars_id' => $child]));
        $this->assertGreaterThan(0, countElementsInTable(Calendar_Holiday::getTable(), ['calendars_id' => $root]));
        $this->assertSame(0, countElementsInTable(Calendar_Holiday::getTable(), ['calendars_id' => $deleted]));
    }

    public function testTheAutomaticActionIsRegisteredAtInstall(): void
    {
        $task = $this->cronTask();

        $this->assertSame(DAY_TIMESTAMP, (int) $task->fields['frequency']);
        $this->assertSame(CronTask::STATE_WAITING, (int) $task->fields['state']);
        $this->assertSame(CronTask::MODE_EXTERNAL, (int) $task->fields['mode'], 'CLI mode, as GLPI recommends');
        $this->assertSame($task->getID(), AutomaticImport::getCronTask()?->getID());
        $this->assertSame('Import the coming years of the automatic holiday imports', AutomaticImport::cronInfo(AutomaticImport::CRON_NAME)['description']);
    }

    public function testSaveFromRequestReplacesThePreviousImportOfTheSameRegionAndEntity(): void
    {
        $this->login('glpi');

        $first = AutomaticImport::saveFromRequest($this->request());
        $this->assertGreaterThan(0, $first->getID());
        $this->assertSame(2, (int) $first->fields['years_count']);
        $this->assertSame('["official"]', $first->fields['types']);

        $second = AutomaticImport::saveFromRequest($this->request([
            'years' => [2030, 2031, 2032],
            'types' => [HolidayType::OFFICIAL, HolidayType::OBSERVANCE],
        ]));

        $this->assertSame($first->getID(), $second->getID());
        $this->assertSame(3, (int) $second->fields['years_count']);
        $this->assertSame('["official","observance"]', $second->fields['types']);
        $this->assertCount(1, AutomaticImport::getAll());

        // Another entity is another automatic import
        $child = getItemByTypeName(Entity::class, '_test_child_1', true);
        AutomaticImport::saveFromRequest($this->request(['entities_id' => $child]));
        $this->assertCount(2, AutomaticImport::getAll());
    }

    public function testToRequestStartsFromTheGivenYear(): void
    {
        $this->login('glpi');
        $calendars_id = getItemByTypeName(Calendar::class, 'Default', true);

        $request = AutomaticImport::saveFromRequest($this->request(['is_recursive' => false, 'skip_existing' => false, 'fixed_as_recurrent' => false]))->toRequest(2040);

        $this->assertSame([2040, 2041], $request->years);
        $this->assertSame('FR', $request->region_code);
        $this->assertSame([HolidayType::OFFICIAL], $request->types);
        $this->assertSame([$calendars_id], $request->calendars_id);
        $this->assertFalse($request->is_recursive);
        $this->assertFalse($request->skip_existing);
        $this->assertFalse($request->fixed_as_recurrent);
        $this->assertFalse($request->replace);
        $this->assertSame('fr_FR', $request->locale);
    }

    public function testTheAutomaticActionImportsTheWindowStartingAtTheCurrentYear(): void
    {
        $this->login('glpi');
        $year = (int) date('Y');
        AutomaticImport::saveFromRequest($this->request());
        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, $year, 'newYearsDay'));

        $task = $this->cronTask();
        $this->assertSame(1, AutomaticImport::cronImportHolidays($task));

        // 10 official French holidays per year, two years from the current one
        // (the 8 fixed ones become recurrent periods shared by both years)
        $this->assertSame(20, countElementsInTable(ImportedHoliday::getTable(), ['source' => 'yasumi', 'region_code' => 'FR']));
        $this->assertNotNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, $year, 'newYearsDay'));
        $this->assertNotNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, $year + 1, 'bastilleDay'));
        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, $year + 2, 'newYearsDay'));
        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, 2020, 'newYearsDay'), 'The years of the manual import are not what matters');

        $import = AutomaticImport::getAll()[0];
        $this->assertNotEmpty($import->fields['last_run']);
        $this->assertStringStartsWith('12 created, 0 updated, 8 unchanged', $import->fields['last_summary']);

        // Nothing new the day after
        $this->assertSame(0, AutomaticImport::cronImportHolidays($this->cronTask()));
        $this->assertSame(20, countElementsInTable(ImportedHoliday::getTable(), ['source' => 'yasumi', 'region_code' => 'FR']));
        $this->assertStringStartsWith('0 created, 0 updated, 20 unchanged', AutomaticImport::getAll()[0]->fields['last_summary']);
    }

    public function testTheAutomaticActionSkipsInactiveImportsAndSurvivesBrokenOnes(): void
    {
        $this->login('glpi');

        $inactive = AutomaticImport::saveFromRequest($this->request());
        $inactive->update(['id' => $inactive->getID(), 'is_active' => 0]);

        $broken = AutomaticImport::saveFromRequest($this->request(['region_code' => 'FR-57']));
        $broken->update(['id' => $broken->getID(), 'source' => 'gone']);

        AutomaticImport::saveFromRequest($this->request(['region_code' => 'BE', 'years' => [2020]]));

        $this->assertSame(1, AutomaticImport::cronImportHolidays($this->cronTask()));

        $this->assertSame(0, countElementsInTable(ImportedHoliday::getTable(), ['region_code' => 'FR']));
        $this->assertSame(0, countElementsInTable(ImportedHoliday::getTable(), ['region_code' => 'FR-57']));
        $this->assertGreaterThan(0, countElementsInTable(ImportedHoliday::getTable(), ['region_code' => 'BE', 'year' => (int) date('Y')]));

        // The failure is recorded on the import, so the plugin page shows it
        $broken->getFromDB($broken->getID());
        $this->assertNotEmpty($broken->fields['last_run']);
        $this->assertStringContainsString('gone', $broken->fields['last_summary']);
        $inactive->getFromDB($inactive->getID());
        $this->assertEmpty($inactive->fields['last_run'], 'Never ran');
    }

    public function testAFailedRunRecordsItsErrorAndRethrows(): void
    {
        $this->login('glpi');
        $import = AutomaticImport::saveFromRequest($this->request());
        $import->run(2026);
        $this->assertStringStartsWith('12 created', $import->fields['last_summary']);
        $import->update(['id' => $import->getID(), 'region_code' => 'XX']);

        try {
            $import->run(2026);
            $this->fail('Expected the unknown region to throw');
        } catch (Throwable $throwable) {
        }

        $import->getFromDB($import->getID());
        $this->assertSame($throwable->getMessage(), $import->fields['last_summary']);
        $this->assertNotEmpty($import->fields['last_run']);
    }

    public function testTheSystemCronIsDetectedFromRecentCliRuns(): void
    {
        $this->login('glpi');
        global $DB;
        $DB->update(CronTask::getTable(), ['lastrun' => null], ['mode' => CronTask::MODE_EXTERNAL]);
        $this->assertFalse(AutomaticImport::isSystemCronRunning(), 'No CLI task ever ran');

        $task = $this->cronTask();
        $DB->update(CronTask::getTable(), ['lastrun' => date('Y-m-d H:i:s', strtotime('-2 days'))], ['id' => $task->getID()]);
        $this->assertFalse(AutomaticImport::isSystemCronRunning(), 'A run two days ago is not enough');

        $DB->update(CronTask::getTable(), ['lastrun' => date('Y-m-d H:i:s', strtotime('-2 hours'))], ['id' => $task->getID()]);
        $this->assertTrue(AutomaticImport::isSystemCronRunning());

        // A task in GLPI mode says nothing about the system cron
        $DB->update(CronTask::getTable(), ['mode' => CronTask::MODE_INTERNAL], ['id' => $task->getID()]);
        $this->assertFalse(AutomaticImport::isSystemCronRunning());
    }
}
