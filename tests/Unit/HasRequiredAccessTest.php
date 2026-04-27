<?php

namespace Blax\Roles\Tests\Unit;

use Blax\Roles\Models\Permission;
use Blax\Roles\Models\RequiredAccess;
use Blax\Roles\Models\Role;
use Blax\Roles\RolesServiceProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;

class HasRequiredAccessTest extends TestCase
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
        $app['config']->set('roles.run_migrations', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../workbench/database/migrations');
    }

    // ─── relations / mutations ─────────────────────────────────────────

    public function test_required_access_links_returns_empty_by_default(): void
    {
        $article = Article::create(['title' => 'Holder']);
        $this->assertCount(0, $article->requiredAccessLinks);
    }

    public function test_add_required_access_creates_pivot_row(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $target = Article::create(['title' => 'Target']);

        $link = $holder->addRequiredAccess($target);

        $this->assertInstanceOf(RequiredAccess::class, $link);
        $this->assertSame($holder->getMorphClass(), $link->holder_type);
        $this->assertEquals($holder->id, $link->holder_id);
        $this->assertSame($target->getMorphClass(), $link->required_type);
        $this->assertEquals($target->id, $link->required_id);
    }

    public function test_add_required_access_is_idempotent(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $target = Article::create(['title' => 'Target']);

        $first = $holder->addRequiredAccess($target);
        $second = $holder->addRequiredAccess($target);

        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $holder->requiredAccessLinks()->get());
    }

    public function test_remove_required_access_deletes_pivot_row(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $target = Article::create(['title' => 'Target']);

        $holder->addRequiredAccess($target);
        $deleted = $holder->removeRequiredAccess($target);

        $this->assertSame(1, $deleted);
        $this->assertCount(0, $holder->requiredAccessLinks()->get());
    }

    public function test_remove_required_access_returns_zero_when_not_linked(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $other = Article::create(['title' => 'Other']);

        $this->assertSame(0, $holder->removeRequiredAccess($other));
    }

    public function test_sync_required_access_replaces_existing_set(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $a = Article::create(['title' => 'A']);
        $b = Article::create(['title' => 'B']);
        $c = Article::create(['title' => 'C']);

        $holder->addRequiredAccess($a);
        $holder->addRequiredAccess($b);

        $holder->syncRequiredAccess([$b, $c]);

        $links = $holder->requiredAccessLinks()->get();
        // Morph columns store IDs as strings (uuidMorphs); compare as strings.
        $ids = $links->pluck('required_id')->map(fn($id) => (string) $id)->all();
        $expected = [(string) $b->id, (string) $c->id];

        $this->assertEqualsCanonicalizing($expected, $ids);
        $this->assertCount(2, $links);
    }

    public function test_sync_required_access_with_empty_clears_all(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $a = Article::create(['title' => 'A']);
        $holder->addRequiredAccess($a);

        $holder->syncRequiredAccess([]);

        $this->assertCount(0, $holder->requiredAccessLinks()->get());
    }

    public function test_required_access_targets_resolves_polymorphic_models(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $a = Article::create(['title' => 'A']);
        $b = Article::create(['title' => 'B']);

        $holder->addRequiredAccess($a);
        $holder->addRequiredAccess($b);

        $targets = $holder->requiredAccessTargets();

        $this->assertCount(2, $targets);
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $targets->pluck('id')->all(),
        );
    }

    // ─── hasUnlockedRequiredAccessFor — the access check ─────────────

    public function test_returns_false_when_entity_is_null(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $target = Article::create(['title' => 'Target']);
        $holder->addRequiredAccess($target);

        $this->assertFalse($holder->hasUnlockedRequiredAccessFor(null));
    }

    public function test_returns_false_when_no_required_access_links_exist(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $user = User::factory()->create();

        $this->assertFalse($holder->hasUnlockedRequiredAccessFor($user));
    }

    public function test_returns_false_when_user_has_no_access_to_any_target(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $target = Article::create(['title' => 'Target']);
        $holder->addRequiredAccess($target);

        $user = User::factory()->create();

        $this->assertFalse($holder->hasUnlockedRequiredAccessFor($user));
    }

    public function test_returns_true_when_user_has_direct_access_to_target(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $target = Article::create(['title' => 'Target']);
        $holder->addRequiredAccess($target);

        $user = User::factory()->create();
        $user->grantAccess($target);

        $this->assertTrue($holder->hasUnlockedRequiredAccessFor($user));
    }

    public function test_returns_true_when_user_has_role_based_access_to_target(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $target = Article::create(['title' => 'Target']);
        $holder->addRequiredAccess($target);

        $role = Role::create(['slug' => 'premium']);
        $role->grantAccess($target); // role -> target

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertTrue($holder->hasUnlockedRequiredAccessFor($user));
    }

    public function test_returns_true_when_user_has_permission_based_access_to_target(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $target = Article::create(['title' => 'Target']);
        $holder->addRequiredAccess($target);

        $permission = Permission::create(['slug' => 'view-target']);
        $permission->grantAccess($target); // permission -> target

        $user = User::factory()->create();
        $user->assignPermission($permission);

        $this->assertTrue($holder->hasUnlockedRequiredAccessFor($user));
    }

    public function test_returns_true_when_only_one_of_multiple_targets_is_unlocked(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $a = Article::create(['title' => 'A']);
        $b = Article::create(['title' => 'B']);
        $c = Article::create(['title' => 'C']);
        $holder->syncRequiredAccess([$a, $b, $c]);

        $user = User::factory()->create();
        $user->grantAccess($b); // only b granted

        $this->assertTrue($holder->hasUnlockedRequiredAccessFor($user));
    }

    public function test_returns_false_when_access_to_target_is_expired(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $target = Article::create(['title' => 'Target']);
        $holder->addRequiredAccess($target);

        $user = User::factory()->create();
        $user->grantAccess($target, expiresAt: Carbon::now()->subDay());

        $this->assertFalse($holder->hasUnlockedRequiredAccessFor($user));
    }

    public function test_returns_true_when_access_expires_in_the_future(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $target = Article::create(['title' => 'Target']);
        $holder->addRequiredAccess($target);

        $user = User::factory()->create();
        $user->grantAccess($target, expiresAt: Carbon::now()->addDay());

        $this->assertTrue($holder->hasUnlockedRequiredAccessFor($user));
    }

    public function test_does_not_unlock_holder_via_unrelated_access_grants(): void
    {
        $holder = Article::create(['title' => 'Holder']);
        $required = Article::create(['title' => 'Required']);
        $unrelated = Article::create(['title' => 'Unrelated']);
        $holder->addRequiredAccess($required);

        $user = User::factory()->create();
        $user->grantAccess($unrelated); // not the required one

        $this->assertFalse($holder->hasUnlockedRequiredAccessFor($user));
    }

    public function test_other_holders_are_not_unlocked_by_a_user_with_one_unlock(): void
    {
        $holderA = Article::create(['title' => 'Holder A']);
        $holderB = Article::create(['title' => 'Holder B']);
        $targetA = Article::create(['title' => 'Target A']);
        $targetB = Article::create(['title' => 'Target B']);

        $holderA->addRequiredAccess($targetA);
        $holderB->addRequiredAccess($targetB);

        $user = User::factory()->create();
        $user->grantAccess($targetA);

        $this->assertTrue($holderA->hasUnlockedRequiredAccessFor($user));
        $this->assertFalse($holderB->hasUnlockedRequiredAccessFor($user));
    }

    // ─── performance ─────────────────────────────────────────────────

    /**
     * The whole point of the SQL fast-path is that adding more
     * required-access targets does not multiply the work. Resolving the
     * user's role/permission space has a fixed prelude cost (a few
     * lookups), and the lock decision itself is one EXISTS query — that
     * shape must stay constant regardless of target count.
     */
    public function test_unlock_check_query_count_does_not_scale_with_target_count(): void
    {
        $role = Role::create(['slug' => 'premium']);

        $smallHolder = Article::create(['title' => 'Small']);
        $smallTargets = collect(range(1, 3))->map(fn($i) => Article::create(['title' => "S{$i}"]));
        $smallHolder->syncRequiredAccess($smallTargets);
        $role->grantAccess($smallTargets->first());

        $largeHolder = Article::create(['title' => 'Large']);
        $largeTargets = collect(range(1, 50))->map(fn($i) => Article::create(['title' => "L{$i}"]));
        $largeHolder->syncRequiredAccess($largeTargets);
        $role->grantAccess($largeTargets->first());

        $user = User::factory()->create();
        $user->assignRole($role);

        $countQueries = function ($holder) use ($user) {
            $holder = $holder->fresh();
            $u = $user->fresh();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $unlocked = $holder->hasUnlockedRequiredAccessFor($u);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertTrue($unlocked, 'expected ' . $holder->title . ' unlocked');
            return count($queries);
        };

        $smallCount = $countQueries($smallHolder);
        $largeCount = $countQueries($largeHolder);

        $this->assertSame(
            $smallCount,
            $largeCount,
            "Query count grew from {$smallCount} (3 targets) to {$largeCount} (50 targets) "
            . '— hasUnlockedRequiredAccessFor must stay O(1) in target count.',
        );

        // Sanity bound: prelude + EXISTS check is small and fixed.
        $this->assertLessThanOrEqual(
            6,
            $largeCount,
            'unexpectedly many queries (' . $largeCount . ') for unlock check',
        );
    }
}
