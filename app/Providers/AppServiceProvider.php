<?php

namespace App\Providers;

use App\Models\Group;
use App\Models\SessionScore;
use App\Models\Student;
use App\Observers\SessionScoreObserver;
use App\Policies\GroupPolicy;
use App\Policies\StudentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Student::class, StudentPolicy::class);
        Gate::policy(Group::class, GroupPolicy::class);
        SessionScore::observe(SessionScoreObserver::class);
    }
}
