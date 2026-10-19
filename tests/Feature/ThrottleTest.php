<?php

namespace Tests\Feature;

use App\Http\Middleware\ObservableThrottleRequests;
use App\Models\Station;
use App\Models\Store;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * ObservableThrottleRequests and the limits in AppServiceProvider.
 *
 * The real limits are far too big to walk past in a test, so most cases swap
 * in a tiny limiter under the same name; the last few check the real numbers.
 * Ported from ToolboxPizza.
 */
class ThrottleTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    private string $probe;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();

        Store::create(['id' => 7, 'store_number' => '1001', 'station_password' => 'open-sesame']);
        $this->station = Station::create([
            'store_id' => 7,
            'name' => 'Make line',
            'room_name' => 'make-line-1001',
            'type' => Station::TYPE_STANDARD,
        ]);

        $this->probe = "/api/1001/stations/{$this->station->id}/media";
    }

    private function tinyApiLimiter(int $real, int $target = 100): void
    {
        RateLimiter::for('api', fn (Request $request) => [
            Limit::perMinute($real)->by('t:'.$request->bearerToken()),
            Limit::perMinute($target)->by('target:t:'.$request->bearerToken()),
        ]);
    }

    public function test_passing_a_target_logs_once_and_blocks_nothing(): void
    {
        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();
        $this->tinyApiLimiter(real: 100, target: 2);

        foreach (range(1, 5) as $_) {
            $this->getJson($this->probe, $this->headers())->assertOk();
        }

        Log::shouldHaveReceived('warning')
            ->with('throttle.usage', Mockery::on(fn ($ctx) => $ctx['limiter'] === 'api' && $ctx['max'] === 2))
            ->once();
    }

    public function test_record_only_mode_lets_a_breach_through_and_logs_it_once(): void
    {
        config(['app.throttle_enforce' => false]);
        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();
        $this->tinyApiLimiter(real: 2);

        $this->getJson($this->probe, $this->headers())->assertOk();
        $this->getJson($this->probe, $this->headers())->assertOk();

        foreach (range(1, 3) as $_) {
            $this->getJson($this->probe, $this->headers())
                ->assertOk()
                ->assertHeader('X-RateLimit-Remaining', '0');
        }

        Log::shouldHaveReceived('warning')
            ->with('throttle.breach', Mockery::on(fn ($ctx) => $ctx['enforced'] === false))
            ->once();
    }

    public function test_enforce_mode_answers_429_with_retry_after(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 2);

        $this->getJson($this->probe, $this->headers())->assertOk();
        $this->getJson($this->probe, $this->headers())->assertOk();

        $this->getJson($this->probe, $this->headers())
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_a_throttled_request_never_reaches_pizzasys(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);

        $this->getJson($this->probe, $this->headers())->assertOk();
        $sent = count(Http::recorded());

        $this->getJson($this->probe, $this->headers())->assertStatus(429);

        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_route_tier_also_runs_before_pizzasys(): void
    {
        config(['app.throttle_enforce' => true]);
        RateLimiter::for('api-poll', fn (Request $request) => Limit::perMinute(1)->by('t:'.$request->bearerToken()));

        $flush = fn () => $this->postJson('/api/1001/usage/segments', ['segments' => []], $this->headers());

        $this->assertNotSame(429, $flush()->status());
        $sent = count(Http::recorded());

        $flush()->assertStatus(429);

        $this->assertCount($sent, Http::recorded());
    }

    public function test_two_tokens_do_not_share_a_bucket(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);

        $this->getJson($this->probe, $this->headers())->assertOk();
        $this->getJson($this->probe, $this->headers())->assertStatus(429);

        $this->getJson($this->probe, ['Authorization' => 'Bearer 2|other-token', 'Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_an_unreachable_limiter_store_serves_the_request_unthrottled(): void
    {
        config(['app.throttle_enforce' => true]);

        $down = new class extends ArrayStore
        {
            public function get($key)
            {
                throw new \RuntimeException('redis down');
            }

            public function increment($key, $value = 1)
            {
                throw new \RuntimeException('redis down');
            }

            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('redis down');
            }
        };

        $limiter = new CacheRateLimiter(new Repository($down));
        $limiter->for('api', fn (Request $request) => Limit::perMinute(1)->by('t:'.$request->bearerToken()));
        $this->app->instance(CacheRateLimiter::class, $limiter);

        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();

        $this->getJson($this->probe, $this->headers())->assertOk();
        $this->getJson($this->probe, $this->headers())->assertOk();

        Log::shouldHaveReceived('error')
            ->with('throttle.store_unavailable', Mockery::on(fn ($ctx) => $ctx['limiter'] === 'api'))
            ->twice();
    }

    public function test_the_real_api_limits_split_reads_from_writes(): void
    {
        $limits = fn (string $method) => collect(RateLimiter::limiter('api')(
            Request::create($this->probe, $method, server: ['HTTP_AUTHORIZATION' => 'Bearer 1|test-token'])
        ));

        $real = fn ($all) => $all->reject(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();
        $target = fn ($all) => $all->filter(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();

        $this->assertSame([600, 5000], $real($limits('GET')));
        $this->assertSame([120, 1200], $target($limits('GET')));
        $this->assertSame([300, 5000], $real($limits('POST')));
        $this->assertSame([60, 1200], $target($limits('POST')));
    }

    public function test_keys_hash_the_token_and_fall_back_to_the_ip(): void
    {
        $key = fn (array $server) => RateLimiter::limiter('api-poll')(Request::create('/x', 'POST', server: $server))[0]->key;

        $a = $key(['HTTP_AUTHORIZATION' => 'Bearer 1|aaa']);

        $this->assertSame($a, $key(['HTTP_AUTHORIZATION' => 'Bearer 1|aaa']));
        $this->assertNotSame($a, $key(['HTTP_AUTHORIZATION' => 'Bearer 1|bbb']));
        $this->assertStringNotContainsString('aaa', $a);
        $this->assertSame('ip:10.1.2.3', $key(['REMOTE_ADDR' => '10.1.2.3']));
    }

    /**
     * The limits a limiter returns for a real route, so {storeId} and
     * {station} resolve - as raw strings, the way the throttle sees them.
     */
    private function limitsFor(string $limiter, string $method, string $uri, array $server = [])
    {
        $request = Request::create($uri, $method, server: $server);
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);

        return collect(RateLimiter::limiter($limiter)($request));
    }

    private function real($limits): array
    {
        return $limits->reject(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();
    }

    private function target($limits): array
    {
        return $limits->filter(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();
    }

    public function test_the_station_token_limits_key_on_ip_store_and_station(): void
    {
        $limits = $this->limitsFor('station-token', 'POST', '/api/1001/tokens/stations/5', ['REMOTE_ADDR' => '10.1.2.3']);

        $this->assertSame([10, 60], $this->real($limits));
        $this->assertSame([5, 20], $this->target($limits));
        $this->assertStringEndsWith('10.1.2.3|1001|5', $limits[0]->key);
        $this->assertCount(4, $limits->pluck('key')->unique());

        $other = $this->limitsFor('station-token', 'POST', '/api/1001/tokens/stations/6', ['REMOTE_ADDR' => '10.1.2.3']);
        $this->assertNotSame($limits[0]->key, $other[0]->key);
    }

    public function test_guessing_a_station_password_is_refused_after_ten_and_never_asks_pizzasys(): void
    {
        config(['app.throttle_enforce' => true]);
        $other = Station::create(['store_id' => 7, 'name' => 'Oven', 'room_name' => 'oven-1001', 'type' => Station::TYPE_STANDARD]);

        $guess = fn (Station $station) => $this->postJson("/api/1001/tokens/stations/{$station->id}", ['password' => 'guess']);

        foreach (range(1, 10) as $_) {
            $guess($this->station)->assertForbidden();
        }

        $guess($this->station)->assertStatus(429)->assertHeader('Retry-After');
        $guess($other)->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_public_station_list_is_limited_per_ip(): void
    {
        $limits = $this->limitsFor('api-public', 'GET', '/api/1001/stations', ['REMOTE_ADDR' => '10.1.2.3']);

        $this->assertSame([60], $this->real($limits));
        $this->assertSame([20, 100], $this->target($limits));
        $this->assertSame('ip:10.1.2.3', $limits[0]->key);

        config(['app.throttle_enforce' => true]);

        foreach (range(1, 60) as $_) {
            $this->getJson('/api/1001/stations')->assertOk();
        }

        $this->getJson('/api/1001/stations')->assertStatus(429);
    }

    public function test_the_upload_limits_carry_the_plan_numbers(): void
    {
        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer 1|aaa'];
        $chunk = $this->limitsFor('api-upload-chunk', 'POST', '/api/1001/stations/5/media/uploads/u1/chunks/0', $bearer);
        $upload = $this->limitsFor('api-upload', 'POST', '/api/1001/stations/5/media/uploads', $bearer);

        $this->assertSame([2000, 5000], $this->real($chunk));
        $this->assertSame([600], $this->target($chunk));
        $this->assertSame([60], $this->real($upload));
        $this->assertSame([20], $this->target($upload));
    }

    public function test_the_heartbeat_and_chunks_are_not_capped_by_the_general_limit(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);

        $chunk = "/api/1001/stations/{$this->station->id}/media/uploads/u1/chunks/0";

        foreach (range(1, 3) as $_) {
            $this->assertNotSame(429, $this->postJson('/api/1001/usage/segments', ['segments' => []], $this->headers())->status());
            $this->assertNotSame(429, $this->postJson($chunk, [], $this->headers())->status());
        }

        $this->getJson($this->probe, $this->headers())->assertOk();
        $this->getJson($this->probe, $this->headers())->assertStatus(429);
    }

    public function test_the_poll_and_chunk_ip_ceilings_are_keyed_apart_from_a_tokenless_caller(): void
    {
        foreach (['api-poll' => 1200, 'api-upload-chunk' => 2000] as $limiter => $perToken) {
            $keys = collect(RateLimiter::limiter($limiter)(Request::create('/x', 'POST', server: ['REMOTE_ADDR' => '10.1.2.3'])))
                ->reject(fn (Limit $l) => str_starts_with($l->key, 'target:'));

            $this->assertSame([$perToken, 5000], $keys->pluck('maxAttempts')->all());
            $this->assertSame(['ip:10.1.2.3', 'ceiling:ip:10.1.2.3'], $keys->pluck('key')->all());
        }
    }

    public function test_each_tier_is_on_the_routes_the_plan_puts_it_on(): void
    {
        $routesWith = fn (string $middleware) => collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array($middleware, $route->gatherMiddleware(), true))
            ->map(fn ($route) => ($route->methods()[0]).' '.$route->uri())
            ->sort()->values()->all();

        $this->assertSame(['POST api/{storeId}/tokens/stations/{station}'], $routesWith('throttle:station-token'));
        $this->assertSame(['GET api/{storeId}/stations'], $routesWith('throttle:api-public'));
        $this->assertSame(
            ['POST api/{storeId}/stations/{station}/media/uploads/{upload}/chunks/{chunkIndex}'],
            $routesWith('throttle:api-upload-chunk'),
        );
        $this->assertSame([
            'POST api/{storeId}/stations/{station}/media/uploads',
            'POST api/{storeId}/stations/{station}/media/uploads/complete-bulk',
            'POST api/{storeId}/stations/{station}/media/uploads/{upload}/complete',
        ], $routesWith('throttle:api-upload'));
        $this->assertSame(['POST api/{storeId}/usage/segments'], $routesWith('throttle:api-poll'));

        // The heartbeat and chunks swap the general limit for their own tier.
        $general = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array(ObservableThrottleRequests::class.':api', app('router')->gatherRouteMiddleware($route), true))
            ->map(fn ($route) => ($route->methods()[0]).' '.$route->uri())
            ->sort()->values()->all();

        $this->assertSame(
            array_values(array_diff($routesWith('auth.token.store'), $routesWith('throttle:api-poll'), $routesWith('throttle:api-upload-chunk'))),
            $general,
        );
    }
}
