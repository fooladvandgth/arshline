<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

/**
 * Base test case that ensures Brain Monkey environment is set up & torn down properly.
 * Extend this instead of bare TestCase for tests that rely on WP function stubbing.
 */
abstract class BaseMonkeyTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (class_exists(Monkey::class)) {
            Monkey\setUp();
        }
    }

    protected function tearDown(): void
    {
        if (class_exists(Monkey::class)) {
            Monkey\tearDown();
        }
        parent::tearDown();
    }
}
