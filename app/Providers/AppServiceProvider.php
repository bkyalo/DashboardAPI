<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\Auth\UserRepository;
use App\Services\Auth\AuthService;
use App\Services\Auth\RolePermissionService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register repositories
        $this->app->bind(UserRepository::class, function ($app) {
            return new UserRepository();
        });

        // Register services
        $this->app->bind(AuthService::class, function ($app) {
            return new AuthService(
                $app->make(UserRepository::class)
            );
        });

        $this->app->bind(RolePermissionService::class, function ($app) {
            return new RolePermissionService(
                $app->make(UserRepository::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // file:// key paths can mis-report mode as the storage dir (770) under FPM.
        // Files themselves should still be 600/660 and owned by the web user.
        \Laravel\Passport\Passport::$validateKeyPermissions = false;

        \Laravel\Passport\Passport::enablePasswordGrant();
        \Laravel\Passport\Passport::tokensExpireIn(now()->addDays(15));
        \Laravel\Passport\Passport::refreshTokensExpireIn(now()->addDays(30));
        \Laravel\Passport\Passport::personalAccessTokensExpireIn(now()->addMonths(6));
    }
}
