<?php

use App\Models\Role;
use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Starting WIMSChat access for every role: Super Admin and Project Manager get every WIMSChat ability, every other role
 * gets the same WIMSChat abilities as the "Account" role (what it already has, or the everyday set below when it has
 * none yet). Only ever adds abilities, so anything granted or tuned by hand is kept. Re-running is safe.
 */
return new class extends Migration
{
    private const FULL_ACCESS = ['Super Admin', 'Project Manager'];

    // Everyday chat: read, start/send/edit own, react/forward/pin, search, mention, nudge, attach & download files.
    private const EVERYDAY = [
        'view-wimschat', 'start-wimschat-conversations', 'send-wimschat-messages', 'edit-wimschat-messages',
        'react-wimschat-messages', 'forward-wimschat-messages', 'pin-wimschat-messages', 'search-wimschat',
        'mention-wimschat-users', 'nudge-wimschat-users', 'attach-wimschat-files', 'view-wimschat-attachments',
        'download-wimschat-attachments',
    ];

    public function up(): void
    {
        $all = array_keys(PermissionRegistry::modules()['wimschat']['abilities']);
        foreach ($all as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (Role::query()->get()->groupBy('account_id') as $roles) {
            $account = $roles->firstWhere('name', 'Account');
            $template = $account
                ? $account->permissions->pluck('name')->filter(fn ($n) => in_array($n, $all, true))->values()->all()
                : [];
            $template = $template ?: self::EVERYDAY;
            if ($account && ! $account->permissions->pluck('name')->intersect($all)->count()) {
                $account->givePermissionTo($template);
            }

            foreach ($roles as $role) {
                $role->givePermissionTo(in_array($role->name, self::FULL_ACCESS, true) ? $all : $template);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permissions granted to roles are left in place: removing them could take chat away from people who rely on it.
    }
};
