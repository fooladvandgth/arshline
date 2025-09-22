<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Arshline\Support\Helpers;

class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
        };
    }

    public function testTableNameBuildsWithPrefix()
    {
        $this->assertSame('wp_x_forms', Helpers::tableName('forms'));
        $this->assertSame('wp_x_submission_values', Helpers::tableName('submission_values'));
    }
}
