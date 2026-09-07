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

use Entity;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Feriae\ClosingDay;
use GlpiPlugin\Feriae\ClosingPeriods;
use Holiday;

final class ClosingPeriodsTest extends DbTestCase
{
    private function addPeriod(string $name, int $entities_id, bool $recursive, string $begin, string $end, bool $perpetual): int
    {
        return (new Holiday())->add([
            'name'         => $name,
            'entities_id'  => $entities_id,
            'is_recursive' => $recursive ? 1 : 0,
            'begin_date'   => $begin,
            'end_date'     => $end,
            'is_perpetual' => $perpetual ? 1 : 0,
        ]);
    }

    /** @return list<array{string, string, string, bool}> name, begin, end, perpetual */
    private function summarize(array $days): array
    {
        return array_map(static fn(ClosingDay $d): array => [$d->name, $d->begin, $d->end, $d->is_perpetual], $days);
    }

    public function testDatedPeriodsOverlappingTheWindow(): void
    {
        $this->login('glpi');
        $root = getItemByTypeName(Entity::class, '_test_root_entity', true);
        $this->addPeriod('Before', $root, true, '2026-05-30', '2026-05-31', false);
        $this->addPeriod('Bridge', $root, true, '2026-05-31', '2026-06-02', false);
        $this->addPeriod('Inside', $root, true, '2026-06-10', '2026-06-10', false);
        $this->addPeriod('Overlaps end', $root, true, '2026-06-30', '2026-07-03', false);
        $this->addPeriod('After', $root, true, '2026-07-01', '2026-07-01', false);

        $days = (new ClosingPeriods())->getBetween('2026-06-01', '2026-06-30', $root);

        $this->assertSame([
            ['Bridge', '2026-05-31', '2026-06-02', false],
            ['Inside', '2026-06-10', '2026-06-10', false],
            ['Overlaps end', '2026-06-30', '2026-07-03', false],
        ], $this->summarize($days));
    }

    public function testRecurrentPeriodsAreReplayedEveryYear(): void
    {
        $this->login('glpi');
        $root = getItemByTypeName(Entity::class, '_test_root_entity', true);
        $this->addPeriod('Noël', $root, true, '2019-12-25', '2019-12-25', true);
        // Range crossing the new year (GLPI requires end >= begin, so it is
        // entered with two consecutive years)
        $this->addPeriod('Fermeture annuelle', $root, true, '2019-12-24', '2020-01-02', true);

        $days = (new ClosingPeriods())->getBetween('2026-01-01', '2027-12-31', $root);

        // Chronological order
        $this->assertSame([
            ['Fermeture annuelle', '2025-12-24', '2026-01-02', true],
            ['Fermeture annuelle', '2026-12-24', '2027-01-02', true],
            ['Noël', '2026-12-25', '2026-12-25', true],
            ['Fermeture annuelle', '2027-12-24', '2028-01-02', true],
            ['Noël', '2027-12-25', '2027-12-25', true],
        ], $this->summarize($days));
    }

    public function testEntityVisibility(): void
    {
        $this->login('glpi');
        $root    = getItemByTypeName(Entity::class, '_test_root_entity', true);
        $child_1 = getItemByTypeName(Entity::class, '_test_child_1', true);
        $child_2 = getItemByTypeName(Entity::class, '_test_child_2', true);
        $this->addPeriod('Parent recursive', $root, true, '2026-07-14', '2026-07-14', false);
        $this->addPeriod('Parent only', $root, false, '2026-07-14', '2026-07-14', false);
        $this->addPeriod('Child 1', $child_1, false, '2026-07-14', '2026-07-14', false);
        $this->addPeriod('Child 2', $child_2, false, '2026-07-14', '2026-07-14', false);

        $names = static fn(array $days): array => array_map(static fn(ClosingDay $d): string => $d->name, $days);
        $periods = new ClosingPeriods();

        $this->assertSame(['Parent recursive', 'Child 1'], $names($periods->getBetween('2026-07-14', '2026-07-14', $child_1)));
        $this->assertSame(['Parent recursive', 'Parent only'], $names($periods->getBetween('2026-07-14', '2026-07-14', $root)));
        $this->assertSame(['Parent recursive', 'Child 2'], $names($periods->getBetween('2026-07-14', '2026-07-14', $child_2)));
    }

    public function testFindCovering(): void
    {
        $this->login('glpi');
        $root = getItemByTypeName(Entity::class, '_test_root_entity', true);
        $id   = $this->addPeriod('Noël', $root, true, '2019-12-25', '2019-12-25', true);
        $periods = new ClosingPeriods();

        $this->assertNull($periods->findCovering('2026-12-24', $root));
        $day = $periods->findCovering('2026-12-25', $root);
        $this->assertNotNull($day);
        $this->assertSame($id, $day->id);
        $this->assertTrue($day->covers('2026-12-25'));
        $this->assertTrue($day->isSingleDay());
        $this->assertSame([], $periods->getBetween('2026-12-26', '2026-12-25', $root), 'Inverted window');
    }
}
