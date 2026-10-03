<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public static function limitedRoutes(): array
    {
        return [
            ['POST', '/api/auth/otp/request', 3, false],
            ['POST', '/api/auth/otp/verify', 10, false],
            ['GET', '/api/auth/microsoft/redirect', 30, false],
            ['GET', '/api/auth/microsoft/callback', 30, false],
            ['POST', '/api/auth/dev/login', 30, false],
            ['POST', '/api/join-requests', 10, true],
            ['POST', '/api/classes/1/ai/generate', 20, true],
        ];
    }

    #[DataProvider('limitedRoutes')]
    public function test_real_middleware_limits_requests(string $method, string $uri, int $limit, bool $authenticated): void
    {
        Cache::flush();
        if ($authenticated) {
            [$class, $teacher] = $this->classWithMember();
            $this->actingAs(str_contains($uri, '/ai/') ? $teacher : $this->student());
            $uri = str_replace('/classes/1/', "/classes/{$class->id}/", $uri);
        }
        for ($i = 0; $i < $limit; $i++) {
            $this->assertNotSame(429, $this->json($method, $uri, [])->status());
        }
        $this->json($method, $uri, [])->assertTooManyRequests()->assertHeader('Retry-After');
    }
}
