<?php

namespace Blax\Roles\Tests\Unit;

use Blax\Roles\Models\Permission;
use Blax\Roles\Models\Role;
use Blax\Roles\RolesServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\User;

class HasPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [RolesServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../workbench/database/migrations');
    }

    // ─── hasPermission ───────────────────────────────────────────

    public function test_has_permission_returns_false_when_user_has_no_permissions(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->hasPermission('blog'));
    }

    public function test_has_permission_with_direct_exact_match(): void
    {
        $user = User::factory()->create();
        $user->assignPermission('blog');

        $this->assertTrue($user->hasPermission('blog'));
    }

    public function test_has_permission_with_hierarchical_parent_grants_child(): void
    {
        $user = User::factory()->create();
        $user->assignPermission('lection');

        $this->assertTrue($user->hasPermission('lection'));
        $this->assertTrue($user->hasPermission('lection.45'));
        $this->assertTrue($user->hasPermission('lection.foo.bar'));
    }

    public function test_has_permission_hierarchical_child_does_not_grant_parent(): void
    {
        $user = User::factory()->create();
        $user->assignPermission('lection.45');

        $this->assertTrue($user->hasPermission('lection.45'));
        $this->assertFalse($user->hasPermission('lection'));
    }

    public function test_has_permission_hierarchical_sibling_not_granted(): void
    {
        $user = User::factory()->create();
        $user->assignPermission('lection.45');

        $this->assertFalse($user->hasPermission('lection.99'));
    }

    public function test_has_permission_wildcard_star_grants_everything(): void
    {
        $user = User::factory()->create();
        $user->assignPermission('*');

        $this->assertTrue($user->hasPermission('anything'));
        $this->assertTrue($user->hasPermission('blog.edit'));
        $this->assertTrue($user->hasPermission('deep.nested.permission.chain'));
    }

    public function test_has_permission_via_role(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Editor', 'slug' => 'editor']);
        $perm = Permission::create(['slug' => 'blog.edit']);

        // Assign permission to role
        $role->assignPermission($perm);
        // Assign role to user
        $user->assignRole($role);

        $this->assertTrue($user->hasPermission('blog.edit'));
    }

    public function test_has_permission_via_role_hierarchical(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Learner', 'slug' => 'learner']);
        $perm = Permission::create(['slug' => 'lection']);

        $role->assignPermission($perm);
        $user->assignRole($role);

        $this->assertTrue($user->hasPermission('lection'));
        $this->assertTrue($user->hasPermission('lection.45'));
        $this->assertTrue($user->hasPermission('lection.foo.bar'));
        $this->assertFalse($user->hasPermission('blog'));
    }

    public function test_has_permission_does_not_match_partial_slug(): void
    {
        $user = User::factory()->create();
        $user->assignPermission('blog');

        // "blogging" is NOT a child of "blog" — it doesn't start with "blog."
        $this->assertFalse($user->hasPermission('blogging'));
    }

    // ─── rolePermissions ─────────────────────────────────────────

    public function test_role_permissions_returns_empty_when_no_roles(): void
    {
        $user = User::factory()->create();
        $this->assertCount(0, $user->rolePermissions());
    }

    public function test_role_permissions_returns_permissions_from_assigned_role(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $perm1 = Permission::create(['slug' => 'blog.edit']);
        $perm2 = Permission::create(['slug' => 'blog.delete']);

        $role->assignPermission($perm1);
        $role->assignPermission($perm2);
        $user->assignRole($role);

        $perms = $user->rolePermissions();
        $this->assertCount(2, $perms);
        $this->assertTrue($perms->contains('slug', 'blog.edit'));
        $this->assertTrue($perms->contains('slug', 'blog.delete'));
    }

    public function test_role_permissions_deduplicates_across_multiple_roles(): void
    {
        $user = User::factory()->create();
        $role1 = Role::create(['name' => 'Editor', 'slug' => 'editor']);
        $role2 = Role::create(['name' => 'Reviewer', 'slug' => 'reviewer']);
        $perm = Permission::create(['slug' => 'blog.view']);

        $role1->assignPermission($perm);
        $role2->assignPermission($perm);
        $user->assignRole($role1);
        $user->assignRole($role2);

        // rolePermissions returns raw — but permissions() deduplicates
        $perms = $user->permissions();
        $this->assertCount(1, $perms);
    }

    public function test_role_permissions_excludes_expired_role_membership(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Temp', 'slug' => 'temp']);
        $perm = Permission::create(['slug' => 'temp.access']);

        $role->assignPermission($perm);

        // Manually insert an expired role membership
        DB::table(config('roles.table_names.role_member'))->insert([
            'role_id' => $role->id,
            'member_id' => $user->id,
            'member_type' => $user->getMorphClass(),
            'expires_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(0, $user->rolePermissions());
    }

    public function test_role_permissions_includes_non_expired_role_membership(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Active', 'slug' => 'active']);
        $perm = Permission::create(['slug' => 'active.access']);

        $role->assignPermission($perm);

        // Manually insert a future-expiry role membership
        DB::table(config('roles.table_names.role_member'))->insert([
            'role_id' => $role->id,
            'member_id' => $user->id,
            'member_type' => $user->getMorphClass(),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(1, $user->rolePermissions());
    }

    public function test_role_permissions_includes_null_expiry_role_membership(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Permanent', 'slug' => 'permanent']);
        $perm = Permission::create(['slug' => 'permanent.access']);

        $role->assignPermission($perm);
        $user->assignRole($role);

        $this->assertCount(1, $user->rolePermissions());
    }

    public function test_role_permissions_excludes_expired_permission_on_role(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Editor', 'slug' => 'editor']);
        $perm = Permission::create(['slug' => 'temp.perm']);

        // Manually attach permission to role with expired membership
        DB::table(config('roles.table_names.permission_member'))->insert([
            'permission_id' => $perm->id,
            'member_id' => $role->id,
            'member_type' => $role->getMorphClass(),
            'expires_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->assignRole($role);

        $this->assertCount(0, $user->rolePermissions());
    }

    // ─── individualPermissions ───────────────────────────────────

    public function test_individual_permissions_returns_empty_when_none_assigned(): void
    {
        $user = User::factory()->create();
        $this->assertCount(0, $user->individualPermissions()->get());
    }

    public function test_individual_permissions_returns_directly_assigned(): void
    {
        $user = User::factory()->create();
        $user->assignPermission('direct.perm');

        $perms = $user->individualPermissions()->get();
        $this->assertCount(1, $perms);
        $this->assertEquals('direct.perm', $perms->first()->slug);
    }

    // ─── permissions (merged) ────────────────────────────────────

    public function test_permissions_merges_role_and_direct_permissions(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Writer', 'slug' => 'writer']);
        $rolePerm = Permission::create(['slug' => 'blog.write']);
        $role->assignPermission($rolePerm);
        $user->assignRole($role);

        $user->assignPermission('profile.edit');

        $all = $user->permissions();
        $this->assertCount(2, $all);
        $this->assertTrue($all->contains('slug', 'blog.write'));
        $this->assertTrue($all->contains('slug', 'profile.edit'));
    }

    public function test_permissions_deduplicates_when_same_permission_from_role_and_direct(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Writer', 'slug' => 'writer']);
        $perm = Permission::create(['slug' => 'shared.perm']);

        $role->assignPermission($perm);
        $user->assignRole($role);
        $user->assignPermission('shared.perm');

        $all = $user->permissions();
        $this->assertCount(1, $all);
    }

    // ─── assignPermission ────────────────────────────────────────

    public function test_assign_permission_by_slug_string(): void
    {
        $user = User::factory()->create();
        $user->assignPermission('new.permission');

        $this->assertTrue($user->hasPermission('new.permission'));
        // Should have created the permission
        $this->assertDatabaseHas('permissions', ['slug' => 'new.permission']);
    }

    public function test_assign_permission_by_id(): void
    {
        $perm = Permission::create(['slug' => 'by.id']);
        $user = User::factory()->create();
        $user->assignPermission($perm->id);

        $this->assertTrue($user->hasPermission('by.id'));
    }

    public function test_assign_permission_by_model_instance(): void
    {
        $perm = Permission::create(['slug' => 'by.instance']);
        $user = User::factory()->create();
        $user->assignPermission($perm);

        $this->assertTrue($user->hasPermission('by.instance'));
    }

    public function test_assign_permission_is_idempotent(): void
    {
        $user = User::factory()->create();
        $user->assignPermission('idempotent.test');
        $user->assignPermission('idempotent.test');

        // Should only have one entry in the pivot table
        $count = DB::table(config('roles.table_names.permission_member'))
            ->where('member_id', $user->id)
            ->where('member_type', $user->getMorphClass())
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_assign_permission_throws_on_invalid_argument(): void
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $user->assignPermission(['invalid']);
    }

    public function test_assign_permission_creates_permission_if_not_exists(): void
    {
        $user = User::factory()->create();
        $this->assertDatabaseMissing('permissions', ['slug' => 'auto.created']);

        $user->assignPermission('auto.created');

        $this->assertDatabaseHas('permissions', ['slug' => 'auto.created']);
    }

    // ─── removePermission ────────────────────────────────────────

    public function test_remove_permission_by_slug(): void
    {
        $user = User::factory()->create();
        $user->assignPermission('to.remove');
        $this->assertTrue($user->hasPermission('to.remove'));

        $user->removePermission('to.remove');

        // Reload permissions
        $this->assertFalse($user->hasPermission('to.remove'));
    }

    public function test_remove_permission_by_id(): void
    {
        $perm = Permission::create(['slug' => 'remove.by.id']);
        $user = User::factory()->create();
        $user->assignPermission($perm);

        $user->removePermission($perm->id);

        $count = $user->individualPermissions()->count();
        $this->assertEquals(0, $count);
    }

    public function test_remove_permission_by_model(): void
    {
        $perm = Permission::create(['slug' => 'remove.by.model']);
        $user = User::factory()->create();
        $user->assignPermission($perm);

        $user->removePermission($perm);

        $count = $user->individualPermissions()->count();
        $this->assertEquals(0, $count);
    }

    public function test_remove_permission_that_does_not_exist_returns_true(): void
    {
        $user = User::factory()->create();
        $result = $user->removePermission('nonexistent');
        $this->assertTrue($result);
    }

    public function test_remove_permission_does_not_affect_other_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignPermission('keep.this');
        $user->assignPermission('remove.this');

        $user->removePermission('remove.this');

        $this->assertTrue($user->hasPermission('keep.this'));
        $this->assertFalse($user->hasPermission('remove.this'));
    }

    public function test_remove_permission_does_not_affect_role_permissions(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $perm = Permission::create(['slug' => 'role.perm']);
        $role->assignPermission($perm);
        $user->assignRole($role);

        // User also directly has the same permission
        $user->assignPermission($perm);

        // Remove the direct assignment
        $user->removePermission($perm);

        // The user should still have it via role
        $this->assertTrue($user->hasPermission('role.perm'));
    }

    // ─── Role model also uses HasPermissions ─────────────────────

    public function test_role_can_have_permissions_assigned(): void
    {
        $role = Role::create(['name' => 'Moderator', 'slug' => 'moderator']);
        $role->assignPermission('moderate.posts');

        $this->assertTrue($role->hasPermission('moderate.posts'));
    }

    public function test_role_has_permission_hierarchical(): void
    {
        $role = Role::create(['name' => 'Learner', 'slug' => 'learner']);
        $role->assignPermission('lection');

        $this->assertTrue($role->hasPermission('lection.42'));
        $this->assertFalse($role->hasPermission('blog'));
    }

    // ─── Multiple users independence ─────────────────────────────

    public function test_permissions_are_independent_between_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user1->assignPermission('user1.only');
        $user2->assignPermission('user2.only');

        $this->assertTrue($user1->hasPermission('user1.only'));
        $this->assertFalse($user1->hasPermission('user2.only'));
        $this->assertTrue($user2->hasPermission('user2.only'));
        $this->assertFalse($user2->hasPermission('user1.only'));
    }
}
