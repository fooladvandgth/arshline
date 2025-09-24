<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;
use Arshline\Modules\Forms\Form;
use Arshline\Modules\Forms\FormRepository;
use Arshline\Modules\Forms\FormValidator;

class FormRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();
    }

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function testSaveInsertsNewForm()
    {
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public $inserted = false;
            public $insert_args = [];
            public $insert_id = 12;
            public function insert($table, $data) {
                $this->inserted = true;
                $this->insert_args = [$table, $data];
                return true;
            }
        };
        $form = new Form(['schema_version' => '1.0.0', 'owner_id' => 3, 'status' => 'draft', 'meta' => ['title' => 'تست']]);
        $id = FormRepository::save($form);
        $this->assertTrue($wpdb->inserted);
        $this->assertEquals(12, $id);
        $this->assertSame('wp_x_forms', $wpdb->insert_args[0]);
    }

    public function testSaveUpdatesExistingForm()
    {
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public $updated = false;
            public $update_args = [];
            public function update($table, $data, $where) {
                $this->updated = true;
                $this->update_args = [$table, $data, $where];
                return true;
            }
        };
        $form = new Form(['id' => 5, 'schema_version' => '1.0.0', 'owner_id' => 3, 'status' => 'draft', 'meta' => ['title' => 'تست']]);
        $id = FormRepository::save($form);
        $this->assertTrue($wpdb->updated);
        $this->assertEquals(5, $id);
        $this->assertEquals(['id' => 5], $wpdb->update_args[2]);
    }

    public function testDeleteCascadesRelatedRecords()
    {
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public $queries = [];
            public $deleted = false;
            public function prepare($query, ...$args) { return vsprintf($query, $args); }
            public function query($sql) { $this->queries[] = $sql; return true; }
            public function delete($table, $where) { $this->deleted = [$table, $where]; return 1; }
        };
        $this->assertTrue(FormRepository::delete(9));
        $this->assertSame(['wp_x_forms', ['id' => 9]], $wpdb->deleted);
        $this->assertNotEmpty($wpdb->queries);
    }

    public function testValidatorRequiresFields()
    {
        $form = new Form(['fields' => []]);
        $errors = FormValidator::validate($form);
        $this->assertNotEmpty($errors);
    }
}
