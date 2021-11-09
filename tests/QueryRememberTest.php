<?php

namespace Tests;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Eusonlito\QueryRemember\QueryRemember;
use Eusonlito\QueryRemember\ServiceProvider;
use RuntimeException;

class QueryRememberTest extends TestCase
{
    use WithLaravelMigrations;
    use WithFaker;
    use DatabaseMigrations;

    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    protected function setUp() : void
    {
        $this->afterApplicationCreated(function () {
            $this->loadLaravelMigrations();

            for ($i = 0; $i < 10; ++$i) {
                User::make()->forceFill([
                    'email' => $this->faker->freeEmail,
                    'name' => $this->faker->name,
                    'password' => 'password',
                    'email_verified_at' => today(),
                ])->save();
            }
        });

        parent::setUp();
    }

    public function test_macro_returns_rememberable_query_instance(): void
    {
        $this->cache()->flush();

        static::assertInstanceOf(QueryRemember::class, DB::table('users')->remember(60));
        static::assertInstanceOf(QueryRemember::class, User::remember(60));

        static::assertInstanceOf(
            QueryRemember::class,
            DB::table('users')->where('password')->remember(60)
        );
        static::assertInstanceOf(
            QueryRemember::class,
            User::where('password', null)->remember(60)
        );
    }

    public function test_eloquent_builder_cached(): void
    {
        $this->cache()->flush();

        $id = User::inRandomOrder()->remember(60)->value('id');
        User::destroy($id);
        static::assertEquals($id, User::inRandomOrder()->remember(60)->value('id'));

        $id = User::inRandomOrder()->remember(30, 'customKey')->value('id');
        User::destroy($id);
        static::assertEquals($id, User::inRandomOrder()->remember(30, 'customKey')->value('id'));

        $id = User::inRandomOrder()->remember(30, 'differentKey')->value('id');
        User::destroy($id);
        static::assertNotEquals($id, User::inRandomOrder()->remember(30, 'customKey')->value('id'));
    }

    public function test_query_builder_cached(): void
    {
        $this->cache()->flush();

        $id = DB::table('users')->inRandomOrder()->remember(60)->value('id');
        DB::table('users')->delete($id);
        static::assertEquals($id, DB::table('users')->inRandomOrder()->remember(60)->value('id'));

        $id = DB::table('users')->inRandomOrder()->remember(30, 'customKey')->value('id');
        DB::table('users')->delete($id);
        static::assertEquals($id, DB::table('users')->inRandomOrder()->remember(30, 'customKey')->value('id'));

        $id = DB::table('users')->inRandomOrder()->remember(30, 'differentKey')->value('id');
        DB::table('users')->delete($id);
        static::assertNotEquals($id, DB::table('users')->inRandomOrder()->remember(30, 'customKey')->value('id'));
    }

    public function test_query_returns_saved_null_result(): void
    {
        $this->cache()->flush();

        $id = DB::table('users')->where('name', 'not-exist')->remember(60)->first();

        User::make()->forceFill([
            'name' => 'not-exists',
            'email' => $this->faker->freeEmail,
            'password' => 'password',
            'email_verified_at' => today(),
        ])->save();

        static::assertNull($result = DB::table('users')->where('name', 'not-exist')->remember(60)->first());
        static::assertEquals($result, $id);
    }

    public function test_query_returns_raw_statement(): void
    {
        $this->cache()->flush();

        DB::table('users')->where(DB::raw("'name' <> NULL"))->remember(60)->first();

        DB::table('users')->truncate();

        static::assertNotNull(DB::table('users')->where(DB::raw("'name' <> NULL"))->remember(60)->first());
    }

    public function test_forces_developer_to_set_as_before_last_method(): void
    {
        $this->cache()->flush();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The `remember()` method call is not before query execution: [inRandomOrder] called.');

        DB::table('users')->remember(60, 'test')->inRandomOrder()->first();
    }

    public function test_idempotent_operations(): void
    {
        $this->cache()->flush();

        DB::table('users')->remember(60, 'test')->update(['name' => 'foo']);

        $this->assertDatabaseHas('users', ['name' => 'foo']);

        DB::table('users')->remember(60, 'test')->update(['name' => 'bar']);

        $this->assertDatabaseHas('users', ['name' => 'foo']);

        $this->cache()->delete('test');

        DB::table('users')->remember(60, 'test')->update(['name' => 'bar']);

        $this->assertDatabaseMissing('users', ['name' => 'foo']);
        $this->assertDatabaseHas('users', ['name' => 'bar']);
    }

    public function test_first_acquires_lock_and_returns_result(): void
    {
        $this->cache()->flush();

        DB::table('users')->remember(key: 'foo', wait: 1)->get();

        static::assertNotNull($this->cache()->get('foo'));
    }

    public function test_second_waits_for_lock_to_retrieve_from_cache(): void
    {
        $this->cache()->flush();

        $this->cache()->lock('foo', 1)->get();
        $this->cache()->put('foo', $user = DB::table('users')->where('id', 1)->first());

        DB::table('users')->where('id', 1)->delete();

        static::assertEquals($user, DB::table('users')->where('id', 1)->remember(key: 'foo', wait: 1)->first());
    }

    public function test_second_exception_on_timeout(): void
    {
        $this->cache()->flush();

        $this->expectException(LockTimeoutException::class);

        $this->cache()->lock('foo', 2)->get();

        DB::table('users')->where('id', 1)->remember(key: 'foo', wait: 1)->first();
    }

    protected function cache(): Repository
    {
        return Cache::tags(['database', 'database|users']);
    }
}
