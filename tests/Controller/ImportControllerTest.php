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

namespace GlpiPlugin\Feriae\Tests\Controller;

use DBmysql;
use Calendar;
use Calendar_Holiday;
use CronTask;
use Entity;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Feriae\AutomaticImport;
use GlpiPlugin\Feriae\Controller\ImportController;
use GlpiPlugin\Feriae\Import\HolidayImporter;
use GlpiPlugin\Feriae\ImportedHoliday;
use GlpiPlugin\Feriae\PluginConfig;
use GlpiPlugin\Feriae\Source\HolidayType;
use Holiday;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class ImportControllerTest extends DbTestCase
{
    private function countImported(): int
    {
        return countElementsInTable(ImportedHoliday::getTable());
    }

    public function testEveryRouteRequiresTheConfigRight(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2020', 'years_count' => '1', 'automatic' => '1']));

        $tracked   = $this->countImported();
        $automatic = AutomaticImport::getAll()[0];

        $this->login('normal');
        $this->assertSame(0, (int) Session::haveRight('config', UPDATE));
        $group = ['source' => 'yasumi', 'region_code' => 'FR', 'entities_id' => '0', 'year' => '2020'];
        $calls = [
            'import'                      => static fn() => $controller->import(Request::create('', 'POST', ['region_code' => 'BE', 'year_from' => '2020'])),
            'preview'                     => static fn() => $controller->preview(Request::create('', 'GET', ['region_code' => 'FR', 'year' => '2020'])),
            'purge'                       => static fn() => $controller->purge(Request::create('', 'POST', $group)),
            'setRecursive'                => static fn() => $controller->setRecursive(Request::create('', 'POST', $group + ['is_recursive' => '0'])),
            'deleteAutomaticImport'       => static fn() => $controller->deleteAutomaticImport(Request::create('', 'POST', ['id' => (string) $automatic->getID()])),
            'registerAutomaticImport'     => static fn() => $controller->registerAutomaticImport(Request::create('', 'POST', $group)),
            'registerAllAutomaticImports' => $controller->registerAllAutomaticImports(...),
            'purgeAll'                    => $controller->purgeAll(...),
            'days'                        => static fn() => $controller->days(Request::create('', 'GET', $group)),
            'calendars'                   => static fn() => $controller->calendars(Request::create('', 'GET', $group)),
            'runAutomaticImports'         => static fn() => $controller->runAutomaticImports(Request::create('', 'POST')),
            'runOneAutomaticImport'       => static fn() => $controller->runAutomaticImports(Request::create('', 'POST', ['id' => (string) $automatic->getID()])),
        ];

        $refused = [];
        foreach ($calls as $name => $call) {
            try {
                $call();
            } catch (AccessDeniedHttpException) {
                $refused[] = $name;
            }
        }

        $this->assertSame(array_keys($calls), $refused, 'Every route is refused without the right');
        $this->assertSame($tracked, $this->countImported(), 'Nothing imported nor removed');
        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'BE', 0, 2020, 'newYearsDay'));
        $this->assertCount(1, AutomaticImport::getAll(), 'The automatic import is kept, and none registered');
        $this->assertEmpty(AutomaticImport::getAll()[0]->fields['last_run'], 'Nothing ran');
        $this->assertSame(1, (int) AutomaticImport::getAll()[0]->fields['is_recursive']);
        $task = new CronTask();
        $task->getFromDBbyName(AutomaticImport::class, AutomaticImport::CRON_NAME);
        $this->assertEmpty($task->fields['lastrun'], 'The automatic action was not launched');

        // The tabs of the plugin page render nothing either
        ob_start();
        PluginConfig::displayTabContentForItem(new PluginConfig(), 1);
        PluginConfig::displayTabContentForItem(new PluginConfig(), 2);
        $this->assertSame('', (string) ob_get_clean());
    }

    public function testImportCreatesPeriodsAndRemembersTheSettings(): void
    {
        $this->login('glpi');
        $calendars_id = getItemByTypeName(Calendar::class, 'Default', true);

        $response = (new ImportController())->import(Request::create('', 'POST', [
            'source'       => 'yasumi',
            'region_code'  => 'fr-57',
            'year_from'    => '2026',
            'years_count'  => '2',
            'types'        => ['official', 'bogus'],
            'entities_id'  => '0',
            'is_recursive' => '0',
            'calendars_id' => [(string) $calendars_id, '999999'],
            'skip_existing' => '0',
            'fixed_as_recurrent' => '0',
        ]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringEndsWith('/plugins/feriae/front/config.form.php', $response->getTargetUrl());
        // 12 official holidays in Moselle, two years
        $this->assertSame(24, $this->countImported());

        $tracking = ImportedHoliday::findTracking('yasumi', 'FR-57', 0, 2027, 'goodFriday');
        $this->assertNotNull($tracking);
        $holiday = new Holiday();
        $holiday->getFromDB((int) $tracking->fields['holidays_id']);
        $this->assertSame(0, (int) $holiday->fields['is_recursive']);

        $config = PluginConfig::getConfig();
        $this->assertSame('FR-57', $config['region_code']);
        $this->assertSame('2', $config['years_count']);
        $this->assertSame(['official'], PluginConfig::decodeList($config['types']));
        $this->assertSame([(string) $calendars_id], PluginConfig::decodeList($config['calendars_id']), 'Unknown calendars are ignored');
        $this->assertSame('0', $config['is_recursive']);
        $this->assertSame('0', $config['skip_existing']);
        $this->assertSame('0', $config['fixed_as_recurrent']);
    }

    public function testImportRejectsYearsOutOfRange(): void
    {
        $this->login('glpi');
        $controller = new ImportController();

        foreach (['999', '99999', '-1', '0'] as $year) {
            $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => $year]));
        }

        $this->assertSame(0, $this->countImported());
        $this->hasSessionMessages(ERROR, array_fill(0, 4, 'The year must be between 1970 and 2100.'));
    }

    public function testImportStopsAtTheLastAllowedYear(): void
    {
        $this->login('glpi');

        (new ImportController())->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2099', 'years_count' => '10']));

        $this->assertNotNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, 2100, 'newYearsDay'));
        $this->assertSame([2100, 2099], array_column((new HolidayImporter())->getImportGroups(), 'year'));
    }

    public function testImportDropsTheCalendarsOfTheParentEntities(): void
    {
        $this->login('glpi');
        $child_1 = getItemByTypeName(Entity::class, '_test_child_1', true);
        $own     = (new Calendar())->add(['name' => 'Child calendar', 'entities_id' => $child_1, 'is_recursive' => 0]);
        $root    = getItemByTypeName(Calendar::class, 'Default', true);
        $this->setEntity('_test_child_1', false);

        (new ImportController())->import(Request::create('', 'POST', [
            'region_code'  => 'FR',
            'year_from'    => '2026',
            'entities_id'  => (string) $child_1,
            'calendars_id' => [(string) $root, (string) $own],
        ]));

        $this->assertGreaterThan(0, $this->countImported(), 'The close times are created');
        $this->assertSame(0, countElementsInTable(Calendar_Holiday::getTable(), ['calendars_id' => $root]), 'Not linked to the shared calendar of the root entity');
        $this->assertGreaterThan(0, countElementsInTable(Calendar_Holiday::getTable(), ['calendars_id' => $own]));
        $this->assertSame([(string) $own], PluginConfig::decodeList(PluginConfig::getConfig()['calendars_id']), 'Only the accepted calendar is remembered');
    }

    public function testImportRejectsUnknownRegions(): void
    {
        $this->login('glpi');

        (new ImportController())->import(Request::create('', 'POST', ['region_code' => 'XX', 'year_from' => '2026']));

        $this->assertSame(0, $this->countImported());
        $this->hasSessionMessages(ERROR, ['Please select a country or a region.']);
    }

    public function testImportRefusesAnEntityOutOfReach(): void
    {
        $this->login('glpi');
        $this->setEntity('_test_child_1', false);
        $child_2 = getItemByTypeName(Entity::class, '_test_child_2', true);

        try {
            (new ImportController())->import(Request::create('', 'POST', [
                'region_code' => 'FR',
                'year_from'   => '2026',
                'entities_id' => (string) $child_2,
            ]));
            $this->fail('Expected an access denied exception');
        } catch (AccessDeniedHttpException) {
        }

        $this->assertSame(0, $this->countImported(), 'Nothing imported, in no entity');
    }

    public function testTheActionsOnAnImportRefuseAnEntityOutOfReach(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $child_2    = getItemByTypeName(Entity::class, '_test_child_2', true);
        $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2026', 'entities_id' => (string) $child_2, 'automatic' => '1']));
        $tracked   = $this->countImported();
        $automatic = AutomaticImport::getAll()[0];
        $this->assertSame($child_2, (int) $automatic->fields['entities_id']);

        $this->setEntity('_test_child_1', false);
        $group = ['source' => 'yasumi', 'region_code' => 'FR', 'entities_id' => (string) $child_2, 'year' => '2026'];
        $calls = [
            'purge'                   => static fn() => $controller->purge(Request::create('', 'POST', $group)),
            'setRecursive'            => static fn() => $controller->setRecursive(Request::create('', 'POST', $group + ['is_recursive' => '0'])),
            'days'                    => static fn() => $controller->days(Request::create('', 'GET', $group)),
            'calendars'               => static fn() => $controller->calendars(Request::create('', 'GET', $group)),
            'registerAutomaticImport' => static fn() => $controller->registerAutomaticImport(Request::create('', 'POST', $group)),
            'deleteAutomaticImport'   => static fn() => $controller->deleteAutomaticImport(Request::create('', 'POST', ['id' => (string) $automatic->getID()])),
            'runAutomaticImports'     => static fn() => $controller->runAutomaticImports(Request::create('', 'POST', ['id' => (string) $automatic->getID()])),
        ];

        $refused = [];
        foreach ($calls as $name => $call) {
            try {
                $call();
            } catch (AccessDeniedHttpException) {
                $refused[] = $name;
            }
        }

        $this->assertSame(array_keys($calls), $refused, 'Every action on the import of another entity is refused');
        $this->assertSame($tracked, $this->countImported(), 'Nothing removed');
        $this->assertCount(1, AutomaticImport::getAll(), 'The automatic import is kept');
        $this->assertEmpty(AutomaticImport::getAll()[0]->fields['last_run'], 'The automatic import did not run');
        $this->assertSame(1, (int) AutomaticImport::getAll()[0]->fields['is_recursive'], 'The sharing with the child entities is unchanged');
    }

    public function testTheListsOnlyShowTheImportsOfTheEntitiesOfTheSession(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $child_1    = getItemByTypeName(Entity::class, '_test_child_1', true);
        $child_2    = getItemByTypeName(Entity::class, '_test_child_2', true);
        $controller->import(Request::create('', 'POST', ['region_code' => 'BE', 'year_from' => '2026', 'entities_id' => (string) $child_1, 'automatic' => '1']));
        $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2026', 'entities_id' => (string) $child_2, 'automatic' => '1']));
        $this->assertCount(2, (new HolidayImporter())->getAccessibleImportGroups(), 'Both from the root entity with its children');
        $this->assertCount(2, AutomaticImport::getAccessible());

        $this->setEntity('_test_child_1', false);

        $groups = (new HolidayImporter())->getAccessibleImportGroups();
        $this->assertCount(1, $groups);
        $this->assertSame($child_1, $groups[0]['entities_id']);
        $this->assertCount(2, (new HolidayImporter())->getImportGroups(), 'The other import still exists');
        $automatic = AutomaticImport::getAccessible();
        $this->assertCount(1, $automatic);
        $this->assertSame($child_1, (int) $automatic[0]->fields['entities_id']);

        $tabs = (new PluginConfig())->getTabNameForItem(new PluginConfig());
        $this->assertIsArray($tabs);
        $this->assertStringContainsString('>1<', $tabs[2], 'The tab counts the accessible imports');

        ob_start();
        PluginConfig::displayTabContentForItem(new PluginConfig(), 2);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString(sprintf('entities_id=%d&year=2026', $child_1), $html);
        $this->assertStringNotContainsString(sprintf('entities_id=%d&year=2026', $child_2), $html);
        $this->assertStringContainsString('BE', $html);
        $this->assertStringNotContainsString('France', $html);
    }

    public function testTheBulkActionsOnlyReachTheEntitiesOfTheSession(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $year       = (int) date('Y');
        $child_1    = getItemByTypeName(Entity::class, '_test_child_1', true);
        $child_2    = getItemByTypeName(Entity::class, '_test_child_2', true);
        $controller->import(Request::create('', 'POST', ['region_code' => 'BE', 'year_from' => '2020', 'years_count' => '1', 'entities_id' => (string) $child_1]));
        $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2020', 'years_count' => '1', 'entities_id' => (string) $child_2]));
        $this->setEntity('_test_child_1', false);

        // Renew all: only the import of the entity of the session
        $controller->registerAllAutomaticImports();
        $imports = AutomaticImport::getAll();
        $this->assertCount(1, $imports);
        $this->assertSame($child_1, (int) $imports[0]->fields['entities_id']);

        // Run all with an automatic import out of reach: the ones within reach run, without the automatic action
        $this->setEntity('_test_root_entity', true);
        $controller->registerAutomaticImport(Request::create('', 'POST', ['source' => 'yasumi', 'region_code' => 'FR', 'entities_id' => (string) $child_2, 'year' => '2020']));
        $this->assertCount(2, AutomaticImport::getAll());
        $this->setEntity('_test_child_1', false);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

        $controller->runAutomaticImports(Request::create('', 'POST'));

        $this->assertNotNull(ImportedHoliday::findTracking('yasumi', 'BE', $child_1, $year, 'newYearsDay'));
        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'FR', $child_2, $year, 'newYearsDay'), 'The import of the other entity did not run');
        [$belgium, $france] = AutomaticImport::getAll();
        $this->assertNotEmpty($belgium->fields['last_run']);
        $this->assertEmpty($france->fields['last_run']);
        $task = new CronTask();
        $task->getFromDBbyName(AutomaticImport::class, AutomaticImport::CRON_NAME);
        $this->assertEmpty($task->fields['lastrun'], 'The automatic action, which would run every import, was not launched');
        $messages = implode(' ', $_SESSION['MESSAGE_AFTER_REDIRECT'][INFO]);
        $this->assertStringContainsString('BE / _test_child_1', $messages);
        $this->assertStringNotContainsString('FR / _test_child_2', $messages);

        // Remove all: only the imports of the entity of the session
        $controller->purgeAll();

        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'BE', $child_1, 2020, 'newYearsDay'));
        $this->assertNotNull(ImportedHoliday::findTracking('yasumi', 'FR', $child_2, 2020, 'newYearsDay'), 'The import of the other entity is kept');
    }

    public function testPurgeRemovesAGroup(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2026', 'years_count' => '2']));
        $this->assertSame(20, $this->countImported());

        $response = $controller->purge(Request::create('', 'POST', ['source' => 'yasumi', 'region_code' => 'fr', 'entities_id' => '0', 'year' => '2026']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(10, $this->countImported());
        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'newYearsDay'));
    }

    public function testSharingWithChildEntitiesCanBeToggledFromAPreviousImport(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2026', 'years_count' => '2', 'calendars_id' => []]));

        $created = countElementsInTable(Holiday::getTable(), ['comment' => ['LIKE', 'Imported by Feriae%']]);
        $this->assertSame($created, countElementsInTable(Holiday::getTable(), ['comment' => ['LIKE', 'Imported by Feriae%'], 'is_recursive' => 1]));
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

        $response = $controller->setRecursive(Request::create('', 'POST', ['source' => 'yasumi', 'region_code' => 'fr', 'entities_id' => '0', 'is_recursive' => '0']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(0, countElementsInTable(Holiday::getTable(), ['comment' => ['LIKE', 'Imported by Feriae%'], 'is_recursive' => 1]));
        $this->hasSessionMessages(INFO, [sprintf('%d close times of FR now hidden from the child entities.', $created)]);
        $groups = (new HolidayImporter())->getImportGroups();
        $this->assertCount(2, $groups);
        $this->assertFalse($groups[0]['is_recursive']);
        $this->assertFalse($groups[1]['is_recursive']);

        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        $controller->setRecursive(Request::create('', 'POST', ['source' => 'yasumi', 'region_code' => 'fr', 'entities_id' => '0', 'is_recursive' => '1']));
        $this->assertSame($created, countElementsInTable(Holiday::getTable(), ['comment' => ['LIKE', 'Imported by Feriae%'], 'is_recursive' => 1]));
        $this->hasSessionMessages(INFO, [sprintf('%d close times of FR now shown in the child entities.', $created)]);
    }

    public function testImportCanBeKeptAsAnAutomaticImport(): void
    {
        $this->login('glpi');
        $controller = new ImportController();

        $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2026']));
        $this->assertCount(0, AutomaticImport::getAll(), 'Not automatic unless asked');

        $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2026', 'years_count' => '3', 'automatic' => '1']));

        $imports = AutomaticImport::getAll();
        $this->assertCount(1, $imports);
        $this->assertSame('FR', $imports[0]->fields['region_code']);
        $this->assertSame(3, (int) $imports[0]->fields['years_count']);

        $response = $controller->deleteAutomaticImport(Request::create('', 'POST', ['id' => (string) $imports[0]->getID()]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertCount(0, AutomaticImport::getAll());
        $this->assertSame(30, $this->countImported(), 'The close times are kept');
    }

    public function testDeletingAnUnknownAutomaticImportIsAnError(): void
    {
        $this->login('glpi');

        (new ImportController())->deleteAutomaticImport(Request::create('', 'POST', ['id' => '999999']));

        $this->hasSessionMessages(ERROR, ['Nothing to remove.']);
    }

    public function testImportWarnsWhenNoHolidayMatchesTheSelectedTypes(): void
    {
        $this->login('glpi');

        (new ImportController())->import(Request::create('', 'POST', ['region_code' => 'GB-SCT', 'year_from' => '2026', 'types' => ['official']]));

        $this->assertSame(0, $this->countImported());
        $this->hasSessionMessages(WARNING, ['No holiday of the selected types for GB-SCT. This region has: Bank holiday: 10, Other: 1. Tick the matching types and import again.']);
    }

    public function testPreviewCountsTheHolidaysOfARegionByType(): void
    {
        $this->login('glpi');

        $response = (new ImportController())->preview(Request::create('', 'GET', ['region_code' => 'gb-sct', 'year' => '2026']));

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('GB-SCT', $data['region_code']);
        $this->assertSame(2026, $data['year']);
        $this->assertSame(0, $data['types']['official']['count']);
        $this->assertSame(10, $data['types']['bank']['count']);
        $this->assertContains('New Year’s Day', $data['types']['bank']['names']);
    }

    public function testPreviewRejectsUnknownRegions(): void
    {
        $this->login('glpi');

        $response = (new ImportController())->preview(Request::create('', 'GET', ['region_code' => 'XX']));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPreviewRejectsYearsOutOfRange(): void
    {
        $this->login('glpi');

        $response = (new ImportController())->preview(Request::create('', 'GET', ['region_code' => 'FR', 'year' => '2101']));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['error' => 'The year must be between 1970 and 2100.'], json_decode((string) $response->getContent(), true));
    }

    public function testDaysRendersTheListOfAnImport(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $calendars_id = getItemByTypeName(Calendar::class, 'Default', true);
        $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2026', 'calendars_id' => [(string) $calendars_id]]));

        $response = $controller->days(Request::create('', 'GET', ['source' => 'yasumi', 'region_code' => 'fr', 'entities_id' => '0', 'year' => '2026']));

        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        $this->assertStringContainsString('New Year’s Day', $html);
        $this->assertStringContainsString('holiday.form.php', $html);
        $this->assertStringContainsString('<td>1</td>', $html, 'Number of calendars');
        $this->assertStringNotContainsString('>Moved<', $html);

        // A close time transferred to another entity by hand is marked as such
        $tracking = ImportedHoliday::findTracking('yasumi', 'FR', 0, 2026, 'newYearsDay');
        $this->assertNotNull($tracking);
        (new Holiday())->update(['id' => (int) $tracking->fields['holidays_id'], 'entities_id' => getItemByTypeName(Entity::class, '_test_child_1', true)]);
        $html = (string) $controller->days(Request::create('', 'GET', ['source' => 'yasumi', 'region_code' => 'fr', 'entities_id' => '0', 'year' => '2026']))->getContent();
        $this->assertStringContainsString('>Moved<', $html);

        $response = $controller->calendars(Request::create('', 'GET', ['source' => 'yasumi', 'region_code' => 'fr', 'entities_id' => '0', 'year' => '2026']));
        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        $this->assertStringContainsString('calendar.form.php', $html);
        $this->assertStringContainsString('Default', $html);
        $this->assertStringContainsString('<td>10</td>', $html);
        $this->assertSame(400, $controller->calendars(Request::create('', 'GET', ['source' => 'yasumi', 'entities_id' => '0']))->getStatusCode());
        $this->assertStringNotContainsString('Deleted by hand', $html);

        $response = $controller->days(Request::create('', 'GET', ['source' => 'yasumi', 'region_code' => 'FR', 'entities_id' => '0']));
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testRunAutomaticImportsNowLaunchesTheTask(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $year = (int) date('Y');

        $controller->runAutomaticImports(Request::create('', 'POST'));
        $this->hasSessionMessages(ERROR, ['No automatic import to run.']);

        $controller->import(Request::create('', 'POST', ['region_code' => 'BE', 'year_from' => '2020', 'years_count' => '1', 'automatic' => '1']));
        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'BE', 0, $year, 'newYearsDay'));

        $response = $controller->runAutomaticImports(Request::create('', 'POST'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotNull(ImportedHoliday::findTracking('yasumi', 'BE', 0, $year, 'newYearsDay'));
        $task = new CronTask();
        $task->getFromDBbyName(AutomaticImport::class, AutomaticImport::CRON_NAME);
        $this->assertNotEmpty($task->fields['lastrun'], 'Logged and dated like a scheduled run');
        $this->assertSame(CronTask::STATE_WAITING, (int) $task->fields['state']);
        $this->assertStringStartsWith('5 created, 0 updated, 7 unchanged', AutomaticImport::getAll()[0]->fields['last_summary'], 'The 7 fixed dates already have their recurrent periods since the manual import');
    }

    public function testRunningTheAutomaticActionGivesTheSessionBackToTheUser(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $child_1    = getItemByTypeName(Entity::class, '_test_child_1', true);
        $controller->import(Request::create('', 'POST', ['region_code' => 'BE', 'year_from' => '2020', 'years_count' => '1', 'entities_id' => (string) $child_1, 'automatic' => '1']));
        $this->setEntity('_test_child_1', false);
        $keys   = ['glpiactive_entity', 'glpiactive_entity_recursive', 'glpiactiveentities', 'glpiactiveentities_string', 'glpiparententities', 'glpiname', 'glpigroups'];
        $before = array_intersect_key($_SESSION, array_flip($keys));
        $this->assertSame([$child_1 => $child_1], $before['glpiactiveentities']);
        $this->assertFalse(Session::haveAccessToEntity(0));

        $controller->runAutomaticImports(Request::create('', 'POST'));

        $this->assertNotNull(ImportedHoliday::findTracking('yasumi', 'BE', $child_1, (int) date('Y'), 'newYearsDay'), 'The action ran');
        $task = new CronTask();
        $task->getFromDBbyName(AutomaticImport::class, AutomaticImport::CRON_NAME);
        $this->assertNotEmpty($task->fields['lastrun'], 'Through the automatic action');
        $this->assertSame($before, array_intersect_key($_SESSION, array_flip($keys)), 'The session is as it was');
        $this->assertArrayNotHasKey('glpicronuserrunning', $_SESSION);
        $this->assertFalse(Session::haveAccessToEntity(0));
        $this->assertSame('glpi', $_SESSION['glpiname']);
    }

    public function testASingleAutomaticImportCanBeRunNow(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $year = (int) date('Y');
        $controller->import(Request::create('', 'POST', ['region_code' => 'BE', 'year_from' => '2020', 'years_count' => '1', 'automatic' => '1']));
        $controller->import(Request::create('', 'POST', ['region_code' => 'LU', 'year_from' => '2020', 'years_count' => '1', 'automatic' => '1']));
        [$belgium, $luxembourg] = AutomaticImport::getAll();
        $this->assertSame('BE', $belgium->fields['region_code']);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

        $response = $controller->runAutomaticImports(Request::create('', 'POST', ['id' => (string) $belgium->getID()]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotNull(ImportedHoliday::findTracking('yasumi', 'BE', 0, $year, 'newYearsDay'));
        $this->assertNull(ImportedHoliday::findTracking('yasumi', 'LU', 0, $year, 'newYearsDay'), 'Only the requested import ran');
        $this->assertNotEmpty(AutomaticImport::getAll()[0]->fields['last_run']);
        $this->assertEmpty(AutomaticImport::getAll()[1]->fields['last_run']);
        $this->hasSessionMessages(INFO, ['Automatic import of BE / Root entity run: 5 created, 0 updated, 7 unchanged.']);

        $controller->runAutomaticImports(Request::create('', 'POST', ['id' => '999999']));
        $this->hasSessionMessages(ERROR, ['No automatic import to run.']);
    }

    public function testPurgeAllRemovesEveryGroup(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2026', 'years_count' => '2']));
        $controller->import(Request::create('', 'POST', ['region_code' => 'BE', 'year_from' => '2026']));
        $this->assertGreaterThan(0, $this->countImported());
        $created = countElementsInTable(Holiday::getTable(), ['comment' => ['LIKE', 'Imported by Feriae%']]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

        $response = $controller->purgeAll();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(0, $this->countImported());
        $this->assertSame(0, countElementsInTable(Holiday::getTable(), ['comment' => ['LIKE', 'Imported by Feriae%']]));
        $this->hasSessionMessages(INFO, [sprintf('All imports removed: %d close times deleted. Close times entered by hand are untouched.', $created)]);
    }

    public function testAPreviousImportCanBeRegisteredAsAutomatic(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $calendars_id = getItemByTypeName(Calendar::class, 'Default', true);
        $controller->import(Request::create('', 'POST', [
            'region_code'  => 'FR-57',
            'year_from'    => '2026',
            'years_count'  => '2',
            'types'        => ['official', 'observance'],
            'is_recursive' => '0',
            'calendars_id' => [(string) $calendars_id],
        ]));
        $this->assertCount(0, AutomaticImport::getAll());
        $tracked = $this->countImported();

        $response = $controller->registerAutomaticImport(Request::create('', 'POST', ['source' => 'yasumi', 'region_code' => 'fr-57', 'entities_id' => '0', 'year' => '2026']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame($tracked, $this->countImported(), 'Nothing imported again');
        $imports = AutomaticImport::getAll();
        $this->assertCount(1, $imports);
        $request = $imports[0]->toRequest(2030);
        $this->assertSame('FR-57', $request->region_code);
        $this->assertSame([HolidayType::OFFICIAL, HolidayType::OBSERVANCE], $request->types);
        $this->assertFalse($request->is_recursive);
        $this->assertSame([$calendars_id], $request->calendars_id);
        $this->assertTrue($request->fixed_as_recurrent);
        $this->assertSame([2030, 2031], $request->years, 'The 2 years imported for the region, which is also the last number of years used');

        $controller->registerAutomaticImport(Request::create('', 'POST', ['source' => 'yasumi', 'region_code' => 'LU', 'entities_id' => '0', 'year' => '2026']));
        $this->hasSessionMessages(ERROR, ['Nothing is left of this import.']);
    }

    public function testRenewingAnImportKeepsOnlyTheCalendarsTheUserMayAttachTo(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $child_1    = getItemByTypeName(Entity::class, '_test_child_1', true);
        $own        = (new Calendar())->add(['name' => 'Child calendar', 'entities_id' => $child_1, 'is_recursive' => 0]);
        $root       = getItemByTypeName(Calendar::class, 'Default', true);
        // From the root entity, the import is linked to the shared root calendar and to the child's own
        $controller->import(Request::create('', 'POST', ['region_code' => 'BE', 'year_from' => '2026', 'entities_id' => (string) $child_1, 'calendars_id' => [(string) $root, (string) $own]]));
        $this->assertCount(2, (new HolidayImporter())->getImportCalendars('yasumi', 'BE', $child_1, 2026));

        // Renewed from the child entity, where the root calendar may not be attached to
        $this->setEntity('_test_child_1', false);
        $controller->registerAutomaticImport(Request::create('', 'POST', ['source' => 'yasumi', 'region_code' => 'BE', 'entities_id' => (string) $child_1, 'year' => '2026']));
        $this->assertSame([$own], AutomaticImport::getAll()[0]->toRequest(2027)->calendars_id);

        // Renewed from the root entity, which owns the "Default" calendar, both are kept
        $this->setEntity(0, true);
        $controller->registerAllAutomaticImports();
        $this->assertCount(1, AutomaticImport::getAll());
        $this->assertSame([$own, $root], AutomaticImport::getAll()[0]->toRequest(2027)->calendars_id, 'By calendar name');
    }

    public function testRenewingAnImportOfARegionTheSourceDroppedIsAnError(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $this->login('glpi');
        $controller = new ImportController();
        $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2026']));
        $controller->import(Request::create('', 'POST', ['region_code' => 'BE', 'year_from' => '2026']));
        // The source no longer knows the region it was imported under
        $DB->update(ImportedHoliday::getTable(), ['region_code' => 'XX'], ['region_code' => 'FR']);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

        $response = $controller->registerAutomaticImport(Request::create('', 'POST', ['source' => 'yasumi', 'region_code' => 'XX', 'entities_id' => '0', 'year' => '2026']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertCount(0, AutomaticImport::getAll());
        $this->hasSessionMessages(ERROR, ['XX is no longer offered by the source: the import cannot be renewed. Its close times are kept.']);

        $response = $controller->registerAllAutomaticImports();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $imports = AutomaticImport::getAll();
        $this->assertCount(1, $imports, 'The other region is still renewed');
        $this->assertSame('BE', $imports[0]->fields['region_code']);
        $this->hasSessionMessages(WARNING, ['XX is no longer offered by the source: the import cannot be renewed. Its close times are kept.']);
        $this->assertStringContainsString('1 automatic imports registered.', implode(' ', $_SESSION['MESSAGE_AFTER_REDIRECT'][INFO]));
    }

    public function testRenewingAnImportOfASourceThatIsGoneIsAnError(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $this->login('glpi');
        $controller = new ImportController();
        $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2026']));

        $DB->update(ImportedHoliday::getTable(), ['source' => 'gone'], ['region_code' => 'FR']);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

        $controller->registerAutomaticImport(Request::create('', 'POST', ['source' => 'gone', 'region_code' => 'FR', 'entities_id' => '0', 'year' => '2026']));
        $this->hasSessionMessages(ERROR, ['The source gone is no longer available: the import cannot be renewed. Its close times are kept.']);

        $controller->registerAllAutomaticImports();
        $this->hasSessionMessages(WARNING, ['The source gone is no longer available: the import cannot be renewed. Its close times are kept.']);
        $this->assertArrayNotHasKey(ERROR, $_SESSION['MESSAGE_AFTER_REDIRECT'], 'Not "nothing is left": the close times are there');
        $this->assertCount(0, AutomaticImport::getAll());
    }

    public function testAllPreviousImportsCanBeRegisteredAsAutomatic(): void
    {
        $this->login('glpi');
        $controller = new ImportController();
        $child = getItemByTypeName(Entity::class, '_test_child_1', true);

        $controller->registerAllAutomaticImports();
        $this->hasSessionMessages(ERROR, ['Nothing is left of the previous imports.']);

        $controller->import(Request::create('', 'POST', ['region_code' => 'FR', 'year_from' => '2026', 'years_count' => '2']));
        $controller->import(Request::create('', 'POST', ['region_code' => 'BE', 'year_from' => '2026', 'years_count' => '1', 'entities_id' => (string) $child]));
        $controller->import(Request::create('', 'POST', ['region_code' => 'BE', 'year_from' => '2026', 'years_count' => '1']));

        $tracked = $this->countImported();

        $response = $controller->registerAllAutomaticImports();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame($tracked, $this->countImported(), 'Nothing imported again');
        $imports = AutomaticImport::getAll();
        $this->assertCount(3, $imports, 'One per source, region and entity, whatever the number of years');
        $this->assertSame(['BE', 'BE', 'FR'], array_column(array_map(static fn(AutomaticImport $i): array => $i->fields, $imports), 'region_code'));
        $this->assertSame([0, $child, 0], array_map(static fn(AutomaticImport $i): int => (int) $i->fields['entities_id'], $imports));
        $this->assertSame([1, 1, 2], array_map(static fn(AutomaticImport $i): int => (int) $i->fields['years_count'], $imports), 'The last number of years used (1) for Belgium, the 2 years imported for France');

        // Registering again updates rather than duplicates
        $controller->registerAllAutomaticImports();
        $this->assertCount(3, AutomaticImport::getAll());
    }
}
