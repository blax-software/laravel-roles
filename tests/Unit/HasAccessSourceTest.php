<?php

namespace Blax\Roles\Tests\Unit;

use Blax\Roles\Events\SourceAccessesRevoked;
use Blax\Roles\Models\Access;
use Blax\Roles\RolesServiceProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\Subscription;
use Workbench\App\Models\User;

/**
 * Coverage for source-tied grants: subscription cascade cleanup, lifetime +
 * subscription coexistence, manual grants surviving cascades, sync scoping,
 * and renewal idempotency.
 */
class HasAccessSourceTest extends TestCase
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
        // Tests use workbench-specific UUID-aware migrations; disable the
        // package's auto-load so the same tables aren't created twice.
        $app['config']->set('roles.run_migrations', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../workbench/database/migrations');
    }

    // ─── grantAccess with source ─────────────────────────────────

    public function test_grant_access_records_source(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'A']);
        $sub = Subscription::create(['name' => 'monthly']);

        $access = $user->grantAccess($article, null, null, $sub);

        $this->assertEquals($sub->getMorphClass(), $access->source_type);
        $this->assertEquals($sub->id, $access->source_id);
    }

    public function test_grant_access_without_source_leaves_source_null(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Lifetime']);

        $access = $user->grantAccess($article);

        $this->assertNull($access->source_type);
        $this->assertNull($access->source_id);
    }

    public function test_grant_access_source_relationship_resolves(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'A']);
        $sub = Subscription::create(['name' => 'm']);

        $access = $user->grantAccess($article, null, null, $sub);

        $this->assertInstanceOf(Subscription::class, $access->source);
        $this->assertEquals($sub->id, $access->source->id);
    }

    // ─── multiple sources for the same accessible coexist ────────

    public function test_lifetime_and_subscription_grants_for_same_accessible_coexist(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Both']);
        $sub = Subscription::create(['name' => 'pro']);

        $lifetime = $user->grantAccess($article); // null source
        $subbed = $user->grantAccess($article, null, now()->addDays(30), $sub);

        $this->assertNotEquals($lifetime->id, $subbed->id);
        $this->assertEquals(2, $user->accesses()->count());
    }

    public function test_two_subscription_sources_for_same_accessible_create_two_rows(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Overlap']);
        $sub1 = Subscription::create(['name' => 's1']);
        $sub2 = Subscription::create(['name' => 's2']);

        $a1 = $user->grantAccess($article, null, now()->addDays(30), $sub1);
        $a2 = $user->grantAccess($article, null, now()->addDays(30), $sub2);

        $this->assertNotEquals($a1->id, $a2->id);
        $this->assertEquals(2, $user->accesses()->count());
    }

    // ─── renewal idempotency ─────────────────────────────────────

    public function test_renewing_same_source_updates_existing_row(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Renew']);
        $sub = Subscription::create(['name' => 'm']);

        $first = $user->grantAccess($article, null, now()->addDays(30), $sub);
        $second = $user->grantAccess($article, null, now()->addDays(60), $sub);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, $user->accesses()->count());
        $this->assertTrue($second->expires_at->greaterThan($first->expires_at->copy()->addDays(20)));
    }

    public function test_renewal_after_expiry_reactivates_grant(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Reactivate']);
        $sub = Subscription::create(['name' => 'm']);

        $user->grantAccess($article, null, Carbon::now()->subDay(), $sub);
        $this->assertFalse($user->hasAccess($article));

        $user->grantAccess($article, null, Carbon::now()->addDays(30), $sub);
        $this->assertTrue($user->hasAccess($article));
        $this->assertEquals(1, $user->accesses()->count());
    }

    // ─── source cascade cleanup ──────────────────────────────────

    public function test_deleting_source_revokes_subscription_grants_only(): void
    {
        $user = User::factory()->create();
        $articleA = Article::create(['title' => 'A']);
        $articleB = Article::create(['title' => 'B']);
        $sub = Subscription::create(['name' => 'm']);

        $user->grantAccess($articleA);                           // lifetime — survives
        $user->grantAccess($articleA, null, now()->addDays(30), $sub); // subbed
        $user->grantAccess($articleB, null, now()->addDays(30), $sub); // subbed

        $sub->delete();

        $this->assertTrue($user->hasAccess($articleA), 'lifetime grant must survive');
        $this->assertFalse($user->hasAccess($articleB), 'subscription-only grant must be revoked');
        $this->assertEquals(1, $user->accesses()->count());
    }

    public function test_cascade_does_not_touch_other_subscriptions(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'shared']);
        $subA = Subscription::create(['name' => 'a']);
        $subB = Subscription::create(['name' => 'b']);

        $user->grantAccess($article, null, now()->addDays(30), $subA);
        $user->grantAccess($article, null, now()->addDays(30), $subB);

        $subA->delete();

        $this->assertTrue($user->hasAccess($article));
        $this->assertEquals(1, $user->accesses()->count());
    }

    public function test_cascade_dispatches_event(): void
    {
        Event::fake([SourceAccessesRevoked::class]);

        $user = User::factory()->create();
        $article = Article::create(['title' => 'evt']);
        $sub = Subscription::create(['name' => 'm']);

        $user->grantAccess($article, null, now()->addDays(30), $sub);

        $sub->delete();

        Event::assertDispatched(
            SourceAccessesRevoked::class,
            fn(SourceAccessesRevoked $e) => $e->source->is($sub) && $e->count === 1,
        );
    }

    public function test_cascade_event_not_dispatched_when_no_rows_match(): void
    {
        Event::fake([SourceAccessesRevoked::class]);

        $sub = Subscription::create(['name' => 'unused']);
        $sub->delete();

        Event::assertNotDispatched(SourceAccessesRevoked::class);
    }

    // ─── static helper / observer / trait parity ─────────────────

    public function test_static_revoke_by_source_helper(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'S']);
        $sub = Subscription::create(['name' => 'm']);

        $user->grantAccess($article, null, now()->addDays(30), $sub);

        $deleted = Access::revokeBySource($sub);

        $this->assertEquals(1, $deleted);
        $this->assertFalse($user->hasAccess($article));
    }

    // ─── revokeAccess scoped by source ───────────────────────────

    public function test_revoke_access_with_source_filter_only_removes_that_source(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Targeted']);
        $sub = Subscription::create(['name' => 'm']);

        $user->grantAccess($article); // lifetime
        $user->grantAccess($article, null, now()->addDays(30), $sub);

        $user->revokeAccess($article, null, $sub);

        $this->assertTrue($user->hasAccess($article));
        $this->assertEquals(1, $user->accesses()->count());
    }

    public function test_revoke_access_without_source_filter_removes_all_for_accessible(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'AllOfIt']);
        $sub = Subscription::create(['name' => 'm']);

        $user->grantAccess($article);
        $user->grantAccess($article, null, now()->addDays(30), $sub);

        $deleted = $user->revokeAccess($article);

        $this->assertEquals(2, $deleted);
        $this->assertFalse($user->hasAccess($article));
    }

    public function test_revoke_access_by_source_method(): void
    {
        $user = User::factory()->create();
        $a1 = Article::create(['title' => 'X']);
        $a2 = Article::create(['title' => 'Y']);
        $sub = Subscription::create(['name' => 'm']);

        $user->grantAccess($a1);                                // lifetime — survives
        $user->grantAccess($a1, null, now()->addDays(30), $sub);
        $user->grantAccess($a2, null, now()->addDays(30), $sub);

        $deleted = $user->revokeAccessBySource($sub);

        $this->assertEquals(2, $deleted);
        $this->assertTrue($user->hasAccess($a1)); // via lifetime
        $this->assertFalse($user->hasAccess($a2));
    }

    // ─── revokeAllAccess source filter ───────────────────────────

    public function test_revoke_all_access_filtered_by_source(): void
    {
        $user = User::factory()->create();
        $a1 = Article::create(['title' => 'Filt1']);
        $a2 = Article::create(['title' => 'Filt2']);
        $sub = Subscription::create(['name' => 'm']);

        $user->grantAccess($a1);
        $user->grantAccess($a1, null, now()->addDays(30), $sub);
        $user->grantAccess($a2, null, now()->addDays(30), $sub);

        $deleted = $user->revokeAllAccess(Article::class, $sub);

        $this->assertEquals(2, $deleted);
        $this->assertTrue($user->hasAccess($a1)); // lifetime survives
        $this->assertFalse($user->hasAccess($a2));
    }

    // ─── syncAccess source scoping ───────────────────────────────

    public function test_sync_access_scoped_to_source_does_not_touch_lifetime(): void
    {
        $user = User::factory()->create();
        $a1 = Article::create(['title' => 'L1']);
        $a2 = Article::create(['title' => 'S1']);
        $a3 = Article::create(['title' => 'S2']);
        $sub = Subscription::create(['name' => 'm']);

        $user->grantAccess($a1);                                // lifetime
        $user->grantAccess($a2, null, now()->addDays(30), $sub); // initial sub grant

        // Subscription's bundle changes from [a2] to [a3]
        $user->syncAccess(Article::class, [$a3->id], null, now()->addDays(30), $sub);

        $this->assertTrue($user->hasAccess($a1), 'lifetime untouched');
        $this->assertFalse($user->hasAccess($a2), 'old sub grant removed');
        $this->assertTrue($user->hasAccess($a3), 'new sub grant added');
    }

    public function test_sync_access_without_source_only_touches_null_source_rows(): void
    {
        $user = User::factory()->create();
        $a1 = Article::create(['title' => 'M1']);
        $a2 = Article::create(['title' => 'M2']);
        $sub = Subscription::create(['name' => 'm']);

        $user->grantAccess($a1);                                // null source
        $user->grantAccess($a2, null, now()->addDays(30), $sub); // sub source

        $user->syncAccess(Article::class, []); // wipe null-source grants

        $this->assertFalse($user->hasAccess($a1));
        $this->assertTrue($user->hasAccess($a2), 'sub-source grant must be untouched');
    }

    // ─── scope helpers on Access ─────────────────────────────────

    public function test_from_source_scope_filters_correctly(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Scope']);
        $sub = Subscription::create(['name' => 'm']);

        $user->grantAccess($article);
        $user->grantAccess($article, null, now()->addDays(30), $sub);

        $rows = Access::query()->fromSource($sub)->get();

        $this->assertCount(1, $rows);
        $this->assertEquals($sub->id, $rows->first()->source_id);
    }
}
