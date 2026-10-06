<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Most tests use array sessions; explicitly exercise Render's driver.
        config()->set('session.driver', 'database');
        config()->set('session.connection', config('database.default'));
        config()->set('session.table', 'sessions');
        config()->set('session.lottery', [0, 100]);
        $this->app['session']->forgetDrivers();
    }

    public function test_database_handler_can_read_write_update_and_destroy_guest_sessions(): void
    {
        $handler = $this->app['session']->driver()->getHandler();
        $this->assertInstanceOf(DatabaseSessionHandler::class, $handler);
        $this->assertSame('', $handler->read('database-session-probe'));
        $this->assertTrue($handler->write('database-session-probe', 'first payload'));
        $this->assertSame('first payload', $handler->read('database-session-probe'));
        $this->assertDatabaseHas('sessions', [
            'id' => 'database-session-probe',
            'user_id' => null,
            'payload' => base64_encode('first payload'),
        ]);

        $this->assertTrue($handler->write('database-session-probe', 'updated payload'));
        $this->assertSame('updated payload', $handler->read('database-session-probe'));
        $this->assertDatabaseCount('sessions', 1);
        $this->assertTrue($handler->destroy('database-session-probe'));
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_expired_sessions_can_be_garbage_collected(): void
    {
        $handler = $this->app['session']->driver()->getHandler();
        $handler->write('expired-session', 'expired payload');
        DB::table('sessions')->where('id', 'expired-session')->update([
            'last_activity' => now()->subMinutes((int) config('session.lifetime') + 1)->getTimestamp(),
        ]);
        $this->assertSame('', $handler->read('expired-session'));
        $this->assertSame(1, $handler->gc((int) config('session.lifetime') * 60));
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_web_middleware_persists_a_database_session_across_requests(): void
    {
        Route::middleware('web')->get('/_test/database-session', function (Request $request) {
            $visits = $request->session()->get('visits', 0) + 1;
            $request->session()->put('visits', $visits);

            return response()->json(['visits' => $visits]);
        });

        $response = $this->getJson('/_test/database-session')->assertOk()->assertJsonPath('visits', 1);
        $this->assertDatabaseCount('sessions', 1);
        $cookie = $response->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie);

        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->getJson('/_test/database-session')->assertOk()->assertJsonPath('visits', 2);
        $this->assertDatabaseCount('sessions', 1);
    }

    public function test_stateful_origin_requests_use_database_sessions_without_missing_table_errors(): void
    {
        config()->set('sanctum.stateful', ['session-test.example']);
        $this->withHeader('Origin', 'https://session-test.example')
            ->getJson('/api/me')->assertUnauthorized();
        $this->assertDatabaseCount('sessions', 1);

        $user = $this->teacher();
        $this->asToken($this->tokenFor($user))
            ->getJson('/api/me')->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_additive_migration_preserves_existing_users_and_bearer_tokens(): void
    {
        $user = $this->teacher();
        $this->tokenFor($user);
        $usersBefore = DB::table('users')->orderBy('id')->get()->toArray();
        $tokensBefore = DB::table('personal_access_tokens')->orderBy('id')->get()->toArray();

        // Reproduce the already-deployed schema without sessions, in the test DB only.
        $migration = require database_path('migrations/2026_10_07_000002_create_sessions_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('sessions'));
        $migration->up();

        $this->assertTrue(Schema::hasColumns('sessions', [
            'id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity',
        ]));
        $this->assertEquals($usersBefore, DB::table('users')->orderBy('id')->get()->toArray());
        $this->assertEquals($tokensBefore, DB::table('personal_access_tokens')->orderBy('id')->get()->toArray());
        $this->assertSame($user->email, User::findOrFail($user->id)->email);
        $this->assertDatabaseCount('sessions', 0);
    }
}
