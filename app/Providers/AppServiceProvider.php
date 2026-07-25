<?php

namespace App\Providers;

use App\Models\PaymentGateway;
use App\Models\Subscription;
use App\Support\CurrentSchool;
use Illuminate\Pagination\Paginator;
use App\Observers\SubscriptionObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentSchool::class, function ($app) {
            return new CurrentSchool($app['request']);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
       
        
     
    }
}
