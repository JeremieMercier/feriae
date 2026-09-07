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

use GlpiPlugin\Feriae\ClosingDay;
use Calendar;
use Calendar_Holiday;
use DBmysql;
use Entity;
use GlpiPlugin\Feriae\AutomaticImport;
use GlpiPlugin\Feriae\ClosingPeriods;
use GlpiPlugin\Feriae\ImportedHoliday;
use GlpiPlugin\Feriae\Row;
use GlpiPlugin\Feriae\Source\HolidayEntry;
use GlpiPlugin\Feriae\Source\HolidaySource;
use GlpiPlugin\Feriae\Source\HolidayType;
use GlpiPlugin\Feriae\Source\SourceRegistry;
use Holiday;
use Session;

/**
 * Turns the holidays of a source into GLPI closing periods (core `Holiday`
 * items) attached to calendars, keeping track of what it created.
 *
 * Re-running an import is safe: existing periods are refreshed rather than
 * duplicated, and a period the user deleted by hand is not recreated
 * unless the import explicitly asks to replace everything.
 *
 * Holidays falling on the same date every year become, by default, one
 * recurrent closing period shared by the years of the import (tracked by
 * each of them, removed with the last one); the moving ones are one dated
 * period per year.
 */
final readonly class HolidayImporter
{
    public function __construct(private ?HolidaySource $source = null) {}

    public function import(ImportRequest $request): ImportReport
    {
        $source = $this->source ?? SourceRegistry::get($request->source);
        $report = new ImportReport();

        if ($request->replace) {
            foreach ($request->years as $year) {
                $report->removed += $this->purge($source->getKey(), $request->region_code, $request->entities_id, $year);
            }
        }

        $fixed = $request->fixed_as_recurrent
            ? FixedDates::detect($source, $request->region_code, $request->years, $request->locale)
            : [];

        foreach ($request->years as $year) {
            foreach ($source->getHolidays($request->region_code, $year, $request->locale) as $entry) {
                if (!$request->accepts($entry->type)) {
                    continue;
                }

                $report->found++;
                $this->importEntry($source, $entry, $year, $request, $report, isset($fixed[$entry->key]));
            }
        }

        return $report;
    }

    /**
     * Remove the closing periods created by a previous import of the given
     * source / region / entity / year, with their calendar links and
     * tracking rows.
     *
     * @return int number of closing periods removed
     */
    public function purge(string $source, string $region_code, int $entities_id, int $year): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $removed = 0;
        $rows    = $DB->request([
            'FROM'  => ImportedHoliday::getTable(),
            'WHERE' => [
                'source'      => $source,
                'region_code' => $region_code,
                'entities_id' => $entities_id,
                'year'        => $year,
            ],
        ]);

        foreach ($rows as $row) {
            $holidays_id = Row::int($row, 'holidays_id');
            (new ImportedHoliday())->delete(['id' => Row::int($row, 'id')], true);

            // A recurrent period shared with another year stays as long as
            // that year is tracked
            if (ImportedHoliday::countReferences($holidays_id) > 0) {
                continue;
            }

            // A tracking row only vouches for a period of its own entity:
            // the plugin created them together, anything else is a row
            // rewritten behind its back
            $holiday = new Holiday();
            if ($holiday->getFromDB($holidays_id) && Row::int($holiday->fields, 'entities_id') === $entities_id) {
                // Purge: also drops the calendar links (Holiday::cleanDBonPurge)
                $holiday->delete(['id' => $holiday->getID()], true);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * The settings a previous import was made with, as far as they can be
     * read back from what it created: child entities, calendars, types
     * (from the holidays tracked), recurrent periods, and the years
     * imported for the same source, region and entity. Null when nothing
     * is left of the import.
     *
     * @return array{is_recursive: bool, calendars_id: list<int>, types: list<HolidayType>, fixed_as_recurrent: bool, years: list<int>}|null
     */
    public function getGroupSettings(string $source, string $region_code, int $entities_id, int $year, string $locale): ?array
    {
        /** @var DBmysql $DB */
        global $DB;

        $tracking = ImportedHoliday::getTable();
        $holidays = Holiday::getTable();

        $iterator = $DB->request([
            'SELECT'     => [
                $tracking . '.holiday_key',
                $holidays . '.is_recursive',
                $holidays . '.is_perpetual',
            ],
            'FROM'       => $tracking,
            'INNER JOIN' => [
                $holidays => ['ON' => [$tracking => 'holidays_id', $holidays => 'id']],
            ],
            'WHERE'      => [
                $tracking . '.source'      => $source,
                $tracking . '.region_code' => $region_code,
                $tracking . '.entities_id' => $entities_id,
                $tracking . '.year'        => $year,
            ],
        ]);

        $keys         = [];
        $is_recursive = false;
        $recurrent    = false;
        foreach ($iterator as $row) {
            $keys[Row::string($row, 'holiday_key')] = true;
            $is_recursive = $is_recursive || Row::int($row, 'is_recursive') === 1;
            $recurrent    = $recurrent || Row::int($row, 'is_perpetual') === 1;
        }

        if ($keys === []) {
            return null;
        }

        $types = [];
        foreach (SourceRegistry::get($source)->getHolidays($region_code, $year, $locale) as $entry) {
            if (isset($keys[$entry->key])) {
                $types[$entry->type->value] = $entry->type;
            }
        }

        $years = [];
        foreach ($DB->request([
            'SELECT'   => 'year',
            'DISTINCT' => true,
            'FROM'     => $tracking,
            'WHERE'    => ['source' => $source, 'region_code' => $region_code, 'entities_id' => $entities_id],
        ]) as $row) {
            $years[] = Row::int($row, 'year');
        }

        sort($years);

        return [
            'is_recursive'       => $is_recursive,
            'calendars_id'       => array_column($this->getImportCalendars($source, $region_code, $entities_id, $year), 'id'),
            'types'              => array_values($types) !== [] ? array_values($types) : [HolidayType::OFFICIAL],
            'fixed_as_recurrent' => $recurrent,
            'years'              => $years,
        ];
    }

    /**
     * Share, or stop sharing, with the child entities the closing periods
     * created by the imports of a source / region / entity, whatever the
     * year: recurrent periods are shared between years. The automatic
     * import of the same region and entity follows, so that its next runs
     * create the coming years the same way.
     *
     * A period a calendar of a child entity uses cannot be withdrawn from
     * it, the calendar would keep a period its entity cannot see: it is
     * left as is and counted as blocked. GLPI's own check on child
     * entities does not look at calendar links.
     *
     * @return array{updated: int, blocked: int}
     */
    public function setRecursive(string $source, string $region_code, int $entities_id, bool $is_recursive): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $rows = $DB->request([
            'SELECT'   => ['holidays_id'],
            'DISTINCT' => true,
            'FROM'     => ImportedHoliday::getTable(),
            'WHERE'    => [
                'source'      => $source,
                'region_code' => $region_code,
                'entities_id' => $entities_id,
            ],
        ]);

        $result = ['updated' => 0, 'blocked' => 0];
        foreach ($rows as $row) {
            $holiday = new Holiday();
            if (!$holiday->getFromDB(Row::int($row, 'holidays_id')) || Row::int($holiday->fields, 'entities_id') !== $entities_id) {
                continue;
            }

            if ($holiday->isRecursive() === $is_recursive) {
                continue;
            }

            if (!$is_recursive && (!$holiday->canUnrecurs() || $this->isUsedByACalendarOutside($holiday))) {
                $result['blocked']++;
                continue;
            }

            if ($holiday->update(['id' => $holiday->getID(), 'is_recursive' => $is_recursive ? 1 : 0])) {
                $result['updated']++;
            }
        }

        foreach (AutomaticImport::getAll() as $import) {
            if (
                Row::string($import->fields, 'source') === $source
                && Row::string($import->fields, 'region_code') === $region_code
                && Row::int($import->fields, 'entities_id') === $entities_id
            ) {
                $import->update(['id' => $import->getID(), 'is_recursive' => $is_recursive ? 1 : 0]);
            }
        }

        return $result;
    }

    /**
     * Whether a calendar of an entity other than the one of the closing
     * period, or its ancestors, is linked to it.
     */
    private function isUsedByACalendarOutside(Holiday $holiday): bool
    {
        $entities_id = Row::int($holiday->fields, 'entities_id');
        $entities    = getAncestorsOf(Entity::getTable(), $entities_id);
        $entities[]  = $entities_id;

        $links     = Calendar_Holiday::getTable();
        $calendars = Calendar::getTable();

        return countElementsInTable(
            [$links, $calendars],
            [
                $links . '.holidays_id' => $holiday->getID(),
                'FKEY'                 => [$links => 'calendars_id', $calendars => 'id'],
                'NOT'                  => [$calendars . '.entities_id' => $entities],
            ],
        ) > 0;
    }

    /**
     * Remove everything every import created: each batch in turn, so that
     * shared recurrent periods go with their last year.
     *
     * @return int number of closing periods removed
     */
    public function purgeAll(): int
    {
        $removed = 0;
        foreach ($this->getImportGroups() as $group) {
            $removed += $this->purge($group['source'], $group['region_code'], $group['entities_id'], $group['year']);
        }

        return $removed;
    }

    /**
     * The past imports of the entities the session has access to, the
     * only ones the plugin page shows and acts on.
     *
     * @return list<array{source: string, region_code: string, entities_id: int, year: int, tracked: int, existing: int, is_recursive: bool}>
     */
    public function getAccessibleImportGroups(): array
    {
        return array_values(array_filter(
            $this->getImportGroups(),
            static fn(array $group): bool => Session::haveAccessToEntity($group['entities_id']),
        ));
    }

    /**
     * Past imports, grouped by source / region / entity / year.
     *
     * @return list<array{source: string, region_code: string, entities_id: int, year: int, tracked: int, existing: int, is_recursive: bool}>
     */
    public function getImportGroups(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $tracking = ImportedHoliday::getTable();
        $holidays = Holiday::getTable();

        $iterator = $DB->request([
            'SELECT'    => [
                $tracking . '.source',
                $tracking . '.region_code',
                $tracking . '.entities_id',
                $tracking . '.year',
                'COUNT' => $tracking . '.id AS tracked',
                'COUNT DISTINCT' => $holidays . '.id AS existing',
                'MAX' => $holidays . '.is_recursive AS is_recursive',
            ],
            'FROM'      => $tracking,
            'LEFT JOIN' => [
                $holidays => [
                    'ON' => [
                        $tracking => 'holidays_id',
                        $holidays => 'id',
                    ],
                ],
            ],
            'GROUPBY'   => [$tracking . '.source', $tracking . '.region_code', $tracking . '.entities_id', $tracking . '.year'],
            'ORDERBY'   => [$tracking . '.source', $tracking . '.region_code', $tracking . '.entities_id', $tracking . '.year DESC'],
        ]);

        $groups = [];
        foreach ($iterator as $row) {
            $groups[] = [
                'source'      => Row::string($row, 'source'),
                'region_code' => Row::string($row, 'region_code'),
                'entities_id' => Row::int($row, 'entities_id'),
                'year'        => Row::int($row, 'year'),
                'tracked'     => Row::int($row, 'tracked'),
                'existing'    => Row::int($row, 'existing'),
                'is_recursive' => Row::int($row, 'is_recursive') === 1,
            ];
        }

        return $groups;
    }

    /**
     * The days of a previous import, with the closing period each one
     * became (null when it was deleted by hand since) and the number of
     * calendars it is attached to.
     *
     * A day is "moved" when its close time was transferred to another
     * entity since the import: the plugin then leaves it alone, the way it
     * does with a period entered by hand.
     *
     * @return list<array{holiday_key: string, holiday_date: string, holidays_id: int, name: string|null, entities_id: int|null, is_perpetual: bool, is_moved: bool, calendars: int}>
     */
    public function getImportedDays(string $source, string $region_code, int $entities_id, int $year): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $tracking = ImportedHoliday::getTable();
        $holidays = Holiday::getTable();

        $iterator = $DB->request([
            'SELECT'    => [
                $tracking . '.holiday_key',
                $tracking . '.holiday_date',
                $tracking . '.holidays_id',
                $holidays . '.name',
                $holidays . '.entities_id',
                $holidays . '.is_perpetual',
            ],
            'FROM'      => $tracking,
            'LEFT JOIN' => [
                $holidays => ['ON' => [$tracking => 'holidays_id', $holidays => 'id']],
            ],
            'WHERE'     => [
                $tracking . '.source'      => $source,
                $tracking . '.region_code' => $region_code,
                $tracking . '.entities_id' => $entities_id,
                $tracking . '.year'        => $year,
            ],
            'ORDERBY'   => [$tracking . '.holiday_date', $tracking . '.holiday_key'],
        ]);

        $days = [];
        foreach ($iterator as $row) {
            $days[] = [
                'holiday_key'  => Row::string($row, 'holiday_key'),
                'holiday_date' => Row::string($row, 'holiday_date'),
                'holidays_id'  => Row::int($row, 'holidays_id'),
                'name'         => is_array($row) && isset($row['name']) && is_string($row['name']) ? $row['name'] : null,
                'entities_id'  => Row::nullableInt($row, 'entities_id'),
                'is_perpetual' => Row::int($row, 'is_perpetual') === 1,
                'is_moved'     => Row::nullableInt($row, 'entities_id') !== null && Row::int($row, 'entities_id') !== $entities_id,
                'calendars'    => 0,
            ];
        }

        $calendars = $this->getCalendarNames(array_column($days, 'holidays_id'));
        foreach ($days as &$day) {
            $day['calendars'] = count($calendars[$day['holidays_id']] ?? []);
        }

        unset($day);

        return $days;
    }

    /**
     * @param bool $recurrent the holiday falls on the same date every year and
     *                        is wanted as a recurrent closing period
     */
    /**
     * The calendars the closing periods of a previous import are attached
     * to, with the number of those periods each one holds.
     *
     * @return list<array{id: int, name: string, entities_id: int, holidays: int}>
     */
    public function getImportCalendars(string $source, string $region_code, int $entities_id, int $year): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $tracking  = ImportedHoliday::getTable();
        $links     = Calendar_Holiday::getTable();
        $calendars = Calendar::getTable();

        $iterator = $DB->request([
            'SELECT'     => [
                $calendars . '.id',
                $calendars . '.name',
                $calendars . '.entities_id',
                'COUNT DISTINCT' => $links . '.holidays_id AS holidays',
            ],
            'FROM'       => $tracking,
            'INNER JOIN' => [
                $links     => ['ON' => [$tracking => 'holidays_id', $links => 'holidays_id']],
                $calendars => ['ON' => [$links => 'calendars_id', $calendars => 'id']],
            ],
            'WHERE'      => [
                $tracking . '.source'      => $source,
                $tracking . '.region_code' => $region_code,
                $tracking . '.entities_id' => $entities_id,
                $tracking . '.year'        => $year,
            ],
            'GROUPBY'    => [$calendars . '.id'],
            'ORDERBY'    => $calendars . '.name',
        ]);

        $result = [];
        foreach ($iterator as $row) {
            $result[] = [
                'id'          => Row::int($row, 'id'),
                'name'        => Row::string($row, 'name'),
                'entities_id' => Row::int($row, 'entities_id'),
                'holidays'    => Row::int($row, 'holidays'),
            ];
        }

        return $result;
    }

    /**
     * Names of the calendars each closing period is attached to.
     *
     * @param list<int> $holidays_id
     *
     * @return array<int, list<string>>
     */
    private function getCalendarNames(array $holidays_id): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($holidays_id === []) {
            return [];
        }

        $links     = Calendar_Holiday::getTable();
        $calendars = Calendar::getTable();

        $iterator = $DB->request([
            'SELECT'     => [$links . '.holidays_id', $calendars . '.name'],
            'FROM'       => $links,
            'INNER JOIN' => [
                $calendars => ['ON' => [$links => 'calendars_id', $calendars => 'id']],
            ],
            'WHERE'      => [$links . '.holidays_id' => $holidays_id],
            'ORDERBY'    => $calendars . '.name',
        ]);

        $names = [];
        foreach ($iterator as $row) {
            $names[Row::int($row, 'holidays_id')][] = Row::string($row, 'name');
        }

        return $names;
    }

    private function importEntry(HolidaySource $source, HolidayEntry $entry, int $year, ImportRequest $request, ImportReport $report, bool $recurrent): void
    {
        $tracking = ImportedHoliday::findTracking($source->getKey(), $request->region_code, $request->entities_id, $year, $entry->key);
        $holiday  = new Holiday();

        if ($tracking instanceof ImportedHoliday) {
            if (!$holiday->getFromDB($tracking->getHolidaysId())) {
                // Deleted by hand since the previous import: respect that
                $report->skipped++;
                return;
            }

            $changes = [];
            if ($holiday->fields['name'] !== $entry->name) {
                $changes['name'] = $entry->name;
            }

            $is_perpetual = Row::int($holiday->fields, 'is_perpetual') === 1;
            if ($is_perpetual !== $recurrent) {
                $changes['is_perpetual'] = $recurrent ? 1 : 0;
            }

            // A recurrent period is shared by several years: only its month
            // and day matter
            $same_date = $recurrent && $is_perpetual
                ? substr(Row::string($holiday->fields, 'begin_date'), 5) === substr($entry->getDateString(), 5)
                    && substr(Row::string($holiday->fields, 'end_date'), 5) === substr($entry->getDateString(), 5)
                : $holiday->fields['begin_date'] === $entry->getDateString() && $holiday->fields['end_date'] === $entry->getDateString();
            if (!$same_date) {
                $changes['begin_date'] = $entry->getDateString();
                $changes['end_date']   = $entry->getDateString();
            }

            if ($changes !== []) {
                $holiday->update(['id' => $holiday->getID()] + $changes);
                $tracking->update(['id' => $tracking->getID(), 'holiday_date' => $entry->getDateString()]);
                $report->updated++;
            } else {
                $report->unchanged++;
            }
        } else {
            if ($recurrent) {
                // Already a recurrent period through another year of the
                // same import: share it, and track it for this year too
                $shared_id = ImportedHoliday::findRecurrentHolidayId($source->getKey(), $request->region_code, $request->entities_id, $entry->key);
                if ($shared_id !== null && $holiday->getFromDB($shared_id)) {
                    (new ImportedHoliday())->add([
                        'holidays_id'  => $shared_id,
                        'source'       => $source->getKey(),
                        'region_code'  => $request->region_code,
                        'entities_id'  => $request->entities_id,
                        'year'         => $year,
                        'holiday_key'  => $entry->key,
                        'holiday_date' => $entry->getDateString(),
                    ]);
                    $report->unchanged++;
                    $report->linked += $this->linkToCalendars($shared_id, $request->calendars_id);
                    return;
                }
            }

            if ($request->skip_existing) {
                $existing = (new ClosingPeriods())->findCovering($entry->getDateString(), $request->entities_id);
                if ($existing instanceof ClosingDay) {
                    // Reused, not tracked: it does not belong to this import
                    // and must survive its removal.
                    $report->duplicates++;
                    $report->linked += $this->linkToCalendars($existing->id, $request->calendars_id);
                    return;
                }
            }

            $holidays_id = $holiday->add([
                'name'         => $entry->name,
                'comment'      => sprintf(
                    __('Imported by Feriae from %1$s (%2$s, %3$d)', 'feriae'),
                    $source->getName(),
                    $request->region_code,
                    $year,
                ),
                'entities_id'  => $request->entities_id,
                'is_recursive' => $request->is_recursive ? 1 : 0,
                'begin_date'   => $entry->getDateString(),
                'end_date'     => $entry->getDateString(),
                'is_perpetual' => $recurrent ? 1 : 0,
            ]);
            if ($holidays_id === false) {
                return;
            }

            (new ImportedHoliday())->add([
                'holidays_id'  => $holidays_id,
                'source'       => $source->getKey(),
                'region_code'  => $request->region_code,
                'entities_id'  => $request->entities_id,
                'year'         => $year,
                'holiday_key'  => $entry->key,
                'holiday_date' => $entry->getDateString(),
            ]);
            $report->created++;
        }

        $report->linked += $this->linkToCalendars((int) $holiday->getID(), $request->calendars_id);
    }

    /**
     * @param list<int> $calendars_id
     *
     * @return int number of links created
     */
    private function linkToCalendars(int $holidays_id, array $calendars_id): int
    {
        $linked = 0;
        foreach ($calendars_id as $calendars_id_item) {
            $link = new Calendar_Holiday();
            if ($link->getFromDBByCrit(['calendars_id' => $calendars_id_item, 'holidays_id' => $holidays_id])) {
                continue;
            }

            if ($link->add(['calendars_id' => $calendars_id_item, 'holidays_id' => $holidays_id]) !== false) {
                $linked++;
            }
        }

        return $linked;
    }
}
