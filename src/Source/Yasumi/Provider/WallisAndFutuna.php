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

namespace GlpiPlugin\Feriae\Source\Yasumi\Provider;

final class WallisAndFutuna extends AbstractFrenchOverseas
{
    public const ID = 'FR-WF';

    protected function getLocalTimezone(): string
    {
        return 'Pacific/Wallis';
    }

    protected function addLocalHolidays(): void
    {
        // Saint Peter Chanel, patron saint of Oceania, martyred on Futuna in 1841.
        $this->addFixedHoliday('saintPeterChanel', [
            'en' => 'Saint Peter Chanel',
            'fr' => 'Saint-Pierre-Chanel',
        ], 4, 28, 1962);
        // Territory day, anniversary of the 1961 statute.
        $this->addFixedHoliday('territoryDay', [
            'en' => 'Territory Day',
            'fr' => 'Fête du territoire',
        ], 7, 29, 1962);
    }
}
