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

use Glpi\Tests\DbTestCase;
use GlpiPlugin\Feriae\Source\SourceRegistry;
use GlpiPlugin\Feriae\Source\Yasumi\YasumiSource;

/**
 * The plugin entry points of setup.php: version, prerequisites, init.
 */
final class SetupTest extends DbTestCase
{
    public function testVersionInfo(): void
    {
        $version = plugin_version_feriae();

        $this->assertSame('Feriae', $version['name']);
        $this->assertSame(PLUGIN_FERIAE_VERSION, $version['version']);
        $this->assertSame('MIT', $version['license']);
        $this->assertSame(PLUGIN_FERIAE_MIN_GLPI_VERSION, $version['requirements']['glpi']['min']);
        $this->assertSame(PLUGIN_FERIAE_MAX_GLPI_VERSION, $version['requirements']['glpi']['max']);
    }

    public function testPrerequisitesAreMetWhenYasumiIsInstalled(): void
    {
        $this->assertTrue(plugin_feriae_has_dependencies());

        ob_start();
        $result = plugin_feriae_check_prerequisites();
        $output = ob_get_clean();

        $this->assertTrue($result);
        $this->assertSame('', $output, 'Nothing is echoed when the prerequisites are met');
    }

    public function testInitRegistersTheYasumiSource(): void
    {
        SourceRegistry::reset();

        plugin_init_feriae();

        $this->assertTrue(SourceRegistry::has(YasumiSource::KEY));
        $this->assertSame(YasumiSource::KEY, SourceRegistry::getDefault()->getKey());
    }
}
