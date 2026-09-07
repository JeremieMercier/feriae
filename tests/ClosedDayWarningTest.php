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
use Holiday;
use PlanningExternalEvent;
use Ticket;
use TicketTask;
use User;

final class ClosedDayWarningTest extends DbTestCase
{
    private function createTicket(int $entities_id): int
    {
        $id = (new Ticket())->add([
            'name'        => 'Closed day warning',
            'content'     => 'Closed day warning',
            'entities_id' => $entities_id,
        ]);
        $this->assertGreaterThan(0, $id);
        return $id;
    }

    private function planTask(int $tickets_id, string $begin, string $end): TicketTask
    {
        $task = new TicketTask();
        $id   = $task->add([
            'tickets_id' => $tickets_id,
            'content'    => 'Intervention',
            'plan'       => [
                'begin'         => $begin,
                'end'           => $end,
                'users_id_tech' => getItemByTypeName(User::class, 'tech', true),
            ],
        ]);
        $this->assertGreaterThan(0, $id);
        return $task;
    }

    public function testWarnsWhenATaskIsPlannedOnAClosingPeriodOfTheTicketEntity(): void
    {
        $this->login('glpi');
        $root = getItemByTypeName(Entity::class, '_test_root_entity', true);
        (new Holiday())->add(['name' => 'Noël', 'entities_id' => $root, 'is_recursive' => 1, 'begin_date' => '2026-12-25', 'end_date' => '2026-12-25', 'is_perpetual' => 0]);

        $this->planTask($this->createTicket($root), '2026-12-25 09:00:00', '2026-12-25 11:00:00');

        $this->hasSessionMessageThatContains('Warning: this task is planned on a close time of the entity', WARNING);
    }

    public function testNoWarningOnAWorkingDay(): void
    {
        $this->login('glpi');
        $root = getItemByTypeName(Entity::class, '_test_root_entity', true);
        (new Holiday())->add(['name' => 'Noël', 'entities_id' => $root, 'is_recursive' => 1, 'begin_date' => '2026-12-25', 'end_date' => '2026-12-25', 'is_perpetual' => 0]);

        $this->planTask($this->createTicket($root), '2026-12-24 09:00:00', '2026-12-24 11:00:00');

        $this->assertArrayNotHasKey(WARNING, $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? []);
    }

    public function testOnlyThePeriodsOfTheTicketEntityCount(): void
    {
        $this->login('glpi');
        $child_1 = getItemByTypeName(Entity::class, '_test_child_1', true);
        $child_2 = getItemByTypeName(Entity::class, '_test_child_2', true);
        (new Holiday())->add(['name' => 'Fermeture agence 2', 'entities_id' => $child_2, 'is_recursive' => 0, 'begin_date' => '2026-12-25', 'end_date' => '2026-12-25', 'is_perpetual' => 0]);

        $this->planTask($this->createTicket($child_1), '2026-12-25 09:00:00', '2026-12-25 11:00:00');
        $this->assertArrayNotHasKey(WARNING, $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? []);

        $this->planTask($this->createTicket($child_2), '2026-12-25 09:00:00', '2026-12-25 11:00:00');
        $this->hasSessionMessageThatContains('Fermeture agence 2', WARNING);
    }

    public function testWarnsAgainWhenTheTaskIsMovedOntoAClosedDay(): void
    {
        $this->login('glpi');
        $root = getItemByTypeName(Entity::class, '_test_root_entity', true);
        (new Holiday())->add(['name' => 'Noël', 'entities_id' => $root, 'is_recursive' => 1, 'begin_date' => '2026-12-25', 'end_date' => '2026-12-25', 'is_perpetual' => 0]);
        $task = $this->planTask($this->createTicket($root), '2026-12-24 09:00:00', '2026-12-24 11:00:00');
        $this->assertArrayNotHasKey(WARNING, $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? []);

        // Content change only: nothing to say
        $task->update(['id' => $task->getID(), 'content' => 'Updated']);
        $this->assertArrayNotHasKey(WARNING, $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? []);

        // Same input as the task form: the parent ticket travels as _job
        $ticket = new Ticket();
        $ticket->getFromDB((int) $task->fields['tickets_id']);

        $tech = getItemByTypeName(User::class, 'tech', true);
        $task->update([
            'id'            => $task->getID(),
            'tickets_id'    => $ticket->getID(),
            '_job'          => $ticket,
            'users_id_tech' => $tech,
            'plan'          => ['begin' => '2026-12-25 09:00:00', 'end' => '2026-12-25 11:00:00', 'users_id_tech' => $tech],
        ]);
        $this->hasSessionMessageThatContains('Noël', WARNING);
    }

    public function testWarnsForAnExternalPlanningEventOfTheEntity(): void
    {
        $this->login('glpi');
        $root    = getItemByTypeName(Entity::class, '_test_root_entity', true);
        $child_2 = getItemByTypeName(Entity::class, '_test_child_2', true);
        (new Holiday())->add(['name' => 'Fermeture agence 2', 'entities_id' => $child_2, 'is_recursive' => 0, 'begin_date' => '2026-12-25', 'end_date' => '2026-12-25', 'is_perpetual' => 0]);

        $add = fn(int $entities_id): int => (new PlanningExternalEvent())->add([
            'name'        => 'Réunion',
            'entities_id' => $entities_id,
            'users_id'    => getItemByTypeName(User::class, 'tech', true),
            'plan'        => ['begin' => '2026-12-25 09:00:00', 'end' => '2026-12-25 11:00:00'],
        ]);

        // The event entity has no close time that day
        $this->assertGreaterThan(0, $add($root));
        $this->assertArrayNotHasKey(WARNING, $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? []);

        $this->assertGreaterThan(0, $add($child_2));
        $this->hasSessionMessageThatContains('Warning: this event is planned on a close time of the entity', WARNING);
    }

    public function testNoWarningForAnExternalEventMovedFromThePlanning(): void
    {
        $this->login('glpi');
        $root = getItemByTypeName(Entity::class, '_test_root_entity', true);
        (new Holiday())->add(['name' => 'Noël', 'entities_id' => $root, 'is_recursive' => 1, 'begin_date' => '2026-12-25', 'end_date' => '2026-12-25', 'is_perpetual' => 0]);

        (new PlanningExternalEvent())->add([
            'name'           => 'Réunion',
            'entities_id'    => $root,
            'users_id'       => getItemByTypeName(User::class, 'tech', true),
            'plan'           => ['begin' => '2026-12-25 09:00:00', 'end' => '2026-12-25 11:00:00'],
            '_no_check_plan' => true,
        ]);

        $this->assertArrayNotHasKey(WARNING, $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? []);
    }

    public function testMultiDayTaskListsEveryClosedDay(): void
    {
        $this->login('glpi');
        $root = getItemByTypeName(Entity::class, '_test_root_entity', true);
        (new Holiday())->add(['name' => 'Noël', 'entities_id' => $root, 'is_recursive' => 1, 'begin_date' => '2019-12-25', 'end_date' => '2019-12-25', 'is_perpetual' => 1]);
        (new Holiday())->add(['name' => 'Saint-Étienne', 'entities_id' => $root, 'is_recursive' => 1, 'begin_date' => '2026-12-26', 'end_date' => '2026-12-26', 'is_perpetual' => 0]);

        $this->planTask($this->createTicket($root), '2026-12-24 09:00:00', '2026-12-27 11:00:00');

        $messages = $_SESSION['MESSAGE_AFTER_REDIRECT'][WARNING] ?? [];
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Noël', $messages[0]);
        $this->assertStringContainsString('Saint-Étienne', $messages[0]);
        unset($_SESSION['MESSAGE_AFTER_REDIRECT'][WARNING]);
    }
}
