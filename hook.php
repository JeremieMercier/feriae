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

use GlpiPlugin\Feriae\AutomaticImport;
use GlpiPlugin\Feriae\Import\HolidayImporter;
use GlpiPlugin\Feriae\ImportedHoliday;
use GlpiPlugin\Feriae\PluginConfig;

/**
 * Plugin install process
 */
function plugin_feriae_install(): bool
{
    $migration = new Migration(PLUGIN_FERIAE_VERSION);

    PluginConfig::install();
    ImportedHoliday::install($migration);
    AutomaticImport::install($migration);

    return true;
}

/**
 * Plugin uninstall process
 *
 * The closing periods created by the imports are removed first, the same
 * way as the "Remove all imports" button of the plugin page: once the
 * tracking table is gone nothing could tell them from the ones entered by
 * hand, which are never touched. Then the tables, the automatic action and
 * the configuration are dropped.
 */
function plugin_feriae_uninstall(): bool
{
    $migration = new Migration(PLUGIN_FERIAE_VERSION);

    $removed = (new HolidayImporter())->purgeAll();
    if ($removed > 0) {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Feriae: %d close times created by the imports have been removed.', 'feriae'),
            $removed,
        )));
    }

    AutomaticImport::uninstall($migration);
    ImportedHoliday::uninstall($migration);
    PluginConfig::uninstall();

    return true;
}
