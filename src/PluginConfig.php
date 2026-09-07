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
use CommonGLPI;
use Config;
use Entity;
use CronTask;
use DBmysql;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Feriae\Import\HolidayImporter;
use GlpiPlugin\Feriae\Import\ImportRequest;
use GlpiPlugin\Feriae\Source\HolidaySource;
use GlpiPlugin\Feriae\Source\HolidayType;
use GlpiPlugin\Feriae\Source\Region;
use GlpiPlugin\Feriae\Source\SourceRegistry;
use Session;

use function Safe\json_decode;
use function Safe\json_encode;

/**
 * Plugin configuration page (Setup > Plugins > Feriae): a tab with the
 * import form and a tab with the automatic and previous imports.
 */
final class PluginConfig extends CommonGLPI
{
    public const CONTEXT = 'plugin:feriae';

    public static $rightname = 'config';

    public static function getTypeName($nb = 0)
    {
        return 'Feriae';
    }

    public static function getIcon(): string
    {
        return 'ti ti-calendar-off';
    }

    /**
     * Stored configuration: the last import settings, used to prefill the
     * form (and, later, by the automatic import).
     *
     * @return array<string, string>
     */
    public static function getDefaults(): array
    {
        return [
            'source'       => SourceRegistry::getDefault()->getKey(),
            'region_code'  => '',
            'years_count'  => '3',
            'types'        => json_encode([HolidayType::OFFICIAL->value]),
            'is_recursive' => '1',
            'calendars_id' => json_encode([]),
            'skip_existing' => '1',
            'fixed_as_recurrent' => '1',
        ];
    }

    /** @return array<string, string> */
    public static function getConfig(): array
    {
        $values = [];
        foreach (Config::getConfigurationValues(self::CONTEXT) as $name => $value) {
            if (is_scalar($value)) {
                $values[(string) $name] = (string) $value;
            }
        }

        return $values + self::getDefaults();
    }

    /** @param array<string, string> $values */
    public static function setConfig(array $values): void
    {
        Config::setConfigurationValues(self::CONTEXT, array_intersect_key($values, self::getDefaults()));
    }

    /**
     * @return list<string>
     */
    public static function decodeList(string $value): array
    {
        try {
            $decoded = json_decode($value, true);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map(strval(...), array_filter($decoded, is_scalar(...))));
    }

    public static function getRootDoc(): string
    {
        /** @var array<string, mixed> $CFG_GLPI */
        global $CFG_GLPI;

        $root_doc = $CFG_GLPI['root_doc'] ?? '';

        return is_string($root_doc) ? $root_doc : '';
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addStandardTab(self::class, $ong, $options);
        // The "All" tab would stack the form and the lists, and load the page script twice
        $ong['no_all_tab'] = true;
        return $ong;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof self) {
            return [
                1 => self::createTabEntry(__('New import', 'feriae'), 0, $item::class, 'ti ti-calendar-down'),
                2 => self::createTabEntry(__('Imports', 'feriae'), count((new HolidayImporter())->getAccessibleImportGroups()), $item::class, 'ti ti-history'),
            ];
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof self) {
            if ((int) $tabnum === 2) {
                self::showImports();
            } else {
                self::showImportForm();
            }

            return true;
        }

        return false;
    }

    private static function showImportForm(): void
    {
        if (!Session::haveRight(self::$rightname, UPDATE)) {
            return;
        }

        $config  = self::getConfig();
        $locale  = self::getSessionLocale();
        $sources = [];
        foreach (SourceRegistry::all() as $key => $source) {
            $sources[$key] = $source->getName();
        }

        $source = self::getConfiguredSource($config);

        $types = [];
        foreach (HolidayType::cases() as $type) {
            $types[$type->value] = $type->getLabel();
        }

        TemplateRenderer::getInstance()->display('@feriae/import.html.twig', [
            'config'          => $config,
            'sources'         => $sources,
            'source'          => $source,
            'regions'         => self::getRegionChoices($source, $locale),
            'types'           => $types,
            'selected_types'  => self::decodeList($config['types']),
            'calendars'       => self::getCalendarChoices(),
            'selected_calendars' => array_map(intval(...), self::decodeList($config['calendars_id'])),
            'current_year'    => (int) date('Y'),
            'min_year'        => ImportRequest::MIN_YEAR,
            'max_year'        => ImportRequest::MAX_YEAR,
            'form_action'     => self::getRootDoc() . '/plugins/feriae/import',
            'preview_action'  => self::getRootDoc() . '/plugins/feriae/import/preview',
        ]);
    }

    /**
     * The "Imports" tab: the automatic imports with the state of the
     * automatic action, and the previous imports with their actions.
     */
    private static function showImports(): void
    {
        if (!Session::haveRight(self::$rightname, UPDATE)) {
            return;
        }

        $locale      = self::getSessionLocale();
        $regions     = self::getConfiguredSource(self::getConfig())->getRegions($locale);
        $region_name = static fn(string $code): string => isset($regions[$code]) ? self::getRegionLabel($regions[$code], $regions) : $code;
        $source_name = static fn(string $key): string => SourceRegistry::has($key) ? SourceRegistry::get($key)->getName() : $key;

        $groups = [];
        foreach ((new HolidayImporter())->getAccessibleImportGroups() as $group) {
            $group['source_name'] = $source_name($group['source']);
            $group['region_name'] = $region_name($group['region_code']);
            // Short name for the confirmation messages, the cell shows the full path
            $entity               = new Entity();
            $group['entity_name'] = $entity->getFromDB($group['entities_id']) ? Row::string($entity->fields, 'name') : (string) $group['entities_id'];
            $groups[]             = $group;
        }

        $automatic_imports = [];
        foreach (AutomaticImport::getAccessible() as $import) {
            $automatic_imports[] = [
                'id'           => $import->getID(),
                'source_name'  => $source_name(Row::string($import->fields, 'source')),
                'region_code'  => Row::string($import->fields, 'region_code'),
                'region_name'  => $region_name(Row::string($import->fields, 'region_code')),
                'entities_id'  => Row::int($import->fields, 'entities_id'),
                'is_recursive' => Row::int($import->fields, 'is_recursive') === 1,
                'years_count'  => Row::int($import->fields, 'years_count'),
                'calendars_id' => array_map(intval(...), self::decodeList(Row::string($import->fields, 'calendars_id'))),
                'is_active'    => Row::int($import->fields, 'is_active') === 1,
                'last_run'     => Row::string($import->fields, 'last_run'),
                'last_summary' => Row::string($import->fields, 'last_summary'),
            ];
        }

        $cron_task = AutomaticImport::getCronTask();
        $cron      = null;
        if ($cron_task instanceof CronTask) {
            $mode   = Row::int($cron_task->fields, 'mode');
            $is_cli = $mode === CronTask::MODE_EXTERNAL;
            $cron   = [
                'url'       => $cron_task->getLinkURL(),
                'mode_name' => CronTask::getModeName($is_cli ? CronTask::MODE_EXTERNAL : CronTask::MODE_INTERNAL),
                'is_cli'    => $is_cli,
                'lastrun'   => Row::string($cron_task->fields, 'lastrun'),
                // Only worth a warning when the task relies on the system cron
                'system_cron_missing' => $is_cli && !AutomaticImport::isSystemCronRunning(),
            ];
        }

        TemplateRenderer::getInstance()->display('@feriae/imports.html.twig', [
            'groups'          => $groups,
            'automatic_imports' => $automatic_imports,
            'cron'            => $cron,
            'purge_action'    => self::getRootDoc() . '/plugins/feriae/import/purge',
            'recursive_action' => self::getRootDoc() . '/plugins/feriae/import/recursive',
            'purge_all_action' => self::getRootDoc() . '/plugins/feriae/import/purge/all',
            'automatic_register_action' => self::getRootDoc() . '/plugins/feriae/import/automatic/register',
            'automatic_register_all_action' => self::getRootDoc() . '/plugins/feriae/import/automatic/register/all',
            'days_action'     => self::getRootDoc() . '/plugins/feriae/import/days',
            'calendars_action' => self::getRootDoc() . '/plugins/feriae/import/calendars',
            'automatic_delete_action' => self::getRootDoc() . '/plugins/feriae/import/automatic/delete',
            'automatic_run_action' => self::getRootDoc() . '/plugins/feriae/import/automatic/run',
        ]);
    }

    /**
     * @param array<string, string> $config
     */
    private static function getConfiguredSource(array $config): HolidaySource
    {
        return SourceRegistry::has($config['source']) ? SourceRegistry::get($config['source']) : SourceRegistry::getDefault();
    }

    /**
     * Regions grouped by country for a select with optgroups:
     * country label => [region code => label]. Inside a group the country
     * is implied, so a subdivision is labelled "Moselle" or
     * "Tasmania / Northeast" rather than repeating "France / ...".
     *
     * @return array<string, array<string, string>>
     */
    public static function getRegionChoices(HolidaySource $source, string $locale): array
    {
        $regions = $source->getRegions($locale);
        $choices = [];
        foreach ($regions as $region) {
            $country = $regions[$region->countryCode] ?? null;
            $group   = $country !== null ? $country->name : $region->countryCode;
            $label   = self::getRegionLabel($region, $regions);
            if (!$region->isCountry() && $country !== null && str_starts_with($label, $country->name . ' / ')) {
                $label = substr($label, strlen($country->name) + 3);
            }

            $choices[$group][$region->code] = $label;
        }

        return $choices;
    }

    /**
     * "France" for a country, "France / Moselle" for a subdivision.
     *
     * @param array<string, Region> $regions
     */
    public static function getRegionLabel(Region $region, array $regions): string
    {
        $labels  = [$region->name];
        $current = $region;
        while ($current->parentCode !== null && isset($regions[$current->parentCode])) {
            $current  = $regions[$current->parentCode];
            $labels[] = $current->name;
        }

        return implode(' / ', array_reverse($labels));
    }

    /**
     * Calendars the user may attach close times to: id => name. Those of
     * the active entities only, not the ones of the parent entities that
     * are merely shared with them: seeing a calendar is not editing it,
     * and GLPI itself requires the update right on the calendar to link a
     * close time to it.
     *
     * @return array<int, string>
     */
    public static function getCalendarChoices(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $choices  = [];
        $iterator = $DB->request([
            'SELECT'  => ['id', 'name'],
            'FROM'    => Calendar::getTable(),
            'WHERE'   => getEntitiesRestrictCriteria(Calendar::getTable(), '', '', false),
            'ORDERBY' => 'name',
        ]);
        foreach ($iterator as $row) {
            $choices[Row::int($row, 'id')] = Row::string($row, 'name');
        }

        return $choices;
    }

    public static function getSessionLocale(): string
    {
        $language = $_SESSION['glpilanguage'] ?? '';
        return is_string($language) && $language !== '' ? $language : 'en_GB';
    }

    public static function install(): void
    {
        $missing = array_diff_key(self::getDefaults(), Config::getConfigurationValues(self::CONTEXT));
        if ($missing !== []) {
            Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    public static function uninstall(): void
    {
        Config::deleteConfigurationValues(self::CONTEXT, array_keys(Config::getConfigurationValues(self::CONTEXT)));
    }
}
