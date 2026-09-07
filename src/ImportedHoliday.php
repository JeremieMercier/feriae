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

use CommonDBTM;
use DBConnection;
use DBmysql;
use Holiday;
use Migration;

/**
 * Tracks the closing periods (core `Holiday` items) created by the plugin,
 * so that imports are idempotent and clean-ups only touch what the plugin
 * created, never the periods entered by hand.
 */
final class ImportedHoliday extends CommonDBTM
{
    public static $rightname = 'config';

    public $dohistory = false;

    public static function getTypeName($nb = 0)
    {
        return _n('Imported close time', 'Imported close times', $nb, 'feriae');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-down';
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
     * Tracking row of a holiday of the given source / region / entity /
     * year, or null when it was never imported there.
     */
    public static function findTracking(string $source, string $region_code, int $entities_id, int $year, string $holiday_key): ?self
    {
        $item = new self();
        if (
            $item->getFromDBByCrit([
                'source'      => $source,
                'region_code' => $region_code,
                'entities_id' => $entities_id,
                'year'        => $year,
                'holiday_key' => $holiday_key,
            ])
        ) {
            return $item;
        }

        return null;
    }

    /**
     * The recurrent closing period a holiday already became through another
     * year of the same source / region / entity import, if any.
     */
    public static function findRecurrentHolidayId(string $source, string $region_code, int $entities_id, string $holiday_key): ?int
    {
        /** @var DBmysql $DB */
        global $DB;

        $tracking = self::getTable();
        $holidays = Holiday::getTable();

        $iterator = $DB->request([
            'SELECT'     => [$tracking . '.holidays_id'],
            'FROM'       => $tracking,
            'INNER JOIN' => [
                $holidays => ['ON' => [$tracking => 'holidays_id', $holidays => 'id']],
            ],
            'WHERE'      => [
                $tracking . '.source'      => $source,
                $tracking . '.region_code' => $region_code,
                $tracking . '.entities_id' => $entities_id,
                $tracking . '.holiday_key' => $holiday_key,
                $holidays . '.is_perpetual' => 1,
            ],
            'LIMIT'      => 1,
        ]);

        foreach ($iterator as $row) {
            return Row::int($row, 'holidays_id');
        }

        return null;
    }

    /**
     * Number of imports (years) a closing period is tracked by: a recurrent
     * period is shared by every year that imported it.
     */
    /**
     * Source and region each of the given holidays was imported from:
     * holidays_id => list of [source, region_code], without duplicates
     * (a recurrent period is tracked once per year, for the same region).
     *
     * @param list<int> $holidays_ids
     *
     * @return array<int, list<array{source: string, region_code: string}>>
     */
    public static function getOriginsOf(array $holidays_ids): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($holidays_ids === []) {
            return [];
        }

        $iterator = $DB->request([
            'SELECT'   => ['holidays_id', 'source', 'region_code'],
            'DISTINCT' => true,
            'FROM'     => self::getTable(),
            'WHERE'    => ['holidays_id' => $holidays_ids],
            'ORDER'    => ['holidays_id', 'source', 'region_code'],
        ]);

        $origins = [];
        foreach ($iterator as $row) {
            $origins[Row::int($row, 'holidays_id')][] = [
                'source'      => Row::string($row, 'source'),
                'region_code' => Row::string($row, 'region_code'),
            ];
        }

        return $origins;
    }

    public static function countReferences(int $holidays_id): int
    {
        return countElementsInTable(self::getTable(), ['holidays_id' => $holidays_id]);
    }

    public function getHolidaysId(): int
    {
        return Row::int($this->fields, 'holidays_id');
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
                    `holidays_id` int {$sign} NOT NULL DEFAULT '0',
                    `source` varchar(50) NOT NULL,
                    `region_code` varchar(20) NOT NULL,
                    `entities_id` int {$sign} NOT NULL DEFAULT '0',
                    `year` smallint NOT NULL,
                    `holiday_key` varchar(100) NOT NULL,
                    `holiday_date` date NOT NULL,
                    `date_creation` timestamp NULL DEFAULT NULL,
                    `date_mod` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unicity` (`source`, `region_code`, `entities_id`, `year`, `holiday_key`),
                    KEY `holidays_id` (`holidays_id`),
                    KEY `entities_id` (`entities_id`),
                    KEY `holiday_date` (`holiday_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC",
            );
        } elseif (!$DB->fieldExists($table, 'entities_id')) {
            // The entity joined the tracking key: the same region can now be
            // imported in several entities. Existing rows take the entity
            // of their closing period.
            $migration->addField($table, 'entities_id', 'fkey', ['after' => 'region_code']);
            $migration->addKey($table, 'entities_id');
            $migration->executeMigration();

            $holidays = Holiday::getTable();
            $DB->doQuery(
                "UPDATE `{$table}` INNER JOIN `{$holidays}` ON `{$holidays}`.`id` = `{$table}`.`holidays_id`
                    SET `{$table}`.`entities_id` = `{$holidays}`.`entities_id`",
            );

            // Two steps: the migration would otherwise try to add the new
            // key before dropping the old one of the same name
            $migration->dropKey($table, 'unicity');
            $migration->executeMigration();
            $migration->addKey($table, ['source', 'region_code', 'entities_id', 'year', 'holiday_key'], 'unicity', 'UNIQUE');
        }

        $migration->executeMigration();
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::getTable());
        $migration->executeMigration();
    }
}
