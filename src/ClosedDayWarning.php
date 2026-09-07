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
use CommonITILTask;
use Entity;
use Html;
use PlanningExternalEvent;
use Session;

/**
 * Warns, without blocking, when a task or an external planning event is
 * planned on a closing period of its entity: the entity of the parent
 * ticket, change or problem for a task, the entity of the event itself
 * for an external event. Mirrors the core "user is busy at the selected
 * timeframe" warning.
 */
final class ClosedDayWarning
{
    /**
     * Plugin hook callback for the item_add and item_update hooks of the
     * ITIL task classes and of the external planning events.
     */
    public static function onTaskSaved(CommonDBTM $item): void
    {
        $entities_id = self::getEntityToCheck($item);
        if ($entities_id === null) {
            return;
        }

        // Same opt-outs as the core check (items moved from the planning view)
        if (
            ($item->input['_do_not_check_already_planned'] ?? false) === true
            || isset($item->input['_no_check_plan'])
        ) {
            return;
        }

        // On update, only when the planning changed
        if ($item->updates !== [] && !array_intersect(['begin', 'end'], $item->updates)) {
            return;
        }

        $begin = $item->fields['begin'] ?? null;
        $end   = $item->fields['end'] ?? null;
        if (!is_string($begin) || $begin === '' || !is_string($end) || $end === '') {
            return;
        }

        $days = (new ClosingPeriods())->getBetween(substr($begin, 0, 10), substr($end, 0, 10), $entities_id);
        if ($days === []) {
            return;
        }

        $lines = [];
        foreach ($days as $day) {
            $lines[] = '- ' . htmlescape(self::describe($day));
        }

        Session::addMessageAfterRedirect(
            htmlescape(sprintf(
                $item instanceof PlanningExternalEvent
                    ? __('Warning: this event is planned on a close time of the entity %s.', 'feriae')
                    : __('Warning: this task is planned on a close time of the entity %s.', 'feriae'),
                Entity::getFriendlyNameById($entities_id),
            )) . '<br/>' . implode('<br/>', $lines),
            false,
            WARNING,
        );
    }

    /**
     * Entity whose closing periods apply to the item, null when the item
     * is not one we watch.
     */
    private static function getEntityToCheck(CommonDBTM $item): ?int
    {
        if ($item instanceof CommonITILTask) {
            $parent = $item->getItem();
            return $parent instanceof CommonDBTM ? Row::int($parent->fields, 'entities_id') : null;
        }

        if ($item instanceof PlanningExternalEvent) {
            return Row::int($item->fields, 'entities_id');
        }

        return null;
    }

    public static function describe(ClosingDay $day): string
    {
        if ($day->isSingleDay()) {
            return sprintf('%s (%s)', $day->name, Html::convDate($day->begin));
        }

        return sprintf('%s (%s - %s)', $day->name, Html::convDate($day->begin), Html::convDate($day->end));
    }
}
