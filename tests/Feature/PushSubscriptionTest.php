<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $endpoint, string $p256dh, string $auth, array $extra = []): array
    {
        return array_merge([
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => $p256dh,
                'auth' => $auth,
            ],
            'contentEncoding' => 'aesgcm',
        ], $extra);
    }

    public function test_subscribe_requires_auth_returns_401_json(): void
    {
        $res = $this->postJson('/api/v1/push/subscribe', $this->payload('https://example.test/ep', 'p', 'a'));
        $res->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_subscribe_validates_payload(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://example.test/ep',
        ]);
        $res->assertStatus(422)->assertJsonValidationErrors(['keys']);

        $res = $this->actingAs($user)->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://example.test/ep',
            'keys' => [],
        ]);
        $res->assertStatus(422)->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);
    }

    public function test_subscribe_creates_or_updates_subscription(): void
    {
        $user = User::factory()->create();

        $endpoint = 'https://example.test/ep-1';
        $p256dh1 = 'p256dh-1';
        $auth1 = 'auth-1';

        $res = $this->actingAs($user)->postJson('/api/v1/push/subscribe', $this->payload($endpoint, $p256dh1, $auth1));
        $res->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseCount('push_subscriptions', 1);
        $row = PushSubscription::query()->first();
        $this->assertNotNull($row);
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertSame($endpoint, $row->endpoint);
        $this->assertSame(strtoupper(hash('sha256', $endpoint)), $row->endpoint_hash);
        $this->assertSame($p256dh1, $row->p256dh);
        $this->assertSame($auth1, $row->auth);

        $p256dh2 = 'p256dh-2';
        $auth2 = 'auth-2';

        $res = $this->actingAs($user)->postJson('/api/v1/push/subscribe', $this->payload($endpoint, $p256dh2, $auth2));
        $res->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseCount('push_subscriptions', 1);
        $row->refresh();
        $this->assertSame($p256dh2, $row->p256dh);
        $this->assertSame($auth2, $row->auth);
    }

    public function test_subscribe_conflict_same_endpoint_different_keys_returns_409(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $endpoint = 'https://example.test/shared-endpoint';

        $res = $this->actingAs($userA)->postJson('/api/v1/push/subscribe', $this->payload($endpoint, 'pA', 'aA'));
        $res->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseCount('push_subscriptions', 1);

        $res = $this->actingAs($userB)->postJson('/api/v1/push/subscribe', $this->payload($endpoint, 'pB', 'aB'));
        $res->assertStatus(409)->assertJson([
            'code' => 'SUBSCRIPTION_CONFLICT',
        ]);

        $row = PushSubscription::query()->first();
        $this->assertSame($userA->id, (int) $row->user_id);
        $this->assertSame('pA', $row->p256dh);
        $this->assertSame('aA', $row->auth);
    }

    public function test_subscribe_allows_transfer_same_endpoint_same_keys(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $endpoint = 'https://example.test/shared-endpoint-2';

        $res = $this->actingAs($userA)->postJson('/api/v1/push/subscribe', $this->payload($endpoint, 'pSAME', 'aSAME'));
        $res->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseCount('push_subscriptions', 1);

        $res = $this->actingAs($userB)->postJson('/api/v1/push/subscribe', $this->payload($endpoint, 'pSAME', 'aSAME'));
        $res->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseCount('push_subscriptions', 1);
        $row = PushSubscription::query()->first();
        $this->assertSame($userB->id, (int) $row->user_id);
        $this->assertSame('pSAME', $row->p256dh);
        $this->assertSame('aSAME', $row->auth);
    }

    public function test_unsubscribe_requires_auth_returns_401_json(): void
    {
        $res = $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => 'https://example.test/ep']);
        $res->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_unsubscribe_deletes_row_for_current_user(): void
    {
        $user = User::factory()->create();

        $endpoint = 'https://example.test/ep-del';

        PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => strtoupper(hash('sha256', $endpoint)),
            'p256dh' => 'p',
            'auth' => 'a',
            'content_encoding' => 'aesgcm',
            'last_seen_at' => now(),
        ]);

        $this->assertDatabaseCount('push_subscriptions', 1);

        $res = $this->actingAs($user)->postJson('/api/v1/push/unsubscribe', ['endpoint' => $endpoint]);
        $res->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_test_endpoint_is_disabled_in_production(): void
    {
        $this->app['env'] = 'production';

        $user = User::factory()->create();
        $res = $this->actingAs($user)->postJson('/api/v1/push/test', [
            'title' => 't',
            'body' => 'b',
            'url' => 'https://example.test/today',
        ]);

        $res->assertStatus(403)->assertJson(['message' => 'Disabled in production.']);
    }

    public function test_test_endpoint_returns_404_when_no_subscriptions(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/api/v1/push/test', [
            'title' => 't',
            'body' => 'b',
            'url' => 'https://example.test/today',
        ]);

        $res->assertStatus(404)->assertJson(['message' => 'No subscriptions.']);
    }

    public function test_test_endpoint_succeeds_without_real_network_by_mocking_webpush(): void
    {
        $user = User::factory()->create();

        $endpoint = 'https://example.test/ep-test';
        PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => strtoupper(hash('sha256', $endpoint)),
            'p256dh' => 'p',
            'auth' => 'a',
            'content_encoding' => 'aesgcm',
            'last_seen_at' => now(),
        ]);

        // flush() は Generator を返す必要がある
        $report = new class {
            public function isSuccess() { return true; }
            public function isSubscriptionExpired() { return false; }
            public function getReason() { return ''; }
        };

        $generator = (function () use ($report) {
            yield $report;
        })();

        $mock = \Mockery::mock(\Minishlink\WebPush\WebPush::class);
        $mock->shouldReceive('queueNotification')->andReturnNull();
        $mock->shouldReceive('flush')->andReturn($generator);

        $this->partialMock(\App\Http\Controllers\Api\V1\PushSubscriptionController::class, function ($m) use ($mock) {
            $m->shouldAllowMockingProtectedMethods();
            $m->shouldReceive('makeWebPush')->andReturn($mock);
        });

        $res = $this->actingAs($user)->postJson('/api/v1/push/test', [
            'title' => 't',
            'body' => 'b',
            'url' => 'https://example.test/today',
        ]);

        $res->assertOk()->assertJson([
            'ok' => true,
        ]);
        $res->assertJsonPath('sent', 1);
    }
}