<?php

namespace App\Providers;

use App\Models\Attendance;
use App\Models\Center;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\SessionScore;
use App\Models\Student;
use App\Models\User;
use App\Models\WeeklyGoal;
use App\Observers\SessionScoreObserver;
use App\Policies\AttendancePolicy;
use App\Policies\CenterPolicy;
use App\Policies\GroupPolicy;
use App\Policies\GuardianPolicy;
use App\Policies\SessionScorePolicy;
use App\Policies\StudentPolicy;
use App\Policies\UserPolicy;
use App\Policies\WeeklyGoalPolicy;
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
        Gate::policy(Center::class, CenterPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Guardian::class, GuardianPolicy::class);
        Gate::policy(Attendance::class, AttendancePolicy::class);
        Gate::policy(SessionScore::class, SessionScorePolicy::class);
        Gate::policy(WeeklyGoal::class, WeeklyGoalPolicy::class);
        SessionScore::observe(SessionScoreObserver::class);
    }
}
