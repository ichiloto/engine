<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Tests\Support;

use PHPUnit\Framework\TestCase;

/** Pest invokes its parent teardown after every local afterEach handler. */
abstract class TemporaryFixtureTestCase extends TestCase
{
    protected function setUp(): void
    {
        TemporaryFixtures::beginTest($this::class . '::' . $this->name());
        parent::setUp();
    }

    protected function tearDown(): void
    {
        try { TemporaryFixtures::endTest(); } finally { parent::tearDown(); }
    }
}
