<?php

namespace App\Providers;

use App\Models\Department;
use App\Models\OfficeHeld;
use App\Models\UserRoleAssignment;
use App\Policies\DepartmentPolicy;
use App\Policies\OfficePolicy;
use App\Policies\UserRoleAssignmentPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        OfficeHeld::class => OfficePolicy::class,
        Department::class => DepartmentPolicy::class,
        UserRoleAssignment::class => UserRoleAssignmentPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
