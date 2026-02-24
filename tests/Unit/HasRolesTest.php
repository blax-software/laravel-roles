<?php

namespace Blax\Roles\Tests\Unit;

use Blax\Roles\Models\Permission;
use Blax\Roles\Models\Role;
use Blax\Roles\RolesServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\User;

class HasRolesTest extends TestCase
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

    // ─── roles relationship ──────────────────────────────────────

    public function test_user_has_no_roles_by_default(): void
    {
        $user = User::factory()->create();
        $this->assertCount(0, $user->roles);
    }

    // ─── hasRole ─────────────────────────────────────────────────

    public function test_has_role_returns_false_when_not_assigned(): void
    {
        $user = User::factory()->create();
        Role::create(['name' => 'Admin', 'slug' => 'admin']);

        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_has_role_by_slug_string(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $user->assignRole($role);

        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_has_role_by_model_instance(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Editor', 'slug' => 'editor']);
        $user->assignRole($role);

        $this->assertTrue($user->hasRole($role));
    }

    public function test_has_role_returns_false_for_nonexistent_slug(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->hasRole('nonexistent'));
    }

    // ─── assignRole ──────────────────────────────────────────────

    public function test_assign_role_by_string_creates_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('new-role');

        $this->assertDatabaseHas('roles', ['slug' => 'new-role']);
        $this->assertTrue($user->hasRole('new-role'));
    }

    public function test_assign_role_by_model_instance(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Writer', 'slug' => 'writer']);
        $user->assignRole($role);

        $this->assertTrue($user->hasRole($role));
    }

    public function test_assign_role_by_numeric_id(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Viewer', 'slug' => 'viewer']);
        $user->assignRole($role->id);

        $this->assertTrue($user->hasRole('viewer'));
    }

    public function test_assign_role_respects_max_times_limit(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Limited', 'slug' => 'limited']);

        // max_times = 1 (default), so second assign should be ignored
        $user->assignRole($role, 1);
        $user->assignRole($role, 1);

        $count = DB::table(config('roles.table_names.role_member'))
            ->where('member_id', $user->id)
            ->where('member_type', $user->getMorphClass())
            ->where('role_id', $role->id)
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_assign_role_allows_duplicates_when_max_times_higher(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Stackable', 'slug' => 'stackable']);

        $user->assignRole($role, 3);
        $user->assignRole($role, 3);
        $user->assignRole($role, 3);
        $user->assignRole($role, 3); // should be blocked

        $count = DB::table(config('roles.table_names.role_member'))
            ->where('member_id', $user->id)
            ->where('member_type', $user->getMorphClass())
            ->where('role_id', $role->id)
            ->count();

        $this->assertEquals(3, $count);
    }

    public function test_assign_role_throws_on_invalid_argument(): void
    {
        $user = User::factory()->create();

        // Type-hinted as string|Role, so stdClass triggers TypeError
        $this->expectException(\TypeError::class);
        $user->assignRole(new \stdClass());
    }

    // ─── removeRole ──────────────────────────────────────────────

    public function test_remove_role_by_model(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Temp', 'slug' => 'temp']);
        $user->assignRole($role);
        $this->assertTrue($user->hasRole($role));

        $user->removeRole($role);

        // Refresh to clear cached relations
        $user->load('roles');
        $this->assertFalse($user->hasRole($role));
    }

    public function test_remove_role_by_slug(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Removable', 'slug' => 'removable']);
        $user->assignRole($role);

        $user->removeRole('removable');
        $user->load('roles');
        $this->assertFalse($user->hasRole('removable'));
    }

    public function test_remove_role_does_not_affect_other_roles(): void
    {
        $user = User::factory()->create();
        $keepRole = Role::create(['name' => 'Keep', 'slug' => 'keep']);
        $removeRole = Role::create(['name' => 'Remove', 'slug' => 'remove']);

        $user->assignRole($keepRole);
        $user->assignRole($removeRole);

        $user->removeRole($removeRole);
        $user->load('roles');

        $this->assertTrue($user->hasRole($keepRole));
        $this->assertFalse($user->hasRole($removeRole));
    }

    // ─── syncRoles ───────────────────────────────────────────────

    public function test_sync_roles_by_slug_strings(): void
    {
        $user = User::factory()->create();
        $user->assignRole('old-role');

        $user->syncRoles(['new-role-1', 'new-role-2']);
        $user->load('roles');

        $this->assertFalse($user->hasRole('old-role'));
        $this->assertTrue($user->hasRole('new-role-1'));
        $this->assertTrue($user->hasRole('new-role-2'));
    }

    public function test_sync_roles_by_model_instances(): void
    {
        $user = User::factory()->create();
        $role1 = Role::create(['name' => 'Role1', 'slug' => 'role1']);
        $role2 = Role::create(['name' => 'Role2', 'slug' => 'role2']);

        $user->syncRoles([$role1, $role2]);
        $user->load('roles');

        $this->assertTrue($user->hasRole($role1));
        $this->assertTrue($user->hasRole($role2));
    }

    public function test_sync_roles_by_numeric_ids(): void
    {
        $user = User::factory()->create();
        $role1 = Role::create(['name' => 'R1', 'slug' => 'r1']);
        $role2 = Role::create(['name' => 'R2', 'slug' => 'r2']);

        $user->syncRoles([$role1->id, $role2->id]);
        $user->load('roles');

        $this->assertTrue($user->hasRole('r1'));
        $this->assertTrue($user->hasRole('r2'));
    }

    public function test_sync_roles_by_objects_with_id(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'ObjRole', 'slug' => 'objrole']);

        $user->syncRoles([(object) ['id' => $role->id]]);
        $user->load('roles');

        $this->assertTrue($user->hasRole('objrole'));
    }

    public function test_sync_roles_by_arrays_with_id(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'ArrRole', 'slug' => 'arrrole']);

        $user->syncRoles([['id' => $role->id]]);
        $user->load('roles');

        $this->assertTrue($user->hasRole('arrrole'));
    }

    public function test_sync_roles_empty_removes_all(): void
    {
        $user = User::factory()->create();
        $user->assignRole('existing');

        $user->syncRoles([]);
        $user->load('roles');

        $this->assertCount(0, $user->roles);
    }

    // ─── hasAnyRole ──────────────────────────────────────────────

    public function test_has_any_role_returns_true_when_one_matches(): void
    {
        $user = User::factory()->create();
        $user->assignRole('editor');

        $this->assertTrue($user->hasAnyRole(['admin', 'editor', 'viewer']));
    }

    public function test_has_any_role_returns_false_when_none_match(): void
    {
        $user = User::factory()->create();
        $user->assignRole('writer');

        $this->assertFalse($user->hasAnyRole(['admin', 'editor']));
    }

    public function test_has_any_role_returns_false_for_empty_array(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->hasAnyRole([]));
    }

    // ─── hasAllRoles ─────────────────────────────────────────────

    public function test_has_all_roles_returns_true_when_all_match(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->assignRole('editor');

        $this->assertTrue($user->hasAllRoles(['admin', 'editor']));
    }

    public function test_has_all_roles_returns_false_when_one_missing(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->assertFalse($user->hasAllRoles(['admin', 'editor']));
    }

    public function test_has_all_roles_returns_true_for_empty_array(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->hasAllRoles([]));
    }

    // ─── Expiration ──────────────────────────────────────────────

    public function test_expired_role_is_not_returned_in_roles_relation(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Expired', 'slug' => 'expired']);

        DB::table(config('roles.table_names.role_member'))->insert([
            'role_id' => $role->id,
            'member_id' => $user->id,
            'member_type' => $user->getMorphClass(),
            'expires_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(0, $user->roles);
        $this->assertFalse($user->hasRole($role));
    }

    public function test_non_expired_role_is_returned_in_roles_relation(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Future', 'slug' => 'future']);

        DB::table(config('roles.table_names.role_member'))->insert([
            'role_id' => $role->id,
            'member_id' => $user->id,
            'member_type' => $user->getMorphClass(),
            'expires_at' => now()->addWeek(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(1, $user->roles);
        $this->assertTrue($user->hasRole($role));
    }

    // ─── extendOrAddRole ─────────────────────────────────────────

    public function test_extend_or_add_role_creates_new_membership(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Extend', 'slug' => 'extend']);

        $user->extendOrAddRole($role, 48);

        $membership = DB::table(config('roles.table_names.role_member'))
            ->where('role_id', $role->id)
            ->where('member_id', $user->id)
            ->first();

        $this->assertNotNull($membership);
        $this->assertNotNull($membership->expires_at);
    }

    public function test_extend_or_add_role_extends_existing_future_membership(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Ext', 'slug' => 'ext']);

        // Create initial membership expiring in 24 hours
        DB::table(config('roles.table_names.role_member'))->insert([
            'role_id' => $role->id,
            'member_id' => $user->id,
            'member_type' => $user->getMorphClass(),
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->extendOrAddRole($role, 24);

        // Should now expire in ~48 hours from original base
        $membership = DB::table(config('roles.table_names.role_member'))
            ->where('role_id', $role->id)
            ->where('member_id', $user->id)
            ->first();

        $this->assertNotNull($membership);
        // Should be extended: approximately 48 hours from now
        $expiresAt = \Carbon\Carbon::parse($membership->expires_at);
        $this->assertTrue($expiresAt->gt(now()->addHours(40)));
    }

    public function test_extend_or_add_role_does_not_modify_null_expiry(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'NoExp', 'slug' => 'noexp']);

        // Create a permanent membership
        DB::table(config('roles.table_names.role_member'))->insert([
            'role_id' => $role->id,
            'member_id' => $user->id,
            'member_type' => $user->getMorphClass(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->extendOrAddRole($role, 24);

        $membership = DB::table(config('roles.table_names.role_member'))
            ->where('role_id', $role->id)
            ->where('member_id', $user->id)
            ->first();

        // Should still be null (permanent)
        $this->assertNull($membership->expires_at);
    }

    public function test_extend_or_add_role_with_zero_hours_does_nothing(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Zero', 'slug' => 'zero']);

        $user->extendOrAddRole($role, 0);

        $count = DB::table(config('roles.table_names.role_member'))
            ->where('role_id', $role->id)
            ->where('member_id', $user->id)
            ->count();

        $this->assertEquals(0, $count);
    }

    public function test_extend_or_add_role_by_slug_string(): void
    {
        $user = User::factory()->create();
        // extendOrAddRole resolves strings via firstOrCreate by name
        $role = Role::create(['name' => 'byslug', 'slug' => 'byslug']);

        $user->extendOrAddRole('byslug', 12);

        $this->assertTrue($user->hasRole('byslug'));
    }

    public function test_extend_or_add_role_by_numeric_id(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'ByNum', 'slug' => 'bynum']);

        $user->extendOrAddRole($role->id, 12);

        $this->assertTrue($user->hasRole('bynum'));
    }

    // ─── Roles independence between users ────────────────────────

    public function test_roles_are_independent_between_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user1->assignRole('admin');
        $user2->assignRole('editor');

        $this->assertTrue($user1->hasRole('admin'));
        $this->assertFalse($user1->hasRole('editor'));
        $this->assertTrue($user2->hasRole('editor'));
        $this->assertFalse($user2->hasRole('admin'));
    }

    // ─── Role slug uniqueness ────────────────────────────────────

    public function test_role_slug_is_auto_suffixed_on_conflict(): void
    {
        $role1 = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $role2 = Role::create(['name' => 'Admin', 'slug' => 'admin']);

        $this->assertEquals('admin', $role1->slug);
        $this->assertEquals('admin-1', $role2->slug);
    }
}
