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
use Yasumi\Yasumi;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Feriae\ClosedDayWarning;
use GlpiPlugin\Feriae\PlanningClosedDay;
use GlpiPlugin\Feriae\Source\SourceRegistry;
use GlpiPlugin\Feriae\Source\Yasumi\YasumiSource;

// Third-party dependencies (Yasumi) live in the plugin's own vendor directory.
// The release archive ships it; a git checkout needs `composer install`.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_FERIAE_VERSION', '0.1.0');

// Minimal GLPI version, inclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_FERIAE_MIN_GLPI_VERSION", "11.0.0");

// Maximum GLPI version, exclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_FERIAE_MAX_GLPI_VERSION", "11.0.99");

/**
 * Init hooks of the plugin.
 * REQUIRED
 */
function plugin_init_feriae(): void
{
    /** @var array<string, array<string, mixed>> $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    // GLPI deactivates the plugin when the prerequisites are not met, but
    // never rely on it: without Yasumi there is simply no source to offer.
    if (!plugin_feriae_has_dependencies()) {
        return;
    }

    // Holiday data sources. Everything else in the plugin only relies on the
    // HolidaySource interface, so sources can be added or swapped here.
    SourceRegistry::register(new YasumiSource());

    // Closing periods shown in the planning (filter, colour, events)
    Plugin::registerClass(PlanningClosedDay::class, ['planning_types' => true]);

    // Non-blocking warning when a task or an external planning event is
    // planned on a closing period of its entity
    $task_hooks = [];
    foreach ([TicketTask::class, ChangeTask::class, ProblemTask::class, PlanningExternalEvent::class] as $task_class) {
        $task_hooks[$task_class] = ClosedDayWarning::onTaskSaved(...);
    }

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['feriae']    = $task_hooks;
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['feriae'] = $task_hooks;

    if (!Session::getLoginUserID()) {
        return;
    }

    // Configuration page (wrench icon on the plugin card)
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['feriae'] = 'front/config.form.php';

    // Planning: closing periods on top of the events of a day. The script
    // guards itself so it only acts on the planning page.
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['feriae'] = ['js/planning.js'];
    PlanningClosedDay::initDefaultFilterColor();
}

/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array{
 *      name: string,
 *      version: string,
 *      author: string,
 *      license: string,
 *      homepage: string,
 *      requirements: array{
 *          glpi: array{
 *              min: string,
 *              max: string,
 *          }
 *      }
 * }
 */
function plugin_version_feriae(): array
{
    return [
        'name'           => 'Feriae',
        'version'        => PLUGIN_FERIAE_VERSION,
        'author'         => 'Jérémie Mercier',
        'license'        => 'MIT',
        'homepage'       => 'https://github.com/JeremieMercier/feriae',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_FERIAE_MIN_GLPI_VERSION,
                'max' => PLUGIN_FERIAE_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

/**
 * Whether the third-party dependencies of the plugin are available.
 */
function plugin_feriae_has_dependencies(): bool
{
    return class_exists(Yasumi::class);
}

/**
 * Check pre-requisites before install
 * OPTIONAL
 *
 * GLPI shows what is echoed here next to its "prerequisites are not
 * matching" message, and refuses to install or activate the plugin.
 */
function plugin_feriae_check_prerequisites(): bool
{
    if (!plugin_feriae_has_dependencies()) {
        echo htmlescape(__('The Yasumi library is missing: install the plugin from its release archive, or run "composer install --no-dev" in the plugin directory.', 'feriae'));
        return false;
    }

    return true;
}

/**
 * Check configuration process
 * OPTIONAL
 *
 * @param bool $verbose Whether to display message on failure. Defaults to false.
 */
function plugin_feriae_check_config(bool $verbose = false): bool
{
    // Your configuration check
    return true;

    // Example:
    // if ($verbose) {
    //    echo __('Installed / not configured', 'feriae');
    // }
    // return false;
}
