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

namespace GlpiPlugin\Feriae\Controller;

use Throwable;
use Glpi\Application\View\TemplateRenderer;
use CronTask;
use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Feriae\AutomaticImport;
use GlpiPlugin\Feriae\Import\HolidayImporter;
use GlpiPlugin\Feriae\Import\ImportPreview;
use GlpiPlugin\Feriae\Import\ImportRequest;
use GlpiPlugin\Feriae\PluginConfig;
use GlpiPlugin\Feriae\Row;
use GlpiPlugin\Feriae\Source\HolidayType;
use GlpiPlugin\Feriae\Source\SourceRegistry;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function Safe\json_encode;

final class ImportController extends AbstractController
{
    private const MAX_YEARS = 10;

    #[Route(path: 'import', name: 'import', methods: ['POST'])]
    public function import(Request $request): RedirectResponse
    {
        Session::checkRight(PluginConfig::$rightname, UPDATE);

        $import_request = $this->buildRequest($request);
        if (!$import_request instanceof ImportRequest) {
            return $this->redirectToPluginPage();
        }

        $report = (new HolidayImporter())->import($import_request);

        if ($request->request->getBoolean('automatic', false)) {
            AutomaticImport::saveFromRequest($import_request);
        }

        PluginConfig::setConfig([
            'source'       => $import_request->source,
            'region_code'  => $import_request->region_code,
            'years_count'  => (string) count($import_request->years),
            'types'        => json_encode(array_map(static fn(HolidayType $t): string => $t->value, $import_request->types)),
            'is_recursive' => $import_request->is_recursive ? '1' : '0',
            'calendars_id' => json_encode($import_request->calendars_id),
            'skip_existing' => $import_request->skip_existing ? '1' : '0',
            'fixed_as_recurrent' => $import_request->fixed_as_recurrent ? '1' : '0',
        ]);

        if ($report->found === 0) {
            // Nothing of the selected types: say what the region does have,
            // the types being far from consistent from one country to another
            $available = ImportPreview::describe(ImportPreview::forRegion(
                SourceRegistry::get($import_request->source),
                $import_request->region_code,
                $import_request->years[0],
                $import_request->locale,
            ));
            Session::addMessageAfterRedirect(htmlescape(
                $available === ''
                    ? sprintf(__('No holiday found for %s.', 'feriae'), $import_request->region_code)
                    : sprintf(__('No holiday of the selected types for %1$s. This region has: %2$s. Tick the matching types and import again.', 'feriae'), $import_request->region_code, $available),
            ), false, WARNING);

            return $this->redirectToPluginPage();
        }

        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Close times of %1$s imported: %2$s.', 'feriae'),
            $import_request->region_code,
            $report->getSummary(),
        )));

        return $this->redirectToPluginPage();
    }

    /**
     * Counts by type of the holidays of a region for a year, for the form.
     */
    #[Route(path: 'import/preview', name: 'import_preview', methods: ['GET'])]
    public function preview(Request $request): JsonResponse
    {
        Session::checkRight(PluginConfig::$rightname, UPDATE);

        $source_key = (string) $request->query->get('source', '');
        if (!SourceRegistry::has($source_key)) {
            $source_key = SourceRegistry::getDefault()->getKey();
        }

        $source      = SourceRegistry::get($source_key);
        $region_code = strtoupper(trim((string) $request->query->get('region_code', '')));
        $year        = $request->query->getInt('year', (int) date('Y'));

        if ($region_code === '' || !$source->supportsRegion($region_code)) {
            return new JsonResponse(['error' => __('Please select a country or a region.', 'feriae')], 400);
        }

        if (!ImportRequest::isValidYear($year)) {
            return new JsonResponse(['error' => $this->getYearError()], 400);
        }

        return new JsonResponse([
            'region_code' => $region_code,
            'year'        => $year,
            'types'       => ImportPreview::forRegion($source, $region_code, $year, PluginConfig::getSessionLocale()),
        ]);
    }

    #[Route(path: 'import/purge', name: 'import_purge', methods: ['POST'])]
    public function purge(Request $request): RedirectResponse
    {
        Session::checkRight(PluginConfig::$rightname, UPDATE);

        $source      = (string) $request->request->get('source', '');
        $region_code = strtoupper((string) $request->request->get('region_code', ''));
        $entities_id = $request->request->getInt('entities_id', -1);
        $year        = $request->request->getInt('year');

        if ($source === '' || $region_code === '' || $entities_id < 0 || $year === 0) {
            Session::addMessageAfterRedirect(htmlescape(__('Nothing to remove.', 'feriae')), false, ERROR);
            return $this->redirectToPluginPage();
        }

        $this->checkEntityAccess($entities_id);

        $removed = (new HolidayImporter())->purge($source, $region_code, $entities_id, $year);

        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('%1$d close times of %2$s %3$d removed.', 'feriae'),
            $removed,
            $region_code,
            $year,
        )));

        return $this->redirectToPluginPage();
    }

    /**
     * Show, or hide, the closing periods of a previous import in the child
     * entities, for every year of the region and entity.
     */
    #[Route(path: 'import/recursive', name: 'import_recursive', methods: ['POST'])]
    public function setRecursive(Request $request): RedirectResponse
    {
        Session::checkRight(PluginConfig::$rightname, UPDATE);

        $source       = (string) $request->request->get('source', '');
        $region_code  = strtoupper((string) $request->request->get('region_code', ''));
        $entities_id  = $request->request->getInt('entities_id', -1);
        $is_recursive = $request->request->getBoolean('is_recursive', false);

        if ($source === '' || $region_code === '' || $entities_id < 0) {
            Session::addMessageAfterRedirect(htmlescape(__('Nothing is left of this import.', 'feriae')), false, ERROR);
            return $this->redirectToPluginPage();
        }

        $this->checkEntityAccess($entities_id);

        $result = (new HolidayImporter())->setRecursive($source, $region_code, $entities_id, $is_recursive);

        Session::addMessageAfterRedirect(htmlescape(sprintf(
            $is_recursive
                ? __('%1$d close times of %2$s now shown in the child entities.', 'feriae')
                : __('%1$d close times of %2$s now hidden from the child entities.', 'feriae'),
            $result['updated'],
            $region_code,
        )));
        if ($result['blocked'] > 0) {
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('%d close times still visible: a calendar of a child entity uses them.', 'feriae'),
                $result['blocked'],
            )), false, WARNING);
        }

        return $this->redirectToPluginPage();
    }

    #[Route(path: 'import/automatic/delete', name: 'automatic_import_delete', methods: ['POST'])]
    public function deleteAutomaticImport(Request $request): RedirectResponse
    {
        Session::checkRight(PluginConfig::$rightname, UPDATE);

        $import = new AutomaticImport();
        if (!$import->getFromDB($request->request->getInt('id'))) {
            Session::addMessageAfterRedirect(htmlescape(__('Nothing to remove.', 'feriae')), false, ERROR);
            return $this->redirectToPluginPage();
        }

        $this->checkEntityAccess(Row::int($import->fields, 'entities_id'));

        $import->delete(['id' => $import->getID()], true);

        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Automatic import of %s removed. The close times already imported are kept.', 'feriae'),
            $import->getLabel(),
        )));

        return $this->redirectToPluginPage();
    }

    /**
     * Register an automatic import from a previous import, with the
     * settings read back from what it created, without importing again.
     */
    #[Route(path: 'import/automatic/register', name: 'automatic_import_register', methods: ['POST'])]
    public function registerAutomaticImport(Request $request): RedirectResponse
    {
        Session::checkRight(PluginConfig::$rightname, UPDATE);

        $entities_id = $request->request->getInt('entities_id', -1);
        if ($entities_id >= 0) {
            $this->checkEntityAccess($entities_id);
        }

        $source      = (string) $request->request->get('source', '');
        $region_code = strtoupper((string) $request->request->get('region_code', ''));
        if ($this->isSourceGone($source)) {
            Session::addMessageAfterRedirect(htmlescape($this->getSourceGoneError($source)), false, ERROR);
            return $this->redirectToPluginPage();
        }

        if ($this->isRegionGone($source, $region_code)) {
            Session::addMessageAfterRedirect(htmlescape($this->getRegionGoneError($region_code)), false, ERROR);
            return $this->redirectToPluginPage();
        }

        $import = $this->registerFromGroup($source, $region_code, $entities_id, $request->request->getInt('year'));
        if (!$import instanceof AutomaticImport) {
            Session::addMessageAfterRedirect(htmlescape(__('Nothing is left of this import.', 'feriae')), false, ERROR);
            return $this->redirectToPluginPage();
        }

        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Automatic import of %1$s registered: %2$d years from the current one will be kept imported.', 'feriae'),
            $import->getLabel(),
            Row::int($import->fields, 'years_count'),
        )));

        return $this->redirectToPluginPage();
    }

    /**
     * Register an automatic import for every region and entity of the
     * previous imports the user has access to, from their most recent year.
     */
    #[Route(path: 'import/automatic/register/all', name: 'automatic_import_register_all', methods: ['POST'])]
    public function registerAllAutomaticImports(): RedirectResponse
    {
        Session::checkRight(PluginConfig::$rightname, UPDATE);

        $registered   = [];
        $gone         = [];
        $gone_sources = [];
        $seen         = [];
        // Groups come sorted by source, region, entity and year descending
        foreach ((new HolidayImporter())->getAccessibleImportGroups() as $group) {
            $key = $group['source'] . '|' . $group['region_code'] . '|' . $group['entities_id'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            // A source or a region gone since: skip it, the others still count
            if ($this->isSourceGone($group['source'])) {
                $gone_sources[$group['source']] = true;
                continue;
            }

            if ($this->isRegionGone($group['source'], $group['region_code'])) {
                $gone[$group['region_code']] = true;
                continue;
            }

            $import = $this->registerFromGroup($group['source'], $group['region_code'], $group['entities_id'], $group['year']);
            if ($import instanceof AutomaticImport) {
                $registered[] = '- ' . htmlescape(sprintf(
                    __('%1$s: %2$d years', 'feriae'),
                    $import->getLabel(),
                    Row::int($import->fields, 'years_count'),
                ));
            }
        }

        if ($gone_sources !== []) {
            Session::addMessageAfterRedirect(htmlescape($this->getSourceGoneError(implode(', ', array_keys($gone_sources)))), false, WARNING);
        }

        if ($gone !== []) {
            Session::addMessageAfterRedirect(htmlescape($this->getRegionGoneError(implode(', ', array_keys($gone)))), false, WARNING);
        }

        if ($registered === []) {
            if ($gone === [] && $gone_sources === []) {
                Session::addMessageAfterRedirect(htmlescape(__('Nothing is left of the previous imports.', 'feriae')), false, ERROR);
            }

            return $this->redirectToPluginPage();
        }

        Session::addMessageAfterRedirect(
            htmlescape(sprintf(__('%d automatic imports registered.', 'feriae'), count($registered))) . '<br/>' . implode('<br/>', $registered),
        );

        return $this->redirectToPluginPage();
    }

    /**
     * The automatic import registered from a previous import, null when
     * nothing is left of it.
     */
    private function registerFromGroup(string $source, string $region_code, int $entities_id, int $year): ?AutomaticImport
    {
        $locale   = PluginConfig::getSessionLocale();
        $settings = $source !== '' && SourceRegistry::has($source) && $region_code !== '' && $entities_id >= 0 && $year !== 0
            ? (new HolidayImporter())->getGroupSettings($source, $region_code, $entities_id, $year, $locale)
            : null;
        if ($settings === null) {
            return null;
        }

        // As many years ahead as this region has imported, at least the usual number
        $config      = PluginConfig::getConfig();
        $years_count = max(count($settings['years']), (int) $config['years_count'], 1);

        // The calendars read back from the links, under the rule of the
        // form: only the ones this user may attach close times to
        $calendars_id = array_values(array_intersect($settings['calendars_id'], array_keys(PluginConfig::getCalendarChoices())));

        return AutomaticImport::saveFromRequest(new ImportRequest(
            $source,
            $region_code,
            ImportRequest::yearsRange((int) date('Y'), min(self::MAX_YEARS, $years_count)),
            $settings['types'],
            $entities_id,
            $settings['is_recursive'],
            $calendars_id,
            false,
            $locale,
            $config['skip_existing'] === '1',
            $settings['fixed_as_recurrent'],
        ));
    }

    /**
     * Remove every import of the entities the user has access to. The
     * uninstall, which runs without a session, removes them all.
     */
    #[Route(path: 'import/purge/all', name: 'import_purge_all', methods: ['POST'])]
    public function purgeAll(): RedirectResponse
    {
        Session::checkRight(PluginConfig::$rightname, UPDATE);

        $importer = new HolidayImporter();
        $removed  = 0;
        foreach ($importer->getAccessibleImportGroups() as $group) {
            $removed += $importer->purge($group['source'], $group['region_code'], $group['entities_id'], $group['year']);
        }

        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('All imports removed: %d close times deleted. Close times entered by hand are untouched.', 'feriae'),
            $removed,
        )));

        return $this->redirectToPluginPage();
    }

    /**
     * The days of a previous import, as an HTML fragment for the modal of
     * the plugin page.
     */
    #[Route(path: 'import/days', name: 'import_days', methods: ['GET'])]
    public function days(Request $request): Response
    {
        Session::checkRight(PluginConfig::$rightname, UPDATE);

        $source      = (string) $request->query->get('source', '');
        $region_code = strtoupper((string) $request->query->get('region_code', ''));
        $entities_id = $request->query->getInt('entities_id', -1);
        $year        = $request->query->getInt('year');

        if ($source === '' || $region_code === '' || $entities_id < 0 || $year === 0) {
            return new Response(htmlescape(__('Nothing to show.', 'feriae')), 400);
        }

        $this->checkEntityAccess($entities_id);

        $days = (new HolidayImporter())->getImportedDays($source, $region_code, $entities_id, $year);

        return new Response(TemplateRenderer::getInstance()->render('@feriae/imported_days.html.twig', [
            'days'        => $days,
            'region_code' => $region_code,
            'year'        => $year,
        ]));
    }

    /**
     * The calendars of a previous import, as an HTML fragment for the modal
     * of the plugin page.
     */
    #[Route(path: 'import/calendars', name: 'import_calendars', methods: ['GET'])]
    public function calendars(Request $request): Response
    {
        Session::checkRight(PluginConfig::$rightname, UPDATE);

        $source      = (string) $request->query->get('source', '');
        $region_code = strtoupper((string) $request->query->get('region_code', ''));
        $entities_id = $request->query->getInt('entities_id', -1);
        $year        = $request->query->getInt('year');

        if ($source === '' || $region_code === '' || $entities_id < 0 || $year === 0) {
            return new Response(htmlescape(__('Nothing to show.', 'feriae')), 400);
        }

        $this->checkEntityAccess($entities_id);

        return new Response(TemplateRenderer::getInstance()->render('@feriae/import_calendars.html.twig', [
            'calendars' => (new HolidayImporter())->getImportCalendars($source, $region_code, $entities_id, $year),
        ]));
    }

    /**
     * Run the automatic imports now. When every active import is within
     * reach of the user, the automatic action itself is launched, the way
     * the "Execute" button of its form does (forced launch: the task mode
     * does not matter), so that the run is logged and dated like any
     * other. Otherwise only the imports of the entities the user has
     * access to are run, one by one, as the action would run them all.
     */
    #[Route(path: 'import/automatic/run', name: 'automatic_import_run', methods: ['POST'])]
    public function runAutomaticImports(Request $request): RedirectResponse
    {
        Session::checkRight(PluginConfig::$rightname, UPDATE);

        // A single import: run it directly, outside the automatic action
        $id = $request->request->getInt('id');
        if ($id > 0) {
            $import = new AutomaticImport();
            if (!$import->getFromDB($id)) {
                Session::addMessageAfterRedirect(htmlescape(__('No automatic import to run.', 'feriae')), false, ERROR);
                return $this->redirectToPluginPage();
            }

            $this->checkEntityAccess(Row::int($import->fields, 'entities_id'));
            try {
                $report = $import->run((int) date('Y'));
            } catch (Throwable $e) {
                Session::addMessageAfterRedirect(htmlescape(sprintf(
                    __('The automatic import of %1$s could not be run: %2$s', 'feriae'),
                    $import->getLabel(),
                    $e->getMessage(),
                )), false, ERROR);
                return $this->redirectToPluginPage();
            }

            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('Automatic import of %1$s run: %2$s.', 'feriae'),
                $import->getLabel(),
                $report->getSummary(),
            )));
            return $this->redirectToPluginPage();
        }

        $is_active  = static fn(AutomaticImport $import): bool => Row::int($import->fields, 'is_active') === 1;
        $all        = array_filter(AutomaticImport::getAll(), $is_active);
        $accessible = array_filter(AutomaticImport::getAccessible(), $is_active);
        if ($accessible === [] || !AutomaticImport::getCronTask() instanceof CronTask) {
            Session::addMessageAfterRedirect(htmlescape(__('No automatic import to run.', 'feriae')), false, ERROR);
            return $this->redirectToPluginPage();
        }

        if (count($accessible) === count($all)) {
            if (!$this->launchAutomaticAction()) {
                Session::addMessageAfterRedirect(htmlescape(
                    __('The automatic action could not be run: it is already running, or GLPI is in maintenance mode.', 'feriae'),
                ), false, ERROR);
                return $this->redirectToPluginPage();
            }

            $accessible = array_filter(AutomaticImport::getAccessible(), $is_active);
        } else {
            foreach ($accessible as $import) {
                try {
                    $import->run((int) date('Y'));
                } catch (Throwable) {
                    // Recorded on the import, listed below like the others
                }
            }
        }

        $lines = [];
        foreach ($accessible as $import) {
            $lines[] = '- ' . htmlescape(sprintf('%s: %s', $import->getLabel(), Row::string($import->fields, 'last_summary')));
        }

        Session::addMessageAfterRedirect(
            htmlescape(__('Automatic imports run.', 'feriae')) . '<br/>' . implode('<br/>', $lines),
        );

        return $this->redirectToPluginPage();
    }

    /**
     * Launch the automatic action of the plugin the way the "Execute"
     * button of its form does, and give the session back to the user.
     *
     * CronTask::launch() prepares a cron context inside the current
     * session: active entity set to the root with every child, groups
     * emptied, user name "cron", a "cron running" marker; and nothing puts
     * them back. Left as is, a user confined to a sub-entity would see the
     * whole instance until the next entity change or logout. The entity is
     * restored through changeActiveEntities(), which rebuilds the lists
     * from the profile rather than trusting saved copies.
     *
     * @return bool whether the task ran
     */
    private function launchAutomaticAction(): bool
    {
        $entity    = $_SESSION['glpiactive_entity'] ?? null;
        $recursive = (bool) ($_SESSION['glpiactive_entity_recursive'] ?? false);
        $whole     = (bool) ($_SESSION['glpientity_fullstructure'] ?? false);
        $name      = $_SESSION['glpiname'] ?? null;
        $groups    = $_SESSION['glpigroups'] ?? null;

        try {
            return CronTask::launch(-CronTask::MODE_INTERNAL, 1, AutomaticImport::CRON_NAME) === AutomaticImport::CRON_NAME;
        } finally {
            unset($_SESSION['glpicronuserrunning']);
            if (is_numeric($entity)) {
                Session::changeActiveEntities($whole ? 'all' : (int) $entity, $recursive);
            }

            $_SESSION['glpiname']   = $name;
            $_SESSION['glpigroups'] = $groups;
        }
    }

    private function buildRequest(Request $request): ?ImportRequest
    {
        $source_key = (string) $request->request->get('source', '');
        if (!SourceRegistry::has($source_key)) {
            $source_key = SourceRegistry::getDefault()->getKey();
        }

        $source = SourceRegistry::get($source_key);

        $region_code = strtoupper(trim((string) $request->request->get('region_code', '')));
        if ($region_code === '' || !$source->supportsRegion($region_code)) {
            Session::addMessageAfterRedirect(htmlescape(__('Please select a country or a region.', 'feriae')), false, ERROR);
            return null;
        }

        $types = [];
        foreach ($request->request->all('types') as $value) {
            $type = is_string($value) ? HolidayType::tryFrom($value) : null;
            if ($type !== null) {
                $types[] = $type;
            }
        }

        if ($types === []) {
            $types = [HolidayType::OFFICIAL];
        }

        $year_from = $request->request->getInt('year_from', (int) date('Y'));
        if (!ImportRequest::isValidYear($year_from)) {
            Session::addMessageAfterRedirect(htmlescape($this->getYearError()), false, ERROR);
            return null;
        }

        $years_count = min(self::MAX_YEARS, max(1, $request->request->getInt('years_count', 1)));

        $entities_id = $request->request->getInt('entities_id', (int) Session::getActiveEntity());
        $this->checkEntityAccess($entities_id);

        $calendars_id = [];
        $allowed      = PluginConfig::getCalendarChoices();
        foreach ($request->request->all('calendars_id') as $value) {
            if (is_numeric($value) && isset($allowed[(int) $value])) {
                $calendars_id[] = (int) $value;
            }
        }

        return new ImportRequest(
            $source_key,
            $region_code,
            ImportRequest::yearsRange($year_from, $years_count),
            array_values(array_unique($types, SORT_REGULAR)),
            $entities_id,
            $request->request->getBoolean('is_recursive', true),
            array_values(array_unique($calendars_id)),
            $request->request->getBoolean('replace', false),
            PluginConfig::getSessionLocale(),
            $request->request->getBoolean('skip_existing', true),
            $request->request->getBoolean('fixed_as_recurrent', true),
        );
    }

    /**
     * Whether a tracked region is no longer offered by its source, which
     * happens when the source drops or renames a region between two
     * versions. Asking it for the holidays would throw.
     */
    private function isRegionGone(string $source, string $region_code): bool
    {
        return $source !== '' && SourceRegistry::has($source) && $region_code !== ''
            && !SourceRegistry::get($source)->supportsRegion($region_code);
    }

    private function getRegionGoneError(string $region_codes): string
    {
        return sprintf(__('%s is no longer offered by the source: the import cannot be renewed. Its close times are kept.', 'feriae'), $region_codes);
    }

    /**
     * Whether a tracked source is no longer registered. Only one source
     * exists today; the day another is added and later removed, its
     * imports must say so rather than "nothing is left".
     */
    private function isSourceGone(string $source): bool
    {
        return $source !== '' && !SourceRegistry::has($source);
    }

    private function getSourceGoneError(string $sources): string
    {
        return sprintf(__('The source %s is no longer available: the import cannot be renewed. Its close times are kept.', 'feriae'), $sources);
    }

    private function getYearError(): string
    {
        return sprintf(__('The year must be between %1$d and %2$d.', 'feriae'), ImportRequest::MIN_YEAR, ImportRequest::MAX_YEAR);
    }

    /**
     * The plugin page acts on the entities of the session only: an import
     * belongs to its entity, and the right to configure GLPI does not
     * reach the entities the user may not see.
     */
    private function checkEntityAccess(int $entities_id): void
    {
        if (!Session::haveAccessToEntity($entities_id)) {
            throw new AccessDeniedHttpException(sprintf('No access to the entity %d', $entities_id));
        }
    }

    private function redirectToPluginPage(): RedirectResponse
    {
        return new RedirectResponse(PluginConfig::getRootDoc() . '/plugins/feriae/front/config.form.php');
    }
}
