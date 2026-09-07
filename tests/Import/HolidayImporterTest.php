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

use Calendar;
use Calendar_Holiday;
use Entity;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Feriae\AutomaticImport;
use GlpiPlugin\Feriae\Import\HolidayImporter;
use GlpiPlugin\Feriae\Import\ImportRequest;
use GlpiPlugin\Feriae\ImportedHoliday;
use GlpiPlugin\Feriae\Source\HolidayType;
use Holiday;

final class HolidayImporterTest extends DbTestCase
{
    private function request(array $overrides = []): ImportRequest
    {
        $defaults = [
            'source'       => 'yasumi',
            'region_code'  => 'FR',
            'years'        => [2026],
            'types'        => [HolidayType::OFFICIAL],
            'entities_id'  => 0,
            'is_recursive' => true,
            'calendars_id' => [$this->defaultCalendarId()],
            'replace'      => false,
            'locale'       => 'fr_FR',
            'skip_existing' => true,
            'fixed_as_recurrent' => true,
        ];
        $values = array_merge($defaults, $overrides);

        return new ImportRequest(...$values);
    }

    private function defaultCalendarId(): int
    {
        return getItemByTypeName(Calendar::class, 'Default', true);
    }

    private function countHolidays(): int
    {
        return countElementsInTable(Holiday::getTable(), ['comment' => ['LIKE', 'Imported by Feriae%']]);
    }

    /**
     * A tracking row rewritten behind the plugin's back, the way a generic
     * write would do it, pointing at a period of another entity.
     */
    private function tamperTracking(string $holiday_key, int $holidays_id): void
    {
        $row = ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, $holiday_key);
        $this->assertNotNull($row);
        $this->assertTrue($row->update(['id' => $row->getID(), 'holidays_id' => $holidays_id]));
    }

    public function testPurgeLeavesAlonePeriodsOutsideTheEntityOfTheImport(): void
    {
        $this->login('glpi');
        $child_2 = getItemByTypeName(Entity::class, '_test_child_2', true);
        $foreign = (new Holiday())->add(['name' => 'Foreign', 'entities_id' => $child_2, 'begin_date' => '2026-06-01', 'end_date' => '2026-06-01']);
        $importer = new HolidayImporter();
        $importer->import($this->request(['calendars_id' => []]));
        $this->tamperTracking('newYearsDay', $foreign);
        $created = $this->countHolidays();

        $removed = $importer->purge('yasumi', 'FR', 0, 2026);

        $this->assertTrue((new Holiday())->getFromDB($foreign), 'The period of the other entity is untouched');
        $this->assertSame($created - 1, $removed, 'Every period of the import but the one no longer tracked');
        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'newYearsDay'), 'The tampered row is gone with the import');
    }

    public function testSharingLeavesAlonePeriodsOutsideTheEntityOfTheImport(): void
    {
        $this->login('glpi');
        $child_2 = getItemByTypeName(Entity::class, '_test_child_2', true);
        $foreign = (new Holiday())->add(['name' => 'Foreign', 'entities_id' => $child_2, 'is_recursive' => 1, 'begin_date' => '2026-06-01', 'end_date' => '2026-06-01']);
        $importer = new HolidayImporter();
        $importer->import($this->request(['calendars_id' => []]));
        $this->tamperTracking('newYearsDay', $foreign);

        $result = $importer->setRecursive('yasumi', 'FR', 0, false);

        $holiday = new Holiday();
        $holiday->getFromDB($foreign);
        $this->assertSame(1, (int) $holiday->fields['is_recursive'], 'The period of the other entity is untouched');
        $this->assertSame($this->countHolidays() - 1, $result['updated'], 'Every period of the import but the one no longer tracked');
    }

    public function testTrackingRowsCannotBeEditedByUsers(): void
    {
        $this->login('glpi');
        (new HolidayImporter())->import($this->request(['calendars_id' => []]));
        $row = ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'newYearsDay');
        $this->assertNotNull($row);

        $this->assertTrue($row->can($row->getID(), READ), 'Readable, for the lists and links');
        $this->assertFalse($row->can($row->getID(), UPDATE), 'Not through the generic form nor the API, even as super-admin');
        $this->assertFalse($row->can(-1, CREATE));
        $this->assertFalse($row->can($row->getID(), DELETE));
        $this->assertFalse($row->can($row->getID(), PURGE));
    }

    public function testImportsOfficialHolidaysAsClosingPeriodsLinkedToTheCalendar(): void
    {
        $this->login('glpi');
        $before = countElementsInTable(Holiday::getTable());

        $report = (new HolidayImporter())->import($this->request());

        // 11 French holidays, Pentecost Monday being an observance
        $this->assertSame(10, $report->created);
        $this->assertSame(0, $report->updated);
        $this->assertSame(0, $report->skipped);
        $this->assertSame(10, $report->linked);
        $this->assertSame($before + 10, countElementsInTable(Holiday::getTable()));

        $holiday = new Holiday();
        $this->assertTrue($holiday->getFromDBByCrit(['name' => 'Jour de l’An', 'begin_date' => '2026-01-01']));
        $this->assertSame('2026-01-01', $holiday->fields['end_date']);
        $this->assertSame(1, (int) $holiday->fields['is_perpetual'], "New Year's Day is fixed: recurrent by default");
        $this->assertSame(0, (int) $holiday->fields['entities_id']);
        $this->assertSame(1, (int) $holiday->fields['is_recursive']);
        $this->assertStringContainsString('Yasumi', $holiday->fields['comment']);
        $this->assertStringContainsString('FR, 2026', $holiday->fields['comment']);

        $this->assertSame(1, countElementsInTable(Calendar_Holiday::getTable(), [
            'calendars_id' => $this->defaultCalendarId(),
            'holidays_id'  => $holiday->getID(),
        ]));

        $tracking = ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'newYearsDay');
        $this->assertNotNull($tracking);
        $this->assertSame($holiday->getID(), (int) $tracking->fields['holidays_id']);
        $this->assertSame('2026-01-01', $tracking->fields['holiday_date']);
    }

    public function testTheCalendarItselfSeesTheImportedPeriods(): void
    {
        $this->login('glpi');
        $calendar = new Calendar();
        $calendar->getFromDB($this->defaultCalendarId());
        $this->assertFalse($calendar->isHoliday('2026-07-14'));

        (new HolidayImporter())->import($this->request());

        // Same path as the core: Calendar > Close times tab > glpi_holidays,
        // used by the SLA / working time computations
        $calendar->getFromDB($this->defaultCalendarId());
        $this->assertTrue($calendar->isHoliday('2026-07-14'));
        $this->assertTrue($calendar->isHoliday('2026-12-25'));
        $this->assertFalse($calendar->isHoliday('2026-07-15'));
        $this->assertTrue($calendar->isHoliday('2027-07-14'), 'Fixed dates are recurrent periods, valid every year');
        $this->assertFalse($calendar->isHoliday('2027-03-29'), 'Easter Monday 2027 is not imported yet');
        $this->assertFalse($calendar->isAWorkingDay(strtotime('2026-07-14 10:00:00')));
    }

    public function testTypesFilterAndSeveralYears(): void
    {
        $this->login('glpi');

        $report = (new HolidayImporter())->import($this->request([
            'years' => [2026, 2027],
            'types' => [HolidayType::OFFICIAL, HolidayType::OBSERVANCE],
        ]));

        // 2026: 11 days; 2027: the 8 fixed ones share the recurrent periods of 2026, 3 moving ones are created
        $this->assertSame(14, $report->created);
        $this->assertSame(8, $report->unchanged);
        $this->assertNotNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'pentecostMonday'));
        $this->assertNotNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, 2027, 'christmasDay'));
    }

    public function testReimportIsIdempotent(): void
    {
        $this->login('glpi');
        $importer = new HolidayImporter();

        $importer->import($this->request());

        $report = $importer->import($this->request());

        $this->assertSame(0, $report->created);
        $this->assertSame(10, $report->unchanged);
        $this->assertSame(0, $report->linked);
        $this->assertSame(10, $this->countHolidays());
    }

    public function testReimportRefreshesRenamedOrMovedPeriods(): void
    {
        $this->login('glpi');
        $importer = new HolidayImporter();
        $importer->import($this->request());

        $tracking = ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'bastilleDay');
        $holiday  = new Holiday();
        $holiday->getFromDB((int) $tracking->fields['holidays_id']);
        $holiday->update(['id' => $holiday->getID(), 'name' => 'Renamed', 'begin_date' => '2026-07-15', 'end_date' => '2026-07-15']);

        $report = $importer->import($this->request());

        $this->assertSame(1, $report->updated);
        $this->assertSame(9, $report->unchanged);
        $holiday->getFromDB($holiday->getID());
        $this->assertSame('La Fête nationale', $holiday->fields['name']);
        $this->assertSame('2026-07-14', $holiday->fields['begin_date']);
    }

    public function testReimportLinksExistingPeriodsToNewCalendars(): void
    {
        $this->login('glpi');
        $importer = new HolidayImporter();
        $importer->import($this->request(['calendars_id' => []]));

        $report = $importer->import($this->request());

        $this->assertSame(10, $report->unchanged);
        $this->assertSame(10, $report->linked);
    }

    public function testPeriodsDeletedByHandAreNotRecreated(): void
    {
        $this->login('glpi');
        $importer = new HolidayImporter();
        $importer->import($this->request());

        $tracking = ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'easterMonday');
        (new Holiday())->delete(['id' => (int) $tracking->fields['holidays_id']], true);

        $report = $importer->import($this->request());

        $this->assertSame(0, $report->created);
        $this->assertSame(1, $report->skipped);
        $this->assertSame(9, $this->countHolidays());
    }

    public function testReplaceRecreatesEverything(): void
    {
        $this->login('glpi');
        $importer = new HolidayImporter();
        $importer->import($this->request());

        $tracking = ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'easterMonday');
        (new Holiday())->delete(['id' => (int) $tracking->fields['holidays_id']], true);

        $report = $importer->import($this->request(['replace' => true]));

        $this->assertSame(9, $report->removed);
        $this->assertSame(10, $report->created);
        $this->assertSame(0, $report->skipped);
        $this->assertSame(10, $this->countHolidays());
        $this->assertSame(10, countElementsInTable(ImportedHoliday::getTable()));
    }

    public function testPurgeRemovesPeriodsLinksAndTracking(): void
    {
        $this->login('glpi');
        $importer = new HolidayImporter();
        $importer->import($this->request(['years' => [2026, 2027]]));

        $links_before = countElementsInTable(Calendar_Holiday::getTable());

        $removed = $importer->purge('yasumi', 'FR', 0, 2026);

        // The 2 moving days of 2026 go; the 8 recurrent periods are still tracked by 2027
        $this->assertSame(2, $removed);
        $this->assertSame(10, $this->countHolidays());
        $this->assertSame($links_before - 2, countElementsInTable(Calendar_Holiday::getTable()));
        $this->assertSame(10, countElementsInTable(ImportedHoliday::getTable()));
        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'newYearsDay'));
        $this->assertNotNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, 2027, 'newYearsDay'));

        // Removing the last year removes everything
        $this->assertSame(10, $importer->purge('yasumi', 'FR', 0, 2027));
        $this->assertSame(0, $this->countHolidays());
        $this->assertSame($links_before - 12, countElementsInTable(Calendar_Holiday::getTable()));
        $this->assertSame(0, countElementsInTable(ImportedHoliday::getTable()));
    }

    public function testDatesCoveredByAHandMadePeriodAreReusedNotDuplicated(): void
    {
        $this->login('glpi');
        $manual = new Holiday();
        // Recurrent, dated another year: matched on month and day
        $manual_id = $manual->add(['name' => 'Noël (manuel)', 'entities_id' => 0, 'is_recursive' => 1, 'begin_date' => '2025-12-25', 'end_date' => '2025-12-25', 'is_perpetual' => 1]);
        // Dated range covering the 1st of May
        $range = new Holiday();
        $range_id = $range->add(['name' => 'Pont de mai', 'entities_id' => 0, 'is_recursive' => 1, 'begin_date' => '2026-04-30', 'end_date' => '2026-05-03', 'is_perpetual' => 0]);

        $report = (new HolidayImporter())->import($this->request());

        $this->assertSame(8, $report->created);
        $this->assertSame(2, $report->duplicates);
        $this->assertSame(10, $report->linked, 'The existing periods are attached to the calendar too');
        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'christmasDay'), 'A reused period is not tracked');
        $this->assertSame(1, countElementsInTable(Calendar_Holiday::getTable(), ['calendars_id' => $this->defaultCalendarId(), 'holidays_id' => $manual_id]));
        $this->assertSame(1, countElementsInTable(Calendar_Holiday::getTable(), ['calendars_id' => $this->defaultCalendarId(), 'holidays_id' => $range_id]));

        // Removing the import leaves the hand-made periods alone
        (new HolidayImporter())->purge('yasumi', 'FR', 0, 2026);
        $this->assertTrue((new Holiday())->getFromDB($manual_id));
        $this->assertTrue((new Holiday())->getFromDB($range_id));
    }

    public function testDuplicateCheckCanBeDisabled(): void
    {
        $this->login('glpi');
        (new Holiday())->add(['name' => 'Noël (manuel)', 'entities_id' => 0, 'is_recursive' => 1, 'begin_date' => '2025-12-25', 'end_date' => '2025-12-25', 'is_perpetual' => 1]);

        $report = (new HolidayImporter())->import($this->request(['skip_existing' => false]));

        $this->assertSame(10, $report->created);
        $this->assertSame(0, $report->duplicates);
    }

    public function testDuplicateCheckFollowsTheEntityVisibility(): void
    {
        $this->login('glpi');
        $root    = getItemByTypeName(Entity::class, '_test_root_entity', true);
        $child_1 = getItemByTypeName(Entity::class, '_test_child_1', true);
        $child_2 = getItemByTypeName(Entity::class, '_test_child_2', true);

        // Visible from child 1 (recursive from the parent), not from child 2 (sibling)
        (new Holiday())->add(['name' => 'Noël parent', 'entities_id' => $root, 'is_recursive' => 1, 'begin_date' => '2026-12-25', 'end_date' => '2026-12-25', 'is_perpetual' => 0]);
        (new Holiday())->add(['name' => 'Noël voisin', 'entities_id' => $child_2, 'is_recursive' => 0, 'begin_date' => '2026-12-25', 'end_date' => '2026-12-25', 'is_perpetual' => 0]);
        (new Holiday())->add(['name' => 'Toussaint enfant non récursif', 'entities_id' => $child_1, 'is_recursive' => 0, 'begin_date' => '2026-11-01', 'end_date' => '2026-11-01', 'is_perpetual' => 0]);

        $report = (new HolidayImporter())->import($this->request(['entities_id' => $child_1, 'region_code' => 'FR-57']));

        $this->assertSame(2, $report->duplicates, 'Christmas from the parent and All Saints of the entity itself');
        $this->assertSame(10, $report->created);
    }

    public function testNationalDaysAreNotDuplicatedWhenImportingASubdivisionAfterTheCountry(): void
    {
        $this->login('glpi');
        $importer = new HolidayImporter();
        $importer->import($this->request());

        $report = $importer->import($this->request(['region_code' => 'FR-57']));

        $this->assertSame(2, $report->created, 'Good Friday and Saint Stephen only');
        $this->assertSame(10, $report->duplicates);
        $this->assertSame(12, $this->countHolidays());
    }

    public function testImportGroups(): void
    {
        $this->login('glpi');
        $importer = new HolidayImporter();
        $importer->import($this->request(['years' => [2026, 2027]]));
        $importer->import($this->request(['region_code' => 'FR-57', 'years' => [2026], 'is_recursive' => false]));

        $tracking = ImportedHoliday::findTracking('yasumi', 'FR', 0, 2027, 'easterMonday');
        (new Holiday())->delete(['id' => (int) $tracking->fields['holidays_id']], true);

        $groups = $importer->getImportGroups();
        $groups = array_filter($groups, static fn(array $g): bool => str_starts_with($g['region_code'], 'FR'));

        $this->assertSame([
            ['source' => 'yasumi', 'region_code' => 'FR', 'entities_id' => 0, 'year' => 2027, 'tracked' => 10, 'existing' => 9, 'is_recursive' => true],
            ['source' => 'yasumi', 'region_code' => 'FR', 'entities_id' => 0, 'year' => 2026, 'tracked' => 10, 'existing' => 10, 'is_recursive' => true],
            // National days already imported for FR are reused, only the two local ones are tracked
            ['source' => 'yasumi', 'region_code' => 'FR-57', 'entities_id' => 0, 'year' => 2026, 'tracked' => 2, 'existing' => 2, 'is_recursive' => false],
        ], array_values($groups));
    }

    public function testImportedDaysListWhatBecameOfEachDay(): void
    {
        $this->login('glpi');
        (new HolidayImporter())->import($this->request());

        $days = (new HolidayImporter())->getImportedDays('yasumi', 'FR', 0, 2026);
        $this->assertCount(10, $days);
        $this->assertSame('2026-01-01', $days[0]['holiday_date']);
        $this->assertSame('newYearsDay', $days[0]['holiday_key']);
        $this->assertSame('Jour de l’An', $days[0]['name']);
        $this->assertSame(0, $days[0]['entities_id']);
        $this->assertSame(1, $days[0]['calendars']);

        $this->assertFalse($days[0]['is_moved']);

        // Transferred to another entity by hand: still listed, marked, and left alone
        $child_1 = getItemByTypeName(Entity::class, '_test_child_1', true);
        $this->assertTrue((new Holiday())->update(['id' => $days[1]['holidays_id'], 'entities_id' => $child_1]));
        $days = (new HolidayImporter())->getImportedDays('yasumi', 'FR', 0, 2026);
        $this->assertSame($child_1, $days[1]['entities_id']);
        $this->assertTrue($days[1]['is_moved']);
        $this->assertFalse($days[0]['is_moved']);

        // Deleted by hand: still listed, without a close time
        $this->deleteItem(Holiday::class, $days[0]['holidays_id'], true);
        $days = (new HolidayImporter())->getImportedDays('yasumi', 'FR', 0, 2026);
        $this->assertCount(10, $days);
        $this->assertNull($days[0]['name']);
        $this->assertFalse($days[0]['is_moved']);
        $this->assertSame(0, $days[0]['calendars']);

        $this->assertSame([], (new HolidayImporter())->getImportedDays('yasumi', 'FR', 0, 2030));
    }

    public function testImportCalendarsListTheCalendarsOfAnImport(): void
    {
        $this->login('glpi');
        $importer = new HolidayImporter();
        $this->assertSame([], $importer->getImportCalendars('yasumi', 'FR', 0, 2026));

        $importer->import($this->request());
        $calendars = $importer->getImportCalendars('yasumi', 'FR', 0, 2026);

        $this->assertCount(1, $calendars);
        $this->assertSame($this->defaultCalendarId(), $calendars[0]['id']);
        $this->assertSame('Default', $calendars[0]['name']);
        $this->assertSame(10, $calendars[0]['holidays']);

        // A period deleted by hand is not attached any more
        $this->deleteItem(Holiday::class, $importer->getImportedDays('yasumi', 'FR', 0, 2026)[0]['holidays_id'], true);
        $this->assertSame(9, $importer->getImportCalendars('yasumi', 'FR', 0, 2026)[0]['holidays']);
    }

    public function testFixedDatesBecomeRecurrentPeriodsSharedByTheYears(): void
    {
        $this->login('glpi');
        $before = countElementsInTable(Holiday::getTable());

        $first = (new HolidayImporter())->import($this->request(['years' => [2026]]));
        $this->assertSame(10, $first->created);
        $this->assertSame($before + 10, countElementsInTable(Holiday::getTable()));

        $second = (new HolidayImporter())->import($this->request(['years' => [2027]]));
        $this->assertSame(2, $second->created, 'Easter Monday and Ascension');
        $this->assertSame(8, $second->unchanged, 'The fixed dates reuse the recurrent periods');
        $this->assertSame($before + 12, countElementsInTable(Holiday::getTable()));

        // Both years track the same recurrent period
        $tracking_2026 = ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'christmasDay');
        $tracking_2027 = ImportedHoliday::findTracking('yasumi', 'FR', 0, 2027, 'christmasDay');
        $this->assertNotNull($tracking_2027);
        $this->assertSame($tracking_2026->getHolidaysId(), $tracking_2027->getHolidaysId());
        $this->assertSame('2027-12-25', $tracking_2027->fields['holiday_date']);
        $holiday = new Holiday();
        $holiday->getFromDB($tracking_2026->getHolidaysId());
        $this->assertSame(1, (int) $holiday->fields['is_perpetual']);
        $this->assertSame('2026-12-25', $holiday->fields['begin_date']);

        // Moving ones stay dated
        $ascension = ImportedHoliday::findTracking('yasumi', 'FR', 0, 2027, 'ascensionDay');
        $holiday->getFromDB($ascension->getHolidaysId());
        $this->assertSame(0, (int) $holiday->fields['is_perpetual']);
        $this->assertSame('2027-05-06', $holiday->fields['begin_date']);

        // A shared recurrent period survives the removal of one year, not of the last
        $this->assertSame(2, (new HolidayImporter())->purge('yasumi', 'FR', 0, 2027));
        $this->assertTrue($holiday->getFromDB($tracking_2026->getHolidaysId()), 'Still tracked by 2026');
        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, 2027, 'christmasDay'));
        $this->assertSame(10, (new HolidayImporter())->purge('yasumi', 'FR', 0, 2026));
        $this->assertFalse($holiday->getFromDB($tracking_2026->getHolidaysId()));
        $this->assertSame($before, countElementsInTable(Holiday::getTable()));
    }

    public function testReimportingARecurrentPeriodOnlyComparesTheMonthAndDay(): void
    {
        $this->login('glpi');
        (new HolidayImporter())->import($this->request(['years' => [2026, 2027]]));

        $report = (new HolidayImporter())->import($this->request(['years' => [2027]]));

        $this->assertSame(0, $report->updated, 'The 2026 date of the recurrent period is not a change for 2027');
        $this->assertSame(10, $report->unchanged);
    }

    public function testFixedDatesStayDatedWhenTheOptionIsOff(): void
    {
        $this->login('glpi');

        $report = (new HolidayImporter())->import($this->request(['years' => [2026, 2027], 'fixed_as_recurrent' => false]));

        $this->assertSame(20, $report->created);
        $holiday = new Holiday();
        $holiday->getFromDB(ImportedHoliday::findTracking('yasumi', 'FR', 0, 2027, 'christmasDay')->getHolidaysId());
        $this->assertSame(0, (int) $holiday->fields['is_perpetual']);
        $this->assertSame('2027-12-25', $holiday->fields['begin_date']);

        // Switching the option on afterwards converts the tracked periods
        $report = (new HolidayImporter())->import($this->request(['years' => [2027]]));
        $this->assertSame(8, $report->updated);
        $holiday->getFromDB($holiday->getID());
        $this->assertSame(1, (int) $holiday->fields['is_perpetual']);
    }

    public function testPurgeAllRemovesEveryImportAndNothingElse(): void
    {
        $this->login('glpi');
        $importer = new HolidayImporter();
        $manual = (new Holiday())->add(['name' => 'Inventaire', 'entities_id' => 0, 'is_recursive' => 1, 'begin_date' => '2026-08-17', 'end_date' => '2026-08-21', 'is_perpetual' => 0]);
        $before = countElementsInTable(Holiday::getTable());
        $importer->import($this->request(['years' => [2026, 2027]]));
        $importer->import($this->request(['region_code' => 'FR-57', 'years' => [2026]]));
        $this->assertSame(12 + 2, $importer->purgeAll(), '12 French periods (10 for 2026, 2 moving ones for 2027) and the 2 local ones of Moselle');

        $this->assertSame($before, countElementsInTable(Holiday::getTable()));
        $this->assertSame(0, countElementsInTable(ImportedHoliday::getTable()));
        $this->assertSame([], $importer->getImportGroups());
        $this->assertTrue((new Holiday())->getFromDB($manual), 'Hand-made periods are kept');
        $this->assertSame(0, $importer->purgeAll());
    }

    public function testGroupSettingsAreReadBackFromWhatTheImportCreated(): void
    {
        $this->login('glpi');
        $importer = new HolidayImporter();
        $child = getItemByTypeName(Entity::class, '_test_child_1', true);
        $this->assertNull($importer->getGroupSettings('yasumi', 'FR', $child, 2026, 'fr_FR'));

        $importer->import($this->request([
            'years'        => [2026, 2027],
            'types'        => [HolidayType::OFFICIAL, HolidayType::OBSERVANCE],
            'entities_id'  => $child,
            'is_recursive' => false,
        ]));

        $settings = $importer->getGroupSettings('yasumi', 'FR', $child, 2027, 'fr_FR');
        $this->assertNull($importer->getGroupSettings('yasumi', 'FR', 0, 2027, 'fr_FR'), 'Nothing imported in the root entity');
        $this->assertFalse($settings['is_recursive']);
        $this->assertSame([$this->defaultCalendarId()], $settings['calendars_id']);
        $this->assertSame([HolidayType::OFFICIAL, HolidayType::OBSERVANCE], $settings['types']);
        $this->assertTrue($settings['fixed_as_recurrent']);
        $this->assertSame([2026, 2027], $settings['years']);

        // Without recurrent periods and with a single type
        $importer->import($this->request(['region_code' => 'BE', 'years' => [2026], 'fixed_as_recurrent' => false, 'calendars_id' => []]));
        $settings = $importer->getGroupSettings('yasumi', 'BE', 0, 2026, 'fr_FR');
        $this->assertSame([], $settings['calendars_id']);
        $this->assertSame([HolidayType::OFFICIAL], $settings['types']);
        $this->assertFalse($settings['fixed_as_recurrent']);
        $this->assertSame([2026], $settings['years']);
    }

    public function testSharingWithChildEntitiesCanBeChangedForEveryYearAtOnce(): void
    {
        $this->login('glpi');
        $importer = new HolidayImporter();
        $importer->import($this->request(['years' => [2026, 2027], 'is_recursive' => true]));
        $importer->import($this->request(['region_code' => 'BE', 'is_recursive' => true]));

        $automatic = AutomaticImport::saveFromRequest($this->request(['is_recursive' => true]));
        $this->assertSame(0, countElementsInTable(Holiday::getTable(), ['comment' => ['LIKE', 'Imported by Feriae%'], 'is_recursive' => 0]));

        $result = $importer->setRecursive('yasumi', 'FR', 0, false);

        $french = countElementsInTable(Holiday::getTable(), ['comment' => ['LIKE', 'Imported by Feriae%(FR,%']]);
        $this->assertSame(['updated' => $french, 'blocked' => 0], $result);
        $this->assertSame(0, countElementsInTable(Holiday::getTable(), ['comment' => ['LIKE', 'Imported by Feriae%(FR,%'], 'is_recursive' => 1]), 'Both years');
        $this->assertSame(0, countElementsInTable(Holiday::getTable(), ['comment' => ['LIKE', 'Imported by Feriae%(BE,%'], 'is_recursive' => 0]), 'Other regions untouched');
        $this->assertTrue($automatic->getFromDB($automatic->getID()));
        $this->assertSame(0, (int) $automatic->fields['is_recursive'], 'The automatic import follows');

        $this->assertSame(['updated' => 0, 'blocked' => 0], $importer->setRecursive('yasumi', 'FR', 0, false), 'Nothing left to change');
        $this->assertSame(['updated' => $french, 'blocked' => 0], $importer->setRecursive('yasumi', 'FR', 0, true));
        $this->assertTrue($automatic->getFromDB($automatic->getID()));
        $this->assertSame(1, (int) $automatic->fields['is_recursive']);
    }

    public function testAPeriodUsedByACalendarOfAChildEntityStaysShared(): void
    {
        $this->login('glpi');
        $child    = getItemByTypeName(Entity::class, '_test_child_1', true);
        $importer = new HolidayImporter();
        $importer->import($this->request(['is_recursive' => true, 'calendars_id' => []]));

        $christmas = ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'christmasDay');
        $this->assertNotNull($christmas);
        $calendars_id = (new Calendar())->add(['name' => 'Child calendar', 'entities_id' => $child]);
        $this->assertGreaterThan(0, $calendars_id);
        $this->assertGreaterThan(0, (new Calendar_Holiday())->add(['calendars_id' => $calendars_id, 'holidays_id' => $christmas->getHolidaysId()]));

        $result = $importer->setRecursive('yasumi', 'FR', 0, false);

        $this->assertSame(1, $result['blocked']);
        $this->assertGreaterThan(0, $result['updated']);
        $holiday = new Holiday();
        $this->assertTrue($holiday->getFromDB($christmas->getHolidaysId()));
        $this->assertTrue($holiday->isRecursive(), 'Still shared: the child calendar needs it');
    }

    public function testTheSameRegionCanBeImportedInSeveralEntities(): void
    {
        $this->login('glpi');
        $importer = new HolidayImporter();
        $child_1  = getItemByTypeName(Entity::class, '_test_child_1', true);
        $child_2  = getItemByTypeName(Entity::class, '_test_child_2', true);

        $first  = $importer->import($this->request(['entities_id' => $child_1, 'is_recursive' => false, 'skip_existing' => false]));
        $second = $importer->import($this->request(['entities_id' => $child_2, 'is_recursive' => false, 'skip_existing' => false]));

        $this->assertSame(10, $first->created);
        $this->assertSame(10, $second->created, 'A sibling entity gets its own periods');
        $tracking_1 = ImportedHoliday::findTracking('yasumi', 'FR', $child_1, 2026, 'christmasDay');
        $tracking_2 = ImportedHoliday::findTracking('yasumi', 'FR', $child_2, 2026, 'christmasDay');
        $this->assertNotSame($tracking_1->getHolidaysId(), $tracking_2->getHolidaysId());
        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'christmasDay'));

        $groups = array_values(array_filter($importer->getImportGroups(), static fn(array $g): bool => $g['region_code'] === 'FR'));
        $this->assertSame([$child_1, $child_2], array_column($groups, 'entities_id'));

        // Each entity is removed on its own
        $this->assertSame(10, $importer->purge('yasumi', 'FR', $child_1, 2026));
        $this->assertNotNull(ImportedHoliday::findTracking('yasumi', 'FR', $child_2, 2026, 'christmasDay'));
    }
}
