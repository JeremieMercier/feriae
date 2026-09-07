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

use GlpiPlugin\Feriae\Source\Region;
use CommonGLPI;
use Entity;
use GlpiPlugin\Feriae\Source\SourceRegistry;
use Safe\DateTimeImmutable;
use Session;

/**
 * Planning type showing the closing periods of the active entities as
 * read-only all-day events, so that planners see closed days at a glance.
 *
 * Registered with `planning_types` at plugin init: GLPI then lists it in
 * the planning filters (checkbox and colour) and calls populatePlanning()
 * for every displayed actor and date range.
 */
final class PlanningClosedDay extends CommonGLPI
{
    public static $rightname = 'planning';

    /**
     * Colour given to the planning filter the first time it shows up for a
     * user. GLPI would otherwise pick one from its palette by order of
     * appearance. Users remain free to change it afterwards.
     */
    public const DEFAULT_FILTER_COLOR = '#ffd2d4';

    public static function getTypeName($nb = 0)
    {
        return _n('Close time', 'Close times', $nb, 'feriae');
    }

    public static function getIcon(): string
    {
        return 'ti ti-calendar-off';
    }

    /**
     * @param array<string, mixed> $options begin, end, who, whogroup, color, event_type_color, check_planned
     *
     * @return array<string, array<string, mixed>>
     */
    public static function populatePlanning($options = []): array
    {
        // Closed days are information for the planner, not a conflict: never
        // report them to the "already planned" or availability checks.
        if (($options['check_planned'] ?? false) === true) {
            return [];
        }

        $begin = $options['begin'] ?? null;
        $end   = $options['end'] ?? null;
        if (!is_string($begin) || !is_string($end) || $begin === '' || $end === '') {
            return [];
        }

        $entities = $_SESSION['glpiactiveentities'] ?? [];
        if (!is_array($entities)) {
            return [];
        }

        $type_color = is_string($options['event_type_color'] ?? null) ? $options['event_type_color'] : '';

        $events = [];
        foreach ($entities as $entities_id) {
            if (!is_numeric($entities_id)) {
                continue;
            }

            foreach ((new ClosingPeriods())->getBetween(substr($begin, 0, 10), substr($end, 0, 10), (int) $entities_id) as $day) {
                $key = 'feriae-' . $day->id . '-' . $day->begin;
                if (isset($events[$key])) {
                    continue;
                }

                $events[$key] = [
                    'itemtype'         => self::class,
                    'id'               => $day->id,
                    'name'             => $day->name,
                    'content'          => '',
                    // Whole days: midnight to midnight after the last day
                    'begin'            => $day->begin . ' 00:00:00',
                    'end'              => (new DateTimeImmutable($day->end))->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
                    'users_id'         => 0,
                    'editable'         => false,
                    // Closed days are not tied to a person: use the colour of
                    // the planning filter rather than the one of the actor
                    'color'            => $type_color,
                    'event_type_color' => $type_color,
                    'entities_id'      => $day->entities_id,
                    'is_perpetual'     => $day->is_perpetual,
                    'region'           => '',
                ];
            }
        }

        // Imported days tell which country or region they belong to
        $regions = self::getRegionNames(array_values(array_unique(array_column($events, 'id'))));
        foreach ($events as &$event) {
            $event['region'] = $regions[$event['id']] ?? '';
        }

        unset($event);

        return $events;
    }

    /**
     * Country or region names (e.g. "France / Moselle") of the closing
     * periods the plugin imported: holidays_id => names, several ones
     * separated by commas. Periods entered by hand have none.
     *
     * @param list<int> $holidays_ids
     *
     * @return array<int, string>
     */
    private static function getRegionNames(array $holidays_ids): array
    {
        $locale = PluginConfig::getSessionLocale();
        /** @var array<string, array<string, Region>> $regions_by_source */
        $regions_by_source = [];

        $names = [];
        foreach (ImportedHoliday::getOriginsOf($holidays_ids) as $holidays_id => $origins) {
            $labels = [];
            foreach ($origins as $origin) {
                $key = $origin['source'];
                if (!isset($regions_by_source[$key])) {
                    $regions_by_source[$key] = SourceRegistry::has($key) ? SourceRegistry::get($key)->getRegions($locale) : [];
                }

                $regions  = $regions_by_source[$key];
                $code     = $origin['region_code'];
                $labels[] = isset($regions[$code]) ? PluginConfig::getRegionLabel($regions[$code], $regions) : $code;
            }

            $names[$holidays_id] = implode(', ', array_unique($labels));
        }

        return $names;
    }

    /**
     * Content of the event in the planning (and its tooltip when $complete).
     *
     * @param array<string, mixed> $val
     */
    public static function displayPlanningItem(array $val, mixed $who, string $type = '', bool $complete = false): string
    {
        $html = '<i class="ti ti-calendar-off me-1"></i>' . htmlescape(Row::string($val, 'name'));

        if ($complete) {
            $html .= '<br/>' . htmlescape(sprintf('%s: %s', Entity::getTypeName(1), Entity::getFriendlyNameById(Row::int($val, 'entities_id'))));
            // Imported days: the country or region, behind the globe of the import form
            $region = Row::string($val, 'region');
            if ($region !== '') {
                $html .= '<br/><i class="ti ti-world me-1"></i>' . htmlescape($region);
            }

            if (($val['is_perpetual'] ?? false) === true) {
                $html .= '<br/>' . htmlescape(__('Recurrent'));
            }
        }

        return $html;
    }

    /**
     * Create the planning filter of the current user with the default colour
     * when it does not exist yet. An existing filter, whatever its colour,
     * is left untouched: the colour belongs to the user.
     */
    public static function initDefaultFilterColor(): void
    {
        $plannings = $_SESSION['glpi_plannings'] ?? null;
        $filters   = is_array($plannings) ? ($plannings['filters'] ?? null) : null;
        if (!is_array($filters) || isset($filters[self::class])) {
            return;
        }

        $filters[self::class] = [
            'color'   => self::DEFAULT_FILTER_COLOR,
            'display' => true,
            'type'    => 'event_filter',
        ];
        $plannings['filters']       = $filters;
        $_SESSION['glpi_plannings'] = $plannings;
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::$rightname, READ);
    }
}
