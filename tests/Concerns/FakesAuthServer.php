<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Stands in for pizzasys. This service has no local login: every request goes
 * through AuthTokenStoreScopeMiddleware, which POSTs the caller's token to the
 * auth server. Faking that one call exercises the real middleware chain rather
 * than bypassing it. Ported, in minimal form, from ToolboxPizza; this service
 * keeps no users table of its own, so there is no user row to create.
 */
trait FakesAuthServer
{
    /**
     * Registered ONCE per test. Http::fake() MERGES stubs and the first match
     * wins, so re-faking mid-test would silently keep the original response.
     * preventStrayRequests() means any unmatched request fails loudly rather
     * than escaping to the network.
     */
    protected function fakeAuthServer(): void
    {
        config([
            'services.auth_server.base_url' => 'http://auth.test',
            'services.auth_server.verify_path' => '/api/v1/auth/token-verify',
            'services.auth_server.service_name' => 'Screens',
            'services.auth_server.call_token' => 'service-token',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'auth.test/*' => Http::response([
                'active' => true,
                'subject_type' => 'user',
                'user' => ['id' => 42, 'name' => 'Maria Manager'],
                'roles' => [],
                'permissions' => [],
                'ext' => ['authorized' => true],
            ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['Authorization' => 'Bearer 1|test-token', 'Accept' => 'application/json'];
    }
}
