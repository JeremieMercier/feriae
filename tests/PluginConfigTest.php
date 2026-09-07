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

use Calendar;
use Entity;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Feriae\AutomaticImport;
use GlpiPlugin\Feriae\Controller\ImportController;
use GlpiPlugin\Feriae\PluginConfig;
use Symfony\Component\HttpFoundation\Request;
use GlpiPlugin\Feriae\Source\Yasumi\YasumiSource;

final class PluginConfigTest extends DbTestCase
{
    public function testDefaultsAreInstalled(): void
    {
        $config = PluginConfig::getConfig();

        $this->assertSame('yasumi', $config['source']);
        $this->assertSame('3', $config['years_count']);
        $this->assertSame('1', $config['fixed_as_recurrent']);
        $this->assertSame(['official'], PluginConfig::decodeList($config['types']));
        $this->assertSame([], PluginConfig::decodeList($config['calendars_id']));
        $this->assertSame('1', $config['skip_existing']);
    }

    public function testDecodeListIgnoresGarbage(): void
    {
        $this->assertSame([], PluginConfig::decodeList(''));
        $this->assertSame([], PluginConfig::decodeList('not json'));
        $this->assertSame([], PluginConfig::decodeList('"scalar"'));
        $this->assertSame(['1', 'a'], PluginConfig::decodeList('[1, "a", [2]]'));
    }

    public function testRegionChoicesAreGroupedByCountryWithFullLabels(): void
    {
        $choices = PluginConfig::getRegionChoices(new YasumiSource(), 'fr_FR');

        $this->assertArrayHasKey('France', $choices);
        $this->assertSame('France', $choices['France']['FR']);
        $this->assertSame('Moselle', $choices['France']['FR-57']);
        $this->assertSame('Guadeloupe', $choices['France']['FR-971']);
        $this->assertSame('Tasmania / Northeast', $choices['Australie']['AU-TAS-NE']);
        $this->assertArrayNotHasKey('US-NYSE', $choices['États-Unis'] ?? []);
    }

    public function testCalendarChoicesListTheCalendarsOfTheActiveEntitiesOnly(): void
    {
        $this->login('glpi');
        $child_1 = getItemByTypeName(Entity::class, '_test_child_1', true);
        $own     = (new Calendar())->add(['name' => 'Child calendar', 'entities_id' => $child_1, 'is_recursive' => 0]);
        $root    = getItemByTypeName(Calendar::class, 'Default', true);

        $choices = PluginConfig::getCalendarChoices();
        $this->assertArrayHasKey($root, $choices, 'From the root entity with its children, every calendar');
        $this->assertArrayHasKey($own, $choices);

        $this->setEntity('_test_child_1', false);
        $choices = PluginConfig::getCalendarChoices();
        $this->assertSame([$own => 'Child calendar'], $choices, 'The shared root calendar is visible but not editable from the child');
    }

    public function testThePageHasATabForTheFormAndOneForTheImports(): void
    {
        $this->login('glpi');
        (new ImportController())->import(Request::create('', 'POST', ['region_code' => 'FR-57', 'year_from' => '2026']));

        $config = new PluginConfig();
        $tabs   = $config->getTabNameForItem($config);
        $this->assertIsArray($tabs);
        $this->assertCount(2, $tabs);
        $this->assertStringContainsString('New import', $tabs[1]);
        $this->assertStringContainsString('Imports', $tabs[2]);
        $this->assertStringContainsString('>1<', $tabs[2], 'The Imports tab counts the previous imports');
    }

    public function testTheFormTabRendersTheImportForm(): void
    {
        $this->login('glpi');

        ob_start();
        PluginConfig::displayTabContentForItem(new PluginConfig(), 1);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString("name='region_code'", $html);
        $this->assertStringContainsString('name="automatic"', $html);
        $this->assertStringContainsString('Source and period', $html);
        // The page script and what it reads from the markup
        $this->assertStringContainsString('/plugins/feriae/js/config.js', $html);
        $this->assertStringContainsString('data-feriae-preview-url="/plugins/feriae/import/preview"', $html);
        $this->assertStringContainsString('data-feriae-preview-messages="{&quot;nothing&quot;:', $html);
        $this->assertStringNotContainsString('<script>', $html, 'No inline script left');
        $this->assertStringNotContainsString('ti-calendar-repeat', $html, 'The lists live in their own tab');
        $this->assertStringNotContainsString('id="feriae-modal"', $html);
    }

    public function testTheImportsTabRendersTheImportLists(): void
    {
        $this->login('glpi');
        (new ImportController())->import(Request::create('', 'POST', ['region_code' => 'FR-57', 'year_from' => '2026', 'automatic' => '1']));
        AutomaticImport::getAll()[0]->run(2026);

        ob_start();
        PluginConfig::displayTabContentForItem(new PluginConfig(), 2);
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('name="year_from"', $html, 'The form lives in its own tab');
        // Automatic imports card
        $this->assertStringContainsString('France / Moselle', $html);
        $this->assertStringContainsString('12 unchanged', $html);
        $this->assertStringContainsString('/plugins/feriae/import/automatic/delete', $html);
        // Automatic action state, with the system cron warning (nothing ran in the test database)
        $this->assertStringContainsString('crontask.form.php', $html);
        $this->assertStringContainsString('no automatic action in CLI mode has run', $html);
        // Previous imports card, with the modal listing the days of an import
        $this->assertStringContainsString('/plugins/feriae/import/purge', $html);
        $this->assertStringContainsString('/plugins/feriae/import/days?source=yasumi&region_code=FR-57&entities_id=0&year=2026', $html);
        $this->assertStringContainsString('/plugins/feriae/import/calendars?source=yasumi&region_code=FR-57&entities_id=0&year=2026', $html);
        $this->assertStringContainsString('id="feriae-modal"', $html);
        // Child entities switch, with its confirmation messages
        $this->assertStringContainsString('/plugins/feriae/import/recursive', $html);
        $this->assertStringContainsString('data-feriae-recursive', $html);
        $this->assertStringContainsString('Show the close times of France / Moselle in the child entities of Root entity?', $html, 'Short entity name');
        // The page script and what it reads from the markup
        $this->assertStringContainsString('/plugins/feriae/js/config.js', $html);
        $this->assertStringContainsString('data-feriae-modal-failure="The list could not be loaded."', $html);
        $this->assertStringNotContainsString('<script>', $html, 'No inline script left');
        $this->assertStringContainsString('/plugins/feriae/import/purge/all', $html);
        $this->assertStringContainsString('/plugins/feriae/import/automatic/register', $html);
        $this->assertStringContainsString('/plugins/feriae/import/automatic/register/all', $html);
        $this->assertStringNotContainsString('No close times imported yet.', $html);
    }
}
