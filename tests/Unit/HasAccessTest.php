<?php

namespace Blax\Roles\Tests\Unit;

use Blax\Roles\Models\Access;
use Blax\Roles\Models\Permission;
use Blax\Roles\Models\Role;
use Blax\Roles\RolesServiceProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

class HasAccessTest extends TestCase
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

    // ─── accesses relationship ───────────────────────────────────

    public function test_accesses_returns_empty_by_default(): void
    {
        $user = User::factory()->create();
        $this->assertCount(0, $user->accesses);
    }

    // ─── grantAccess ─────────────────────────────────────────────

    public function test_grant_access_creates_access_entry(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Test Article']);

        $access = $user->grantAccess($article);

        $this->assertInstanceOf(Access::class, $access);
        $this->assertEquals($user->getMorphClass(), $access->entity_type);
        $this->assertEquals($user->id, $access->entity_id);
        $this->assertEquals($article->getMorphClass(), $access->accessible_type);
        $this->assertEquals($article->id, $access->accessible_id);
    }

    public function test_grant_access_with_context(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Contextual']);

        $access = $user->grantAccess($article, ['reason' => 'purchased']);

        $this->assertEquals(['reason' => 'purchased'], $access->context);
    }

    public function test_grant_access_with_expiration(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Expiring']);
        $expiresAt = Carbon::now()->addDays(30);

        $access = $user->grantAccess($article, null, $expiresAt);

        $this->assertNotNull($access->expires_at);
        // Compare with second precision to avoid microsecond drift
        $this->assertEquals(
            $expiresAt->format('Y-m-d H:i:s'),
            $access->expires_at->format('Y-m-d H:i:s')
        );
    }

    public function test_grant_access_is_idempotent(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Idempotent']);

        $access1 = $user->grantAccess($article);
        $access2 = $user->grantAccess($article);

        $this->assertEquals($access1->id, $access2->id);
        $this->assertEquals(1, $user->accesses()->count());
    }

    // ─── hasAccess ───────────────────────────────────────────────

    public function test_has_access_returns_false_when_no_access(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Locked']);

        $this->assertFalse($user->hasAccess($article));
    }

    public function test_has_access_returns_true_with_direct_access(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Unlocked']);

        $user->grantAccess($article);

        $this->assertTrue($user->hasAccess($article));
    }

    public function test_has_access_with_class_name_and_id(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'ByClassName']);

        $user->grantAccess($article);

        $this->assertTrue($user->hasAccess(Article::class, $article->id));
    }

    public function test_has_access_with_class_name_without_id_throws(): void
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $user->hasAccess(Article::class);
    }

    public function test_has_access_via_role(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Premium', 'slug' => 'premium']);
        $article = Article::create(['title' => 'Premium Article']);

        // Grant access to the role (Role uses HasPermissions which uses HasAccess)
        $role->grantAccess($article);
        // Assign role to user
        $user->assignRole($role);

        $this->assertTrue($user->hasAccess($article));
    }

    public function test_has_access_via_permission(): void
    {
        $user = User::factory()->create();
        $perm = Permission::create(['slug' => 'blog.premium']);
        $article = Article::create(['title' => 'Permission Article']);

        // Grant access to the permission (Permission uses HasAccess)
        $perm->grantAccess($article);
        // Assign permission to user
        $user->assignPermission($perm);

        $this->assertTrue($user->hasAccess($article));
    }

    public function test_has_access_via_role_permission_chain(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Learner', 'slug' => 'learner']);
        $perm = Permission::create(['slug' => 'lection']);
        $article = Article::create(['title' => 'Lesson']);

        // Permission has access to article
        $perm->grantAccess($article);
        // Role has permission
        $role->assignPermission($perm);
        // User has role
        $user->assignRole($role);

        // User should have access via: user → role → permission → access
        $this->assertTrue($user->hasAccess($article));
    }

    public function test_has_access_expired_direct_access_returns_false(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Expired']);

        $user->grantAccess($article, null, Carbon::now()->subDay());

        // grantAccess uses firstOrCreate, so it won't overwrite.
        // We need to update the entry directly.
        $access = $user->accesses()->first();
        $access->update(['expires_at' => Carbon::now()->subDay()]);

        $this->assertFalse($user->hasAccess($article));
    }

    public function test_has_access_non_expired_returns_true(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'NotExpired']);

        $user->grantAccess($article, null, Carbon::now()->addWeek());

        $this->assertTrue($user->hasAccess($article));
    }

    public function test_has_access_null_expiry_returns_true(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Permanent']);

        $user->grantAccess($article);

        $this->assertTrue($user->hasAccess($article));
    }

    public function test_has_access_via_expired_role_returns_false(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'ExpRole', 'slug' => 'exprole']);
        $article = Article::create(['title' => 'RoleExpired']);

        $role->grantAccess($article);

        // Manually insert expired role membership
        DB::table(config('roles.table_names.role_member'))->insert([
            'role_id' => $role->id,
            'member_id' => $user->id,
            'member_type' => $user->getMorphClass(),
            'expires_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse($user->hasAccess($article));
    }

    // ─── revokeAccess ────────────────────────────────────────────

    public function test_revoke_access_by_model(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Revoke']);
        $user->grantAccess($article);

        $deleted = $user->revokeAccess($article);

        $this->assertEquals(1, $deleted);
        $this->assertFalse($user->hasAccess($article));
    }

    public function test_revoke_access_by_class_name_and_id(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'RevokeByClass']);
        $user->grantAccess($article);

        $deleted = $user->revokeAccess(Article::class, $article->id);

        $this->assertEquals(1, $deleted);
        $this->assertFalse($user->hasAccess($article));
    }

    public function test_revoke_access_returns_zero_when_nothing_to_revoke(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'NothingToRevoke']);

        $deleted = $user->revokeAccess($article);

        $this->assertEquals(0, $deleted);
    }

    public function test_revoke_access_does_not_affect_other_accessibles(): void
    {
        $user = User::factory()->create();
        $article1 = Article::create(['title' => 'Keep']);
        $article2 = Article::create(['title' => 'Remove']);

        $user->grantAccess($article1);
        $user->grantAccess($article2);

        $user->revokeAccess($article2);

        $this->assertTrue($user->hasAccess($article1));
        $this->assertFalse($user->hasAccess($article2));
    }

    // ─── revokeAllAccess ─────────────────────────────────────────

    public function test_revoke_all_access_removes_everything(): void
    {
        $user = User::factory()->create();
        $article1 = Article::create(['title' => 'A1']);
        $article2 = Article::create(['title' => 'A2']);

        $user->grantAccess($article1);
        $user->grantAccess($article2);

        $deleted = $user->revokeAllAccess();

        $this->assertEquals(2, $deleted);
        $this->assertFalse($user->hasAccess($article1));
        $this->assertFalse($user->hasAccess($article2));
    }

    public function test_revoke_all_access_filtered_by_type(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'FilteredArticle']);

        // Grant access to user model (different type) + article
        $otherUser = User::factory()->create();
        $user->grantAccess($article);
        $user->grantAccess($otherUser);

        $deleted = $user->revokeAllAccess(Article::class);

        $this->assertEquals(1, $deleted);
        $this->assertFalse($user->hasAccess($article));
        $this->assertTrue($user->hasAccess($otherUser));
    }

    // ─── allAccess ───────────────────────────────────────────────

    public function test_all_access_returns_direct_accesses(): void
    {
        $user = User::factory()->create();
        $article1 = Article::create(['title' => 'AA1']);
        $article2 = Article::create(['title' => 'AA2']);

        $user->grantAccess($article1);
        $user->grantAccess($article2);

        $accesses = $user->allAccess();
        $this->assertCount(2, $accesses);
    }

    public function test_all_access_includes_role_based_accesses(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Reader', 'slug' => 'reader']);
        $article = Article::create(['title' => 'RoleAccess']);

        $role->grantAccess($article);
        $user->assignRole($role);

        $accesses = $user->allAccess();
        $this->assertCount(1, $accesses);
        $this->assertEquals($article->id, $accesses->first()->accessible_id);
    }

    public function test_all_access_includes_permission_based_accesses(): void
    {
        $user = User::factory()->create();
        $perm = Permission::create(['slug' => 'premium.content']);
        $article = Article::create(['title' => 'PermAccess']);

        $perm->grantAccess($article);
        $user->assignPermission($perm);

        $accesses = $user->allAccess();
        $this->assertCount(1, $accesses);
    }

    public function test_all_access_filtered_by_type(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'TypeFilter']);
        $otherUser = User::factory()->create();

        $user->grantAccess($article);
        $user->grantAccess($otherUser);

        $articleAccesses = $user->allAccess(Article::class);
        $this->assertCount(1, $articleAccesses);
    }

    public function test_all_access_excludes_expired_entries(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'ExpAccess']);

        $user->grantAccess($article);
        // Manually expire
        $user->accesses()->update(['expires_at' => now()->subHour()]);

        $accesses = $user->allAccess();
        $this->assertCount(0, $accesses);
    }

    // ─── accessibleIds ───────────────────────────────────────────

    public function test_accessible_ids_returns_correct_ids(): void
    {
        $user = User::factory()->create();
        $a1 = Article::create(['title' => 'AI1']);
        $a2 = Article::create(['title' => 'AI2']);
        $a3 = Article::create(['title' => 'AI3']);

        $user->grantAccess($a1);
        $user->grantAccess($a3);

        $ids = $user->accessibleIds(Article::class);
        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($a1->id));
        $this->assertTrue($ids->contains($a3->id));
        $this->assertFalse($ids->contains($a2->id));
    }

    public function test_accessible_ids_includes_role_based(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Sub', 'slug' => 'sub']);
        $article = Article::create(['title' => 'RoleAI']);

        $role->grantAccess($article);
        $user->assignRole($role);

        $ids = $user->accessibleIds(Article::class);
        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($article->id));
    }

    public function test_accessible_ids_returns_empty_when_no_access(): void
    {
        $user = User::factory()->create();
        Article::create(['title' => 'NoAccess']);

        $ids = $user->accessibleIds(Article::class);
        $this->assertCount(0, $ids);
    }

    // ─── syncAccess ──────────────────────────────────────────────

    public function test_sync_access_adds_and_removes_entries(): void
    {
        $user = User::factory()->create();
        $a1 = Article::create(['title' => 'S1']);
        $a2 = Article::create(['title' => 'S2']);
        $a3 = Article::create(['title' => 'S3']);

        // Initial: access to a1 and a2
        $user->grantAccess($a1);
        $user->grantAccess($a2);

        // Sync to a2 and a3
        $user->syncAccess(Article::class, [$a2->id, $a3->id]);

        $this->assertFalse($user->hasAccess($a1));
        $this->assertTrue($user->hasAccess($a2));
        $this->assertTrue($user->hasAccess($a3));
    }

    public function test_sync_access_empty_removes_all_of_that_type(): void
    {
        $user = User::factory()->create();
        $a1 = Article::create(['title' => 'SE1']);
        $a2 = Article::create(['title' => 'SE2']);

        $user->grantAccess($a1);
        $user->grantAccess($a2);

        $user->syncAccess(Article::class, []);

        $this->assertFalse($user->hasAccess($a1));
        $this->assertFalse($user->hasAccess($a2));
    }

    public function test_sync_access_does_not_affect_other_types(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'TypeKeep']);
        $otherUser = User::factory()->create();

        $user->grantAccess($article);
        $user->grantAccess($otherUser);

        // Sync articles to empty — should keep user access
        $user->syncAccess(Article::class, []);

        $this->assertFalse($user->hasAccess($article));
        $this->assertTrue($user->hasAccess($otherUser));
    }

    public function test_sync_access_with_context_and_expiry(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'CtxSync']);
        $expiresAt = Carbon::now()->addMonth();

        $user->syncAccess(Article::class, [$article->id], ['reason' => 'promo'], $expiresAt);

        $access = $user->accesses()->first();
        $this->assertEquals(['reason' => 'promo'], $access->context);
        $this->assertEquals(
            $expiresAt->format('Y-m-d H:i:s'),
            $access->expires_at->format('Y-m-d H:i:s')
        );
    }

    public function test_sync_access_preserves_existing_entries_in_new_set(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'PreserveSync']);

        $user->grantAccess($article, ['reason' => 'original']);
        $originalId = $user->accesses()->first()->id;

        // Sync with the same ID — should NOT recreate the entry
        $user->syncAccess(Article::class, [$article->id]);

        $currentId = $user->accesses()->first()->id;
        $this->assertEquals($originalId, $currentId);
    }

    // ─── Access independence ─────────────────────────────────────

    public function test_access_is_independent_between_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $article = Article::create(['title' => 'Independent']);

        $user1->grantAccess($article);

        $this->assertTrue($user1->hasAccess($article));
        $this->assertFalse($user2->hasAccess($article));
    }

    // ─── Access scopes on model ──────────────────────────────────

    public function test_access_active_scope(): void
    {
        $user = User::factory()->create();
        $article1 = Article::create(['title' => 'Active']);
        $article2 = Article::create(['title' => 'Expired']);

        $user->grantAccess($article1); // no expiry = active
        $user->grantAccess($article2, null, Carbon::now()->subDay());
        // Manually expire
        $user->accesses()->where('accessible_id', $article2->id)->update(['expires_at' => now()->subDay()]);

        $activeAccesses = Access::active()->where('entity_id', $user->id)->get();
        $this->assertCount(1, $activeAccesses);
    }

    public function test_access_expired_scope(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'ExpiredScope']);

        $user->grantAccess($article);
        $user->accesses()->update(['expires_at' => now()->subHour()]);

        $expiredAccesses = Access::expired()->where('entity_id', $user->id)->get();
        $this->assertCount(1, $expiredAccesses);
    }

    // ─── Complex multi-source access scenarios ───────────────────

    public function test_has_access_combines_direct_and_role_sources(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Sub', 'slug' => 'sub']);
        $directArticle = Article::create(['title' => 'Direct']);
        $roleArticle = Article::create(['title' => 'ViaRole']);

        $user->grantAccess($directArticle);
        $role->grantAccess($roleArticle);
        $user->assignRole($role);

        $this->assertTrue($user->hasAccess($directArticle));
        $this->assertTrue($user->hasAccess($roleArticle));
    }

    public function test_has_access_combines_all_three_sources(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Sub', 'slug' => 'sub']);
        $perm = Permission::create(['slug' => 'premium']);
        $a1 = Article::create(['title' => 'DirectAll']);
        $a2 = Article::create(['title' => 'RoleAll']);
        $a3 = Article::create(['title' => 'PermAll']);

        $user->grantAccess($a1);
        $role->grantAccess($a2);
        $perm->grantAccess($a3);

        $user->assignRole($role);
        $user->assignPermission($perm);

        $this->assertTrue($user->hasAccess($a1));
        $this->assertTrue($user->hasAccess($a2));
        $this->assertTrue($user->hasAccess($a3));

        $allAccess = $user->allAccess(Article::class);
        $this->assertCount(3, $allAccess);
    }

    public function test_model_without_roles_has_no_role_access(): void
    {
        // Permission model has HasAccess but NOT HasRoles
        $perm = Permission::create(['slug' => 'simple']);
        $article = Article::create(['title' => 'Solo']);

        $perm->grantAccess($article);
        $this->assertTrue($perm->hasAccess($article));

        // allAccess should work even without roles
        $accesses = $perm->allAccess();
        $this->assertCount(1, $accesses);
    }

    // ─── Access entity/accessible relationships ──────────────────

    public function test_access_entity_relationship(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'RelEntity']);

        $access = $user->grantAccess($article);

        $entity = $access->entity;
        $this->assertInstanceOf(User::class, $entity);
        $this->assertEquals($user->id, $entity->id);
    }

    public function test_access_accessible_relationship(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'RelAccessible']);

        $access = $user->grantAccess($article);

        $accessible = $access->accessible;
        $this->assertInstanceOf(Article::class, $accessible);
        $this->assertEquals($article->id, $accessible->id);
    }
}
