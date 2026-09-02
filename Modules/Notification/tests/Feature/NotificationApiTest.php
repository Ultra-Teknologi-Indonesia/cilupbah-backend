<?php

namespace Modules\Notification\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Notification\Models\Notification;
use Modules\Notification\Services\NotificationDispatcher;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function makeNotification(User $owner, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $owner->id,
            'title'   => 'Tugas baru',
            'message' => 'Anda mendapat tugas baru.',
            'type'    => 'task_assigned',
            'data'    => ['ref' => 'X'],
            'is_read' => false,
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    public function test_index_returns_only_current_user_notifications(): void
    {
        $other = User::factory()->create();

        $this->makeNotification($this->user);
        $this->makeNotification($this->user);
        $this->makeNotification($other);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_unread_count_ignores_read_and_other_users(): void
    {
        $other = User::factory()->create();

        $this->makeNotification($this->user);
        $this->makeNotification($this->user);
        $this->makeNotification($this->user, ['is_read' => true, 'read_at' => now()]);
        $this->makeNotification($other);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertStatus(200)
            ->assertJsonPath('data.count', 2);
    }

    public function test_channel_order_notifications_are_hidden_but_manual_orders_remain_visible(): void
    {
        $channel = $this->makeNotification($this->user, [
            'title' => 'Pesanan baru dari channel',
            'message' => 'Pesanan SP-123 masuk.',
            'type' => 'order_new',
            'data' => ['source' => 'shopee'],
        ]);
        $manual = $this->makeNotification($this->user, [
            'title' => 'Pesanan baru masuk',
            'message' => 'Pesanan SO-00001 siap diproses.',
            'type' => 'order_new',
            'data' => ['source' => null],
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $manual->id)
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/notifications/{$channel->id}")
            ->assertStatus(404);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertStatus(200)
            ->assertJsonPath('data.count', 1);
    }

    public function test_new_channel_order_notification_is_not_stored_or_pushed(): void
    {
        app(NotificationDispatcher::class)->toUser($this->user->id, [
            'type' => 'order_new',
            'title' => 'Pesanan baru dari channel',
            'message' => 'Pesanan TT-123 masuk.',
            'data' => ['source' => 'tiktok'],
            'push' => false,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->user->id,
            'type' => 'order_new',
        ]);
    }

    public function test_mark_as_read_flips_flag_for_owned_notification(): void
    {
        $notification = $this->makeNotification($this->user);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'id'      => $notification->id,
            'is_read' => true,
        ]);
    }

    public function test_cannot_mark_another_users_notification(): void
    {
        $other = User::factory()->create();
        $notification = $this->makeNotification($other);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertStatus(404);

        $this->assertDatabaseHas('notifications', [
            'id'      => $notification->id,
            'is_read' => false,
        ]);
    }
}
