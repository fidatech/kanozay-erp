<?php

namespace Tests\Feature\Auth;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** M01: sign-in activity is written to the audit log without ever storing a password. */
class AuthenticationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_failure_lockout_and_sign_out_are_audited_without_secrets(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-secret-value'])->assertSessionHasErrors('email');
        $failed = ActivityLog::where('action', 'auth.login_failed')->sole();
        $this->assertSame($user->id, (int) $failed->actor_id);
        $this->assertSame($user->email, $failed->details['email']);
        $this->assertTrue($failed->details['known_account']);

        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'another-secret-value'])->assertSessionHasErrors('email');
        $unknown = ActivityLog::where('action', 'auth.login_failed')->orderByDesc('id')->first();
        $this->assertNull($unknown->actor_id);
        $this->assertFalse($unknown->details['known_account']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
        $login = ActivityLog::where('action', 'auth.login')->sole();
        $this->assertSame($user->id, (int) $login->actor_id);
        $this->assertSame('authentication', $login->subject_type);
        $this->assertArrayHasKey('ip', $login->details);

        $this->post('/logout')->assertRedirect();
        $this->assertSame(1, ActivityLog::where('action', 'auth.logout')->where('actor_id', $user->id)->count());

        // Repeated failures lock the form and the lockout itself is recorded.
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/login', ['email' => 'locked@example.test', 'password' => 'bad-secret-'.$attempt]);
        }
        $this->assertGreaterThanOrEqual(1, ActivityLog::where('action', 'auth.lockout')->count());
        $this->assertSame('locked@example.test', ActivityLog::where('action', 'auth.lockout')->first()->details['email']);

        // No password ever reaches the audit trail.
        $dump = ActivityLog::all()->map(fn (ActivityLog $log) => json_encode($log->getAttributes()))->implode("\n");
        foreach (['wrong-secret-value', 'another-secret-value', 'bad-secret-'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertStringNotContainsString('"password"', $dump);
    }
}
