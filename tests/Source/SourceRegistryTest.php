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

namespace GlpiPlugin\Feriae\Tests\Source;

use InvalidArgumentException;
use LogicException;
use GlpiPlugin\Feriae\Source\HolidaySource;
use GlpiPlugin\Feriae\Source\SourceRegistry;
use GlpiPlugin\Feriae\Source\Yasumi\YasumiSource;
use PHPUnit\Framework\TestCase;

final class SourceRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        // Restore what plugin init registered for the other tests
        SourceRegistry::reset();
        SourceRegistry::register(new YasumiSource());
    }

    public function testPluginInitRegistersYasumi(): void
    {
        $this->assertTrue(SourceRegistry::has('yasumi'));
        $this->assertInstanceOf(YasumiSource::class, SourceRegistry::get('yasumi'));
        $this->assertInstanceOf(YasumiSource::class, SourceRegistry::getDefault());
    }

    public function testAnotherSourceCanBeRegisteredAndResolved(): void
    {
        $fake = $this->createStub(HolidaySource::class);
        $fake->method('getKey')->willReturn('fake');

        SourceRegistry::register($fake);

        $this->assertSame($fake, SourceRegistry::get('fake'));
        $this->assertSame(['yasumi', 'fake'], array_keys(SourceRegistry::all()));
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SourceRegistry::get('nope');
    }

    public function testDefaultRequiresAtLeastOneSource(): void
    {
        SourceRegistry::reset();
        $this->expectException(LogicException::class);
        SourceRegistry::getDefault();
    }
}
