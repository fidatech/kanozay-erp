<?php

namespace App\Listeners;

use App\Models\ActivityLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;

/**
 * Security audit trail for sign-in activity. Passwords and tokens are never recorded;
 * an attempted e-mail address is stored so repeated failures can be investigated.
 */
class RecordAuthenticationActivity
{
    private function write(string $action, ?int $userId, string $description, array $details = []): void
    {
        $request = request();
        ActivityLog::create([
            'actor_id' => $userId, 'action' => $action, 'subject_type' => 'authentication', 'subject_id' => $userId,
            'description' => $description,
            'details' => $details + [
                'ip' => $request?->ip(),
                'user_agent' => Str::limit((string) $request?->userAgent(), 250, ''),
            ],
        ]);
    }

    public function login(Login $event): void
    {
        $this->write('auth.login', (int) $event->user->getAuthIdentifier(), 'User signed in.', ['remember' => (bool) $event->remember]);
    }

    public function failed(Failed $event): void
    {
        $email = Str::limit((string) ($event->credentials['email'] ?? ''), 190, '');
        $this->write('auth.login_failed', $event->user?->getAuthIdentifier(), 'Sign-in attempt failed.', [
            'email' => $email, 'known_account' => $event->user !== null,
        ]);
    }

    public function logout(Logout $event): void
    {
        if ($event->user) {
            $this->write('auth.logout', (int) $event->user->getAuthIdentifier(), 'User signed out.');
        }
    }

    public function lockout(Lockout $event): void
    {
        $this->write('auth.lockout', null, 'Sign-in temporarily locked after repeated failures.', [
            'email' => Str::limit((string) $event->request->input('email'), 190, ''),
        ]);
    }

    public function passwordReset(PasswordReset $event): void
    {
        $this->write('auth.password_reset', (int) $event->user->getAuthIdentifier(), 'Password was reset through the reset link.');
    }
}
