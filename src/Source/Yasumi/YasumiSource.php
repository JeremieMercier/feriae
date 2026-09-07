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

namespace GlpiPlugin\Feriae\Source\Yasumi;

use GlpiPlugin\Feriae\Source\Yasumi\Provider\Guadeloupe;
use GlpiPlugin\Feriae\Source\Yasumi\Provider\Martinique;
use GlpiPlugin\Feriae\Source\Yasumi\Provider\FrenchGuiana;
use GlpiPlugin\Feriae\Source\Yasumi\Provider\Reunion;
use GlpiPlugin\Feriae\Source\Yasumi\Provider\Mayotte;
use GlpiPlugin\Feriae\Source\Yasumi\Provider\SaintBarthelemy;
use GlpiPlugin\Feriae\Source\Yasumi\Provider\SaintMartin;
use GlpiPlugin\Feriae\Source\Yasumi\Provider\SaintPierreAndMiquelon;
use GlpiPlugin\Feriae\Source\Yasumi\Provider\FrenchPolynesia;
use GlpiPlugin\Feriae\Source\Yasumi\Provider\NewCaledonia;
use GlpiPlugin\Feriae\Source\Yasumi\Provider\WallisAndFutuna;
use DateTimeImmutable;
use Locale;
use Yasumi\ProviderInterface;
use GlpiPlugin\Feriae\Source\HolidayEntry;
use GlpiPlugin\Feriae\Source\HolidaySource;
use GlpiPlugin\Feriae\Source\HolidayType;
use GlpiPlugin\Feriae\Source\Region;
use GlpiPlugin\Feriae\Source\UnknownRegionException;
use Yasumi\Holiday;
use Yasumi\Yasumi;

use function Safe\preg_replace;

/**
 * Holiday source backed by the Yasumi library (azuyalabs/yasumi, MIT).
 *
 * Yasumi computes holidays from rules, without network access. Its own
 * providers are completed by the plugin providers (French overseas
 * territories) declared in {@see self::EXTRA_PROVIDERS}.
 */
final class YasumiSource implements HolidaySource
{
    public const KEY = 'yasumi';

    /**
     * Plugin providers, indexed by region code. Yasumi only scans its own
     * directory, so these have to be listed explicitly.
     *
     * @var array<string, class-string<ProviderInterface>>
     */
    private const EXTRA_PROVIDERS = [
        Guadeloupe::ID             => Guadeloupe::class,
        Martinique::ID             => Martinique::class,
        FrenchGuiana::ID           => FrenchGuiana::class,
        Reunion::ID                => Reunion::class,
        Mayotte::ID                => Mayotte::class,
        SaintBarthelemy::ID        => SaintBarthelemy::class,
        SaintMartin::ID            => SaintMartin::class,
        SaintPierreAndMiquelon::ID => SaintPierreAndMiquelon::class,
        FrenchPolynesia::ID        => FrenchPolynesia::class,
        NewCaledonia::ID           => NewCaledonia::class,
        WallisAndFutuna::ID        => WallisAndFutuna::class,
    ];

    /** Yasumi entries that are not geographic regions. */
    private const IGNORED_PROVIDERS = ['US-NYSE'];

    /**
     * Subdivision labels that the humanized class name does not render
     * properly (English, translated through the plugin locales). Everything
     * else is derived from the provider class name.
     */
    private const SUBDIVISION_LABELS = [
        'CA-NL'  => 'Newfoundland and Labrador',
        'CH-BL'  => 'Basel-Landschaft',
        'CH-BS'  => 'Basel-Stadt',
        'CH-NE'  => 'Neuchâtel',
        'CH-SG'  => 'St. Gallen',
        'DE-BW'  => 'Baden-Württemberg',
        'DE-MV'  => 'Mecklenburg-Western Pomerania',
        'DE-NW'  => 'North Rhine-Westphalia',
        'DE-RP'  => 'Rhineland-Palatinate',
        'DE-SH'  => 'Schleswig-Holstein',
        'DE-ST'  => 'Saxony-Anhalt',
        'ES-CL'  => 'Castile and León',
        'ES-CM'  => 'Castilla-La Mancha',
        'ES-MC'  => 'Region of Murcia',
        'ES-MD'  => 'Community of Madrid',
        'FR-57'  => 'Moselle',
        'FR-67'  => 'Bas-Rhin',
        'FR-68'  => 'Haut-Rhin',
        'FR-971' => 'Guadeloupe',
        'FR-972' => 'Martinique',
        'FR-973' => 'Guyane',
        'FR-974' => 'La Réunion',
        'FR-976' => 'Mayotte',
        'FR-BL'  => 'Saint-Barthélemy',
        'FR-MF'  => 'Saint-Martin',
        'FR-PM'  => 'Saint-Pierre-et-Miquelon',
        'FR-PF'  => 'Polynésie française',
        'FR-NC'  => 'Nouvelle-Calédonie',
        'FR-WF'  => 'Wallis-et-Futuna',
        'GB-ENG' => 'England',
        'GB-SCT' => 'Scotland',
        'GB-WLS' => 'Wales',
        'GB-NIR' => 'Northern Ireland',
    ];

    /** @var array<string, string>|null region code => provider class or Yasumi provider path */
    private ?array $providers = null;

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getName(): string
    {
        return 'Yasumi';
    }

    public function getRegions(string $locale): array
    {
        $regions = [];
        foreach (array_keys($this->getProviders()) as $code) {
            $country_code = substr($code, 0, 2);
            $parent_code  = null;
            if (str_contains($code, '-')) {
                $parent_code = substr($code, 0, (int) strrpos($code, '-'));
            }

            $regions[$code] = new Region(
                $code,
                $this->getRegionLabel($code, $country_code, $locale),
                $country_code,
                $parent_code,
            );
        }

        uasort($regions, static function (Region $a, Region $b): int {
            if ($a->countryCode !== $b->countryCode) {
                return strcoll($a->name, $b->name) ?: strcmp($a->code, $b->code);
            }

            if ($a->isCountry() !== $b->isCountry()) {
                return $a->isCountry() ? -1 : 1;
            }

            return strcoll($a->name, $b->name) ?: strcmp($a->code, $b->code);
        });

        return $regions;
    }

    public function supportsRegion(string $regionCode): bool
    {
        return isset($this->getProviders()[strtoupper($regionCode)]);
    }

    public function getHolidays(string $regionCode, int $year, string $locale): array
    {
        $regionCode = strtoupper($regionCode);
        $provider   = $this->getProviders()[$regionCode] ?? null;
        if ($provider === null) {
            throw UnknownRegionException::forCode($regionCode, self::KEY);
        }

        $yasumi_locale = $this->resolveLocale($locale);
        $entries       = [];
        foreach (Yasumi::create($provider, $year, $yasumi_locale)->getHolidays() as $holiday) {
            $entries[] = new HolidayEntry(
                $holiday->getKey(),
                $this->getHolidayName($holiday, $yasumi_locale),
                DateTimeImmutable::createFromInterface($holiday)->setTime(0, 0),
                $this->mapType($holiday->getType()),
                $regionCode,
            );
        }

        usort($entries, static fn(HolidayEntry $a, HolidayEntry $b): int => $a->date <=> $b->date ?: strcmp($a->key, $b->key));

        return $entries;
    }

    /** @return array<string, string> */
    private function getProviders(): array
    {
        if ($this->providers === null) {
            $providers = Yasumi::getProviders();
            foreach (self::IGNORED_PROVIDERS as $ignored) {
                unset($providers[$ignored]);
            }

            $this->providers = $providers + self::EXTRA_PROVIDERS;
        }

        return $this->providers;
    }

    private function getRegionLabel(string $code, string $country_code, string $locale): string
    {
        $country = Locale::getDisplayRegion('-' . $country_code, $locale);
        if ($country === false || $country === '') {
            $country = $country_code;
        }

        if ($code === $country_code) {
            return $country;
        }

        // Subdivision names are not available from intl: the English label
        // goes through the plugin translations (session language)
        if (isset(self::SUBDIVISION_LABELS[$code])) {
            return _x('region', self::SUBDIVISION_LABELS[$code], 'feriae');
        }

        // "Australia/Tasmania/NorthEast" or a FQCN => "North East"
        $class    = $this->getProviders()[$code];
        $basename = preg_replace('#^.*[/\\\\]#', '', $class);
        return _x('region', trim(preg_replace('/(?<!^)(?=[A-Z])/', ' ', $basename)), 'feriae');
    }

    /**
     * Yasumi refuses locales it does not know: fall back from "fr_FR" to
     * "fr", then to English.
     */
    private function resolveLocale(string $locale): string
    {
        $available = Yasumi::getAvailableLocales();
        foreach ([$locale, substr($locale, 0, 2)] as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return 'en_US';
    }

    /**
     * Yasumi only knows the exact locales listed in a translation file, and
     * some holidays are only translated in their own language (e.g. the Day
     * of German Unity). Walk down from the requested locale to English, then
     * to whatever translation exists; the raw key is the last resort.
     */
    private function getHolidayName(Holiday $holiday, string $locale): string
    {
        $candidates = [$locale, substr($locale, 0, 2), 'en_US', 'en'];
        foreach ($candidates as $candidate) {
            if (isset($holiday->translations[$candidate])) {
                return $holiday->translations[$candidate];
            }
        }

        $any = reset($holiday->translations);
        return is_string($any) && $any !== '' ? $any : $holiday->getKey();
    }

    private function mapType(string $type): HolidayType
    {
        return HolidayType::tryFrom($type) ?? HolidayType::OTHER;
    }
}
