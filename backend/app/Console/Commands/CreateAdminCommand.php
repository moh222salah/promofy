<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAdminCommand extends Command
{
    protected $signature = 'promofy:create-admin {email} {--name=Admin} {--password= : Omit to generate a random password}';

    protected $description = 'Create (or promote) a super admin staff account';

    public function handle(): int
    {
        $this->callSilent('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $email = Str::lower($this->argument('email'));
        $password = $this->option('password') ?: Str::password(20, symbols: false);

        $user = User::query()->where('email', $email)->first() ?? new User;
        $user->fill(['name' => $this->option('name'), 'email' => $email]);
        if (! $user->exists || $this->option('password')) {
            $user->password = $password;
        }
        $user->forceFill(['type' => User::TYPE_STAFF, 'is_active' => true, 'email_verified_at' => now()])->save();
        $user->roles()->syncWithoutDetaching([Role::query()->where('slug', PermissionCatalog::SUPER_ADMIN)->value('id')]);

        $this->info("Super admin ready: {$email}");
        if (! $this->option('password')) {
            $this->warn("Generated password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
