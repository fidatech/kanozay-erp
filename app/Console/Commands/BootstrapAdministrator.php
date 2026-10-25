<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class BootstrapAdministrator extends Command
{
    protected $signature = 'kanozay:admin';
    protected $description = 'Interactively create the first Kanozay ERP administrator (no password arguments)';

    public function handle(): int
    {
        if (User::count() > 0) {
            $this->error('Bootstrap is available only before the first user is created.');
            return self::FAILURE;
        }

        if (!Role::where('name', 'Super Administrator')->where('guard_name', 'web')->exists()) {
            $this->error('First run: php artisan db:seed --class=RolePermissionSeeder');
            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Administrator full name'));
        $email = trim((string) $this->ask('Administrator email'));
        $password = (string) $this->secret('Password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()],
        ]);

        if ($validator->fails() || $password !== $confirmation) {
            $this->error('Invalid input or passwords do not match.');
            foreach ($validator->errors()->all() as $error) $this->error($error);
            return self::FAILURE;
        }

        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
        $user->assignRole('Super Administrator');
        $this->info('Administrator created successfully. Keep your password private.');
        return self::SUCCESS;
    }
}
