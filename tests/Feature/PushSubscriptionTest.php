<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function payloadSubscribe(string $endpoint = 'https://example.com/push/abc'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => 'p256dh_dummy',
                'auth' => 'auth_dummy',
            ],
            'contentEncoding' => 'aesgcm',
        ];
    }

    private function forceAppEnv(string $env): void
    {
        putenv("APP_ENV={$env}");
        $this->app->detectEnvironment(fn () => $env);
        config()->set('app.env', $env);
    }

    public function test_subscribe_requires_auth_returns_401_json(): void
    {
        $res = $this->postJson('/api/v1/push/subscribe', $this->payloadSubscribe());

        $res->assertStatus(401);
        $this->assertStringContainsString('application/json', (string) $res->headers->get('content-type'));
    }

    public function test_subscribe_validates_payload(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://example.com/push/x',
        ]);

        $res->assertStatus(422);
        $res->assertJsonStructure(['message', 'errors']);
    }

    public function test_subscribe_creates_or_updates_subscription(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $endpoint = 'https://example.com/push/same';
        $payload = $this->payloadSubscribe($endpoint);

        $res1 = $this->postJson('/api/v1/push/subscribe', $payload);
        $res1->assertStatus(200)->assertJsonStructure(['ok', 'id']);

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $user->id,
            'endpoint' => $endpoint,
        ]);
        $this->assertDatabaseCount('push_subscriptions', 1);

        $res2 = $this->postJson('/api/v1/push/subscribe', $payload);
        $res2->assertStatus(200);

        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_unsubscribe_requires_auth_returns_401_json(): void
    {
        $res = $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => 'https://example.com/push/x']);

        $res->assertStatus(401);
        $this->assertStringContainsString('application/json', (string) $res->headers->get('content-type'));
    }

    public function test_unsubscribe_deletes_row_for_current_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $endpoint = 'https://example.com/push/to-delete';
        $hashUpper = strtoupper(hash('sha256', $endpoint));

        PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => $hashUpper,
            'p256dh' => 'p',
            'auth' => 'a',
            'content_encoding' => 'aesgcm',
        ]);

        $res = $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => $endpoint]);

        $res->assertStatus(200)->assertJson([
            'ok' => true,
            'deleted' => 1,
        ]);

        $this->assertDatabaseMissing('push_subscriptions', [
            'user_id' => $user->id,
            'endpoint_hash' => $hashUpper,
        ]);
    }

    public function test_test_endpoint_is_disabled_in_production(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->forceAppEnv('production');

        $res = $this->postJson('/api/v1/push/test', [
            'title' => 't',
            'body' => 'b',
            'url' => '/',
        ]);

        $res->assertStatus(403);
    }

    public function test_test_endpoint_returns_404_when_no_subscriptions(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->forceAppEnv('local');

        $res = $this->postJson('/api/v1/push/test', [
            'title' => 't',
            'body' => 'b',
            'url' => '/',
        ]);

        $res->assertStatus(404);
    }

    public function test_test_endpoint_succeeds_without_real_network_by_mocking_webpush(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->forceAppEnv('local');

        $endpoint = 'https://example.com/push/ok';
        PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => strtoupper(hash('sha256', $endpoint)),
            'p256dh' => 'p',
            'auth' => 'a',
            'content_encoding' => 'aesgcm',
            'last_seen_at' => now(),
        ]);

        $mockWebPush = Mockery::mock();
        $mockWebPush->shouldReceive('queueNotification')->andReturnNull();
        $mockWebPush->shouldReceive('flush')->andReturn([
            new class {
                public function isSuccess() { return true; }
                public function getReason() { return ''; }
            },
        ]);

        // makeWebPush() が protected になったので差し替え可能
        $this->partialMock(\App\Http\Controllers\Api\V1\PushSubscriptionController::class, function ($mock) use ($mockWebPush) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('makeWebPush')->andReturn($mockWebPush);
        });

        $res = $this->postJson('/api/v1/push/test', [
            'title' => 't',
            'body' => 'b',
            'url' => '/today',
        ]);

        $res->assertStatus(200);
        $res->assertJson([
            'ok' => true,
            'sent' => 1,
            'failed' => 0,
        ]);
        $res->assertJsonStructure([
            'ok',
            'sent',
            'failed',
            'removed',
            'errors',
        ]);
    }
}