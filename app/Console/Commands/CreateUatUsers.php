<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class CreateUatUsers extends Command
{
    protected $signature = 'kanozay:uat-users {--confirm-isolated : Confirm this is a disposable local UAT database with no customer records}';
    protected $description = 'Create synthetic, least-privilege UAT users and print one-time random credentials';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || ! $this->option('confirm-isolated')) {
            $this->error('Refused. Set APP_ENV=local and explicitly confirm an isolated UAT database.');
            return self::FAILURE;
        }
        $roles = [
            'finance' => 'Finance Manager', 'shareholder' => 'Shareholder Officer',
            'production' => 'Production Manager', 'inventory' => 'Inventory Officer',
            'laboratory' => 'Laboratory Officer', 'sales' => 'Sales Officer',
            'hr' => 'HR Officer', 'viewer' => 'Viewer',
        ];
        $emails = array_map(fn ($key) => 'uat-'.$key.'@kanozay.invalid', array_keys($roles));
        if (User::whereIn('email', $emails)->exists()) {
            $this->error('Synthetic UAT users already exist. No passwords or roles were changed.');
            return self::FAILURE;
        }
        if (Role::where('guard_name', 'web')->whereIn('name', array_values($roles))->count() !== count($roles)) {
            $this->error('Seed roles first with php artisan db:seed --force.');
            return self::FAILURE;
        }
        $credentials = DB::transaction(function () use ($roles) {
            $rows = [];
            foreach ($roles as $key => $role) {
                $password = bin2hex(random_bytes(16));
                $email = 'uat-'.$key.'@kanozay.invalid';
                $user = User::create(['name' => 'Synthetic UAT '.ucfirst($key), 'email' => $email, 'password' => $password]);
                $user->assignRole($role);
                $rows[] = [$email, $role, $password];
            }
            return $rows;
        });
        $this->warn('One-time credentials: copy to an approved private channel. They are not written to a file.');
        $this->table(['Login email', 'Role', 'One-time password'], $credentials);
        return self::SUCCESS;
    }
}
