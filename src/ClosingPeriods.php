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

use DBmysql;
use Holiday;

/**
 * Reads the GLPI closing periods (core `Holiday` items) of an entity.
 *
 * Shared by the import duplicate check, the planning display and the
 * closed day warning, so the entity visibility and recurrence rules live
 * in one place: a period is taken into account when it belongs to the
 * entity, or to an ancestor with child entities enabled; recurrent
 * periods are replayed every year.
 */
final class ClosingPeriods
{
    /**
     * Occurrences of the closing periods overlapping [$begin, $end]
     * (Y-m-d dates, inclusive), sorted by date.
     *
     * @return list<ClosingDay>
     */
    public function getBetween(string $begin, string $end, int $entities_id): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($end < $begin) {
            return [];
        }

        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'entities_id', 'is_recursive', 'begin_date', 'end_date', 'is_perpetual'],
            'FROM'   => Holiday::getTable(),
            'WHERE'  => [
                'OR' => [
                    ['is_perpetual' => 0, 'begin_date' => ['<=', $end], 'end_date' => ['>=', $begin]],
                    ['is_perpetual' => 1],
                ],
            ] + getEntitiesRestrictCriteria(Holiday::getTable(), '', $entities_id, true),
            'ORDER'  => 'id',
        ]);

        $days = [];
        foreach ($iterator as $row) {
            $period_begin = Row::string($row, 'begin_date');
            $period_end   = Row::string($row, 'end_date');
            if (strlen($period_begin) !== 10 || strlen($period_end) !== 10) {
                continue;
            }

            $period = [
                'id'           => Row::int($row, 'id'),
                'name'         => Row::string($row, 'name'),
                'entities_id'  => Row::int($row, 'entities_id'),
                'is_recursive' => Row::int($row, 'is_recursive') === 1,
            ];

            if (Row::int($row, 'is_perpetual') !== 1) {
                $days[] = new ClosingDay(...$period, begin: $period_begin, end: $period_end, is_perpetual: false);
                continue;
            }

            // Recurrent: replay the month-day range on every year of the window
            $begin_md = substr($period_begin, 5);
            $end_md   = substr($period_end, 5);
            for ($year = (int) substr($begin, 0, 4) - 1; $year <= (int) substr($end, 0, 4); $year++) {
                $occurrence_begin = $year . '-' . $begin_md;
                // A range crossing the new year (e.g. 12-24 to 01-02) ends the year after
                $occurrence_end = ($end_md < $begin_md ? $year + 1 : $year) . '-' . $end_md;
                if ($occurrence_begin <= $end && $occurrence_end >= $begin) {
                    $days[] = new ClosingDay(...$period, begin: $occurrence_begin, end: $occurrence_end, is_perpetual: true);
                }
            }
        }

        usort($days, static fn(ClosingDay $a, ClosingDay $b): int => [$a->begin, $a->id] <=> [$b->begin, $b->id]);

        return $days;
    }

    /**
     * First closing period covering the given day, if any.
     */
    public function findCovering(string $date, int $entities_id): ?ClosingDay
    {
        $days = $this->getBetween($date, $date, $entities_id);
        return $days[0] ?? null;
    }
}
