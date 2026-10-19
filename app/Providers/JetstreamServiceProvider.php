<?php

namespace App\Providers;

use Laravel\Jetstream\Jetstream;
use App\Actions\Jetstream\DeleteUser;
use Illuminate\Support\ServiceProvider;

class JetstreamServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->configurePermissions();
        Jetstream::deleteUsersUsing(DeleteUser::class);
    }

    public function register()
    {
        // Profile page: only live, de-duplicated browser sessions (see ProfileSessionsController).
        $this->app->bind(\Laravel\Jetstream\Http\Controllers\Inertia\UserProfileController::class, \App\Http\Controllers\ProfileSessionsController::class);
    }

    protected function configurePermissions()
    {
        Jetstream::defaultApiTokenPermissions(['read']);
        Jetstream::permissions(['create', 'read', 'update', 'delete']);
    }
}
