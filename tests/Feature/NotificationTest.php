<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AdministrativeChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_are_private_to_their_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owner->notify(new AdministrativeChange('Access updated', 'An administrative change occurred.'));
        $notification = $owner->notifications()->firstOrFail();

        $this->actingAs($other)->get(route('notifications.index'))->assertOk()->assertDontSee('Access updated');
        $this->actingAs($other)->patch(route('notifications.read', $notification->id))->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
        $this->actingAs($owner)->get(route('notifications.index'))->assertOk()->assertSee('Access updated');
        $this->actingAs($owner)->patch(route('notifications.read', $notification->id))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_as_read_affects_only_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owner->notify(new AdministrativeChange('Owner', 'First'));
        $other->notify(new AdministrativeChange('Other', 'Second'));
        $this->actingAs($owner)->patch(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $owner->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }
}
