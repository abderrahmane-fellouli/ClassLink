<?php

namespace Tests\Unit;

use App\Models\AiProvider;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiFailoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_fails_over_directly_and_caches_success(): void
    {
        foreach (['first', 'second'] as $priority => $name) {
            config(["services.ai.$name.key" => 'test', "services.ai.$name.base_url" => "https://ai.test/$name", "services.ai.$name.model" => 'test']);
            AiProvider::create(['name' => $name, 'priority' => $priority, 'enabled' => true, 'daily_limit' => 10, 'used_today' => 0]);
        }
        Http::fake([
            'https://ai.test/first/*' => Http::response([], 429),
            'https://ai.test/second/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'title' => 'Test', 'questions' => [['statement' => 'Test?', 'type' => 'single', 'options' => [
                    ['label' => 'Yes', 'is_correct' => true], ['label' => 'No', 'is_correct' => false],
                ]]],
            ])]]]], 200),
        ]);
        $service = app(AiService::class);
        $this->assertSame('second', $service->generate('Course', 'unit-hash')['provider']);
        $this->assertTrue($service->generate('Course', 'unit-hash')['cached']);
        Http::assertSentCount(2);
    }
}
