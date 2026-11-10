<?php

namespace App\Providers;

use App\Console\Commands\RbacCheckCompatibilities;
use App\Models\Activity;
use App\Models\AttendanceRegister;
use App\Models\Campaign;
use App\Models\CampaignAssignment;
use App\Models\FieldReport;
use App\Models\MissionOutcome;
use App\Models\MissionSite;
use App\Models\Person;
use App\Models\Team;
use App\Policies\ActivityPolicy;
use App\Policies\AttendanceRegisterPolicy;
use App\Policies\CampaignAssignmentPolicy;
use App\Policies\CampaignPolicy;
use App\Policies\FieldReportPolicy;
use App\Policies\MissionOutcomePolicy;
use App\Policies\MissionSitePolicy;
use App\Policies\PersonPolicy;
use App\Policies\TeamPolicy;
use App\Services\AuthorizationService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * AuthorizationService is a scoped singleton — one instance per HTTP request.
     * This means the per-request assignment cache is shared across multiple
     * policy checks within the same request but never bleeds into the next.
     */
    public function register(): void
    {
        $this->app->scoped(AuthorizationService::class);

        $this->commands([
            RbacCheckCompatibilities::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Campaign::class,           CampaignPolicy::class);
        Gate::policy(MissionSite::class,        MissionSitePolicy::class);
        Gate::policy(Team::class,               TeamPolicy::class);
        Gate::policy(CampaignAssignment::class, CampaignAssignmentPolicy::class);
        Gate::policy(FieldReport::class,        FieldReportPolicy::class);
        Gate::policy(MissionOutcome::class,     MissionOutcomePolicy::class);
        Gate::policy(Person::class,             PersonPolicy::class);
        Gate::policy(Activity::class,           ActivityPolicy::class);
        Gate::policy(AttendanceRegister::class, AttendanceRegisterPolicy::class);
    }
}
