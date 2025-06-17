<?php

namespace Blax\Roles\Tests\Unit;

use Orchestra\Testbench\PHPUnit\TestCase;

class PermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadLaravelMigrations(['--database' => 'testbench']);
        $this->artisan('migrate', ['--database' => 'testbench']);
    }

    public function testHasPermission()
    {
        // Assuming you have a User model with the HasPermissions trait
        $user = \App\Models\User::factory()->create();

        // Add a permission to the user
        $user->permissions()->attach(1, ['context' => 'test']);

        // Check if the user has the permission
        $this->assertTrue($user->hasPermission('view_posts', ['context' => 'test']));
        $this->assertFalse($user->hasPermission('edit_posts', ['context' => 'test']));
    }
}
