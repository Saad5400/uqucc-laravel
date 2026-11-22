<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * `manage-settings`: the AI switches and daily budget, the Telegram allowed
 * chats, and the cache clear — admin-only from now on (editors used to have
 * them). Created here rather than only in the seeder so the deploy's
 * `migrate --force` grants it to the existing admin role.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('manage-settings', 'web');

        Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'manage-settings')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
