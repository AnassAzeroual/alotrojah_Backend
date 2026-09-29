<?php

namespace App\Providers;

use App\Models\AcademicSeason;
use App\Models\Attendance;
use App\Models\Center;
use App\Models\DelegationToken;
use App\Models\Exam;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\MurajaaReview;
use App\Models\RevisionLog;
use App\Models\ScoringModule;
use App\Models\SeasonResult;
use App\Models\SessionScore;
use App\Models\Student;
use App\Models\TermPlan;
use App\Models\TermResult;
use App\Models\User;
use App\Models\WeeklyGoal;
use App\Observers\SessionScoreObserver;
use App\Policies\AttendancePolicy;
use App\Policies\CenterPolicy;
use App\Policies\DelegationPolicy;
use App\Policies\ExamPolicy;
use App\Policies\GroupPolicy;
use App\Policies\GuardianPolicy;
use App\Policies\MurajaaPolicy;
use App\Policies\ResultPolicy;
use App\Policies\ScoringModulePolicy;
use App\Policies\SeasonPolicy;
use App\Policies\SessionScorePolicy;
use App\Policies\StudentPolicy;
use App\Policies\TermPlanPolicy;
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
        Gate::policy(AcademicSeason::class, SeasonPolicy::class);
        Gate::policy(TermPlan::class, TermPlanPolicy::class);
        Gate::policy(ScoringModule::class, ScoringModulePolicy::class);
        Gate::policy(MurajaaReview::class, MurajaaPolicy::class);
        Gate::policy(RevisionLog::class, MurajaaPolicy::class);
        Gate::policy(Exam::class, ExamPolicy::class);
        Gate::policy(TermResult::class, ResultPolicy::class);
        Gate::policy(SeasonResult::class, ResultPolicy::class);
        Gate::policy(DelegationToken::class, DelegationPolicy::class);
        SessionScore::observe(SessionScoreObserver::class);
    }
}
