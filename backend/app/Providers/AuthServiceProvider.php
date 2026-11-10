<?php

namespace App\Providers;

use App\Models\Communication;
use App\Models\Department;
use App\Models\Media;
use App\Models\OfficeHeld;
use App\Models\Resource;
use App\Models\ResourceConversation;
use App\Models\ResourceLearningNote;
use App\Models\UserRoleAssignment;
use App\Policies\CommunicationPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\MediaPolicy;
use App\Policies\OfficePolicy;
use App\Policies\ResourceConversationPolicy;
use App\Policies\ResourceLearningNotePolicy;
use App\Policies\ResourcePolicy;
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
        Communication::class => CommunicationPolicy::class,
        OfficeHeld::class => OfficePolicy::class,
        Department::class => DepartmentPolicy::class,
        Media::class => MediaPolicy::class,
        Resource::class => ResourcePolicy::class,
        ResourceConversation::class => ResourceConversationPolicy::class,
        ResourceLearningNote::class => ResourceLearningNotePolicy::class,
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
