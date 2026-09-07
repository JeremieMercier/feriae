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

namespace GlpiPlugin\Feriae;

use Throwable;
use Calendar;
use CommonDBTM;
use CronTask;
use DBConnection;
use DBmysql;
use Entity;
use GlpiPlugin\Feriae\Import\HolidayImporter;
use GlpiPlugin\Feriae\Import\ImportReport;
use GlpiPlugin\Feriae\Import\ImportRequest;
use GlpiPlugin\Feriae\Source\HolidayType;
use Migration;
use Session;

use function Safe\json_encode;
use function Safe\strtotime;

/**
 * An import renewed every year by the `importholidays` automatic action:
 * the settings of a manual import (source, region, types, entity,
 * calendars) kept so that the coming years are imported without anyone
 * having to remember to do it.
 *
 * Each run imports a rolling window starting at the current year, the
 * width of which is the number of years of the original import. Imports
 * are idempotent, so running the action daily costs nothing once the
 * window is filled.
 */
final class AutomaticImport extends CommonDBTM
{
    public const CRON_NAME = 'importholidays';

    public static $rightname = 'config';

    public $dohistory = false;

    public static function getTypeName($nb = 0)
    {
        return _n('Automatic import', 'Automatic imports', $nb, 'feriae');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-repeat';
    }

    /**
     * Written by the plugin only: there is no form for these rows, and
     * GLPI would otherwise offer its generic form and the REST API to
     * anyone holding the right, with no check on the foreign keys.
     */
    public function canCreateItem(): bool
    {
        return false;
    }

    public function canUpdateItem(): bool
    {
        return false;
    }

    public function canDeleteItem(): bool
    {
        return false;
    }

    public function canPurgeItem(): bool
    {
        return false;
    }

    /**
     * Remember the settings of an import, replacing a previous automatic
     * import of the same source, region and entity.
     */
    public static function saveFromRequest(ImportRequest $request): self
    {
        $values = [
            'types'         => json_encode(array_map(static fn(HolidayType $t): string => $t->value, $request->types)),
            'is_recursive'  => $request->is_recursive ? 1 : 0,
            'calendars_id'  => json_encode($request->calendars_id),
            'years_count'   => max(1, count($request->years)),
            'locale'        => $request->locale,
            'skip_existing' => $request->skip_existing ? 1 : 0,
            'fixed_as_recurrent' => $request->fixed_as_recurrent ? 1 : 0,
            'is_active'     => 1,
        ];

        $item = new self();
        if ($item->getFromDBByCrit(['source' => $request->source, 'region_code' => $request->region_code, 'entities_id' => $request->entities_id])) {
            $item->update(['id' => $item->getID()] + $values);
            return $item;
        }

        $item->add($values + [
            'source'      => $request->source,
            'region_code' => $request->region_code,
            'entities_id' => $request->entities_id,
        ]);
        return $item;
    }

    /**
     * The import request of this automatic import for the window starting
     * at the given year.
     */
    public function toRequest(int $from_year): ImportRequest
    {
        $types = [];
        foreach (PluginConfig::decodeList(Row::string($this->fields, 'types')) as $value) {
            $type = HolidayType::tryFrom($value);
            if ($type !== null) {
                $types[] = $type;
            }
        }

        return new ImportRequest(
            Row::string($this->fields, 'source'),
            Row::string($this->fields, 'region_code'),
            ImportRequest::yearsRange($from_year, Row::int($this->fields, 'years_count')),
            $types !== [] ? $types : [HolidayType::OFFICIAL],
            Row::int($this->fields, 'entities_id'),
            Row::int($this->fields, 'is_recursive') === 1,
            $this->existingCalendars(array_map(intval(...), PluginConfig::decodeList(Row::string($this->fields, 'calendars_id')))),
            false,
            Row::string($this->fields, 'locale') ?: 'en_GB',
            Row::int($this->fields, 'skip_existing') === 1,
            Row::int($this->fields, 'fixed_as_recurrent') === 1,
        );
    }

    /**
     * The given calendars that still exist. Which calendars an import may
     * target is decided when it is saved, with the rights of the user who
     * saves it; the rows being written by the plugin only, a run just
     * drops the calendars deleted since.
     *
     * @param list<int> $calendars_id
     *
     * @return list<int>
     */
    private function existingCalendars(array $calendars_id): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($calendars_id === []) {
            return [];
        }

        $existing = [];
        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Calendar::getTable(),
            'WHERE'  => ['id' => $calendars_id],
        ]);
        foreach ($iterator as $row) {
            $existing[] = Row::int($row, 'id');
        }

        return array_values(array_intersect($calendars_id, $existing));
    }

    /**
     * Import the window starting at the given year and record the outcome,
     * a failure included: the plugin page shows the last result of each
     * automatic import, and a region the source dropped would otherwise
     * keep showing its last success.
     *
     * @throws Throwable when the source or the region is not available any more
     */
    public function run(int $from_year): ImportReport
    {
        try {
            $report = (new HolidayImporter())->import($this->toRequest($from_year));
        } catch (Throwable $throwable) {
            $this->recordRun($throwable->getMessage());
            throw $throwable;
        }

        $this->recordRun($report->getSummary());

        return $report;
    }

    private function recordRun(string $summary): void
    {
        $this->update([
            'id'           => $this->getID(),
            'last_run'     => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'last_summary' => $summary,
        ]);
    }

    /** "FR-57 / Root entity", for the logs. */
    public function getLabel(): string
    {
        return sprintf(
            '%s / %s',
            Row::string($this->fields, 'region_code'),
            Entity::getFriendlyNameById(Row::int($this->fields, 'entities_id')),
        );
    }

    /**
     * Every automatic import, active or not, sorted by region and entity.
     *
     * @return list<self>
     */
    public static function getAll(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $items = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDERBY' => ['source', 'region_code', 'entities_id']]) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = new self();
            $item->getFromResultSet($row);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * The automatic imports of the entities the session has access to,
     * the only ones the plugin page shows and acts on. The automatic
     * action runs them all.
     *
     * @return list<self>
     */
    public static function getAccessible(): array
    {
        return array_values(array_filter(
            self::getAll(),
            static fn(self $import): bool => Session::haveAccessToEntity(Row::int($import->fields, 'entities_id')),
        ));
    }

    /**
     * @param string $name
     *
     * @return array{description?: string}
     */
    public static function cronInfo($name): array
    {
        return match ($name) {
            self::CRON_NAME => ['description' => __('Import the coming years of the automatic holiday imports', 'feriae')],
            default         => [],
        };
    }

    /**
     * Automatic action: import the rolling window of every active
     * automatic import.
     *
     * @return int 1 when something was created or updated, 0 otherwise
     */
    public static function cronImportHolidays(CronTask $task): int
    {
        $from_year = (int) date('Y');
        $changed   = 0;

        foreach (self::getAll() as $import) {
            if (Row::int($import->fields, 'is_active') !== 1) {
                continue;
            }

            try {
                $report = $import->run($from_year);
            } catch (Throwable $e) {
                $task->log(sprintf('%s: %s', $import->getLabel(), $e->getMessage()));
                continue;
            }

            $task->addVolume($report->created + $report->updated);
            $task->log(sprintf('%s: %s', $import->getLabel(), $report->getSummary()));
            $changed += $report->created + $report->updated;
        }

        return $changed > 0 ? 1 : 0;
    }

    /**
     * The automatic action of the plugin, null when it is not registered.
     */
    public static function getCronTask(): ?CronTask
    {
        $task = new CronTask();
        return $task->getFromDBbyName(self::class, self::CRON_NAME) ? $task : null;
    }

    /**
     * Whether the system cron seems to run: some automatic action in CLI
     * mode ran during the last day. Nothing runs CLI tasks otherwise, so
     * this is the best evidence available from inside GLPI.
     */
    public static function isSystemCronRunning(): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $since = date('Y-m-d H:i:s', strtotime('-1 day'));

        return countElementsInTable(CronTask::getTable(), [
            'mode'    => CronTask::MODE_EXTERNAL,
            'lastrun' => ['>=', $since],
        ]) > 0;
    }

    public static function install(Migration $migration): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $charset   = DBConnection::getDefaultCharset();
            $collation = DBConnection::getDefaultCollation();
            $sign      = DBConnection::getDefaultPrimaryKeySignOption();

            $DB->doQuery(
                "CREATE TABLE `{$table}` (
                    `id` int {$sign} NOT NULL AUTO_INCREMENT,
                    `source` varchar(50) NOT NULL,
                    `region_code` varchar(20) NOT NULL,
                    `types` varchar(255) NOT NULL DEFAULT '[]',
                    `entities_id` int {$sign} NOT NULL DEFAULT '0',
                    `is_recursive` tinyint NOT NULL DEFAULT '1',
                    `calendars_id` text,
                    `years_count` smallint NOT NULL DEFAULT '1',
                    `locale` varchar(10) NOT NULL DEFAULT 'en_GB',
                    `skip_existing` tinyint NOT NULL DEFAULT '1',
                    `fixed_as_recurrent` tinyint NOT NULL DEFAULT '1',
                    `is_active` tinyint NOT NULL DEFAULT '1',
                    `last_run` timestamp NULL DEFAULT NULL,
                    `last_summary` varchar(255) DEFAULT NULL,
                    `date_creation` timestamp NULL DEFAULT NULL,
                    `date_mod` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unicity` (`source`, `region_code`, `entities_id`),
                    KEY `entities_id` (`entities_id`),
                    KEY `is_active` (`is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC",
            );
        }

        // Columns added since the first installs
        $migration->addField($table, 'fixed_as_recurrent', 'bool', ['value' => 1, 'after' => 'skip_existing']);

        $migration->executeMigration();

        // Daily: the imports are idempotent, so the cost of a run that has
        // nothing to do is negligible, and a new year is covered at once.
        // CLI mode, as GLPI recommends for every automatic action: the
        // system cron has to be set up (see README), the plugin page warns
        // when it does not seem to be. Administrators may switch the mode.
        CronTask::register(self::class, self::CRON_NAME, DAY_TIMESTAMP, [
            'state'         => CronTask::STATE_WAITING,
            'mode'          => CronTask::MODE_EXTERNAL,
            'logs_lifetime' => 30,
        ]);
    }

    public static function uninstall(Migration $migration): void
    {
        CronTask::unregister('feriae');
        $migration->dropTable(self::getTable());
        $migration->executeMigration();
    }
}
