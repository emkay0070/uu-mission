<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ActivityObjectiveController;
use App\Http\Controllers\ActivityParticipantController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BaptismRecordController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignObjectiveController;
use App\Http\Controllers\CommunicationController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\FieldReportController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MissionOutcomeController;
use App\Http\Controllers\MissionSiteController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrgHierarchyController;
use App\Http\Controllers\OrgMembershipController;
use App\Http\Controllers\OutreachContactController;
use App\Http\Controllers\OutreachEncounterController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ResourceAIController;
use App\Http\Controllers\ResourceBookmarkController;
use App\Http\Controllers\ResourceCategoryController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\ResourceDistributionController;
use App\Http\Controllers\ResourceDownloadController;
use App\Http\Controllers\ResourceFileController;
use App\Http\Controllers\ResourceHighlightController;
use App\Http\Controllers\ResourceNoteController;
use App\Http\Controllers\ResourceReadingProgressController;
use App\Http\Controllers\ResourceSavedController;
use App\Http\Controllers\SiteLogisticsController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TranslationController;
use App\Http\Controllers\UserRoleAssignmentController;
use App\Http\Controllers\WorkspaceController;
use App\Models\ActivityType;
use App\Models\EncounterMethod;
use App\Models\EngagementStage;
use App\Models\FunctionalRoleType;
use App\Models\Language;
use App\Models\OfficeType;
use App\Models\OrgUnit;
use App\Models\OrgUnitType;
use App\Models\OutcomeType;
use App\Models\Person;
use App\Models\Programme;
use App\Models\ProgrammeType;
use App\Models\SkillCategory;
use App\Models\SystemRole;
use App\Models\TeamType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| UU Mission Platform — API v1 Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api/v1 (configured in bootstrap/app.php).
|
| Authentication: Laravel Sanctum token auth.
| Public routes:  POST /auth/login only.
| Protected routes: everything else requires auth:sanctum middleware.
|
| Scope enforcement is applied in two layers:
|   1. Database — composite FK constraints (Correction 2) and CHECK constraints (Correction 1).
|   2. Controller — explicit scope validation before every mutation.
|   3. Policy (future) — Laravel Gates/Policies for fine-grained per-user access.
|
*/

// ── Public Auth (no authentication required) ─────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::get('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);

// ── Public Reference Data ─────────────────────────────────────────────────────
Route::get('/ref/skill-categories', fn () => response()->json(
    SkillCategory::active()->ordered()->get()
));

Route::get('/ref/languages', fn () => response()->json(
    Language::active()->ordered()->get()
));

// ── Authenticated ─────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth management
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });

    // Invitations (admin/coordinator only)
    Route::prefix('invitations')->group(function () {
        Route::get('/', [InvitationController::class, 'index']);
        Route::post('/', [InvitationController::class, 'store']);
        Route::get('/{invitation}', [InvitationController::class, 'show']);
        Route::post('/{invitation}/revoke', [InvitationController::class, 'revoke']);
        Route::post('/{invitation}/resend', [InvitationController::class, 'resend']);
    });

    // ── Workspace (authenticated missionary's personal data) ─────────────
    Route::prefix('workspace')->group(function () {
        Route::get('/my-assignment', [WorkspaceController::class, 'myAssignment']);
        Route::get('/my-team', [WorkspaceController::class, 'myTeam']);
        Route::get('/my-sites', [WorkspaceController::class, 'mySites']);
        Route::get('/my-profile', [WorkspaceController::class, 'myProfile']);
        Route::patch('/my-profile', [WorkspaceController::class, 'updateMyProfile']);
        Route::patch('/my-person', [WorkspaceController::class, 'updateMyPerson']);
        Route::post('/my-languages', [WorkspaceController::class, 'addMyLanguage']);
        Route::delete('/my-languages/{languageCode}', [WorkspaceController::class, 'removeMyLanguage']);
        Route::post('/my-skills', [WorkspaceController::class, 'addMySkill']);
        Route::delete('/my-skills/{skillId}', [WorkspaceController::class, 'removeMySkill']);
        Route::post('/my-mission-experience', [WorkspaceController::class, 'addMyMissionExperience']);
        Route::delete('/my-mission-experience/{experienceId}', [WorkspaceController::class, 'removeMyMissionExperience']);
        Route::post('/my-training', [WorkspaceController::class, 'addMyTraining']);
        Route::delete('/my-training/{trainingId}', [WorkspaceController::class, 'removeMyTraining']);
        Route::post('/my-profile-photo', [WorkspaceController::class, 'uploadProfilePhoto']);
        Route::post('/my-cover-photo', [WorkspaceController::class, 'uploadCoverPhoto']);
    });

    // ── Communication Centre (missionary's official communications) ────────
    Route::prefix('communications')->group(function () {
        Route::get('/', [CommunicationController::class, 'index']);
        Route::get('/unread-count', [CommunicationController::class, 'unreadCount']);
        Route::get('/requiring-acknowledgement', [CommunicationController::class, 'requiringAcknowledgement']);
        Route::post('/', [CommunicationController::class, 'store']);
        Route::get('/{communication}', [CommunicationController::class, 'show']);
        Route::patch('/{communication}', [CommunicationController::class, 'update']);
        Route::delete('/{communication}', [CommunicationController::class, 'destroy']);
        Route::post('/{communication}/read', [CommunicationController::class, 'read']);
        Route::post('/{communication}/acknowledge', [CommunicationController::class, 'acknowledge']);
    });

    // ── Campaigns ─────────────────────────────────────────────────────────────
    Route::prefix('campaigns')->group(function () {

        Route::get('/', [CampaignController::class, 'index']);
        Route::post('/', [CampaignController::class, 'store']);
        Route::get('/{campaign}', [CampaignController::class, 'show']);
        Route::patch('/{campaign}', [CampaignController::class, 'update']);
        Route::get('/{campaign}/stats', [CampaignController::class, 'stats']);
        Route::post('/{campaign}/transition', [CampaignController::class, 'transition']);
        Route::post('/{campaign}/media', [CampaignController::class, 'attachMedia']);
        Route::delete('/{campaign}/media/{media_uuid}', [CampaignController::class, 'detachMedia']);

        // ── Objectives ───────────────────────────────────────────────────────
        Route::prefix('/{campaign}/objectives')->group(function () {
            Route::get('/', [CampaignObjectiveController::class, 'index']);
            Route::post('/', [CampaignObjectiveController::class, 'store']);
            Route::patch('/{objective}', [CampaignObjectiveController::class, 'update']);
            Route::delete('/{objective}', [CampaignObjectiveController::class, 'destroy']);
        });

        // ── Sites (nested under campaign for scope safety) ────────────────────
        Route::prefix('/{campaign}/sites')->group(function () {
            Route::get('/', [MissionSiteController::class, 'index']);
            Route::post('/', [MissionSiteController::class, 'store']);
            Route::get('/{site}', [MissionSiteController::class, 'show']);
            Route::patch('/{site}', [MissionSiteController::class, 'update']);
            Route::delete('/{site}', [MissionSiteController::class, 'destroy']);
            Route::get('/{site}/readiness', [MissionSiteController::class, 'readiness']);
            Route::get('/{site}/logistics', [SiteLogisticsController::class, 'show']);
            Route::put('/{site}/logistics', [SiteLogisticsController::class, 'upsert']);
            Route::post('/{site}/media', [MissionSiteController::class, 'attachMedia']);
            Route::delete('/{site}/media/{media_uuid}', [MissionSiteController::class, 'detachMedia']);
        });

        // ── Teams (nested under campaign) ─────────────────────────────────────
        Route::prefix('/{campaign}/teams')->group(function () {
            Route::get('/', [TeamController::class, 'index']);
            Route::post('/', [TeamController::class, 'store']);
            Route::get('/{team}', [TeamController::class, 'show']);
            Route::patch('/{team}', [TeamController::class, 'update']);
            Route::post('/{team}/members', [TeamController::class, 'addMember']);
            Route::patch('/{team}/members/{member}', [TeamController::class, 'updateMember']);
            Route::delete('/{team}/members/{member}', [TeamController::class, 'removeMember']);
            Route::post('/{team}/media', [TeamController::class, 'attachMedia']);
            Route::delete('/{team}/media/{media_uuid}', [TeamController::class, 'detachMedia']);
        });

        // ── Activities (scheduled field events) ───────────────────────────────
        Route::prefix('/{campaign}/activities')->group(function () {
            Route::get('/', [ActivityController::class, 'index']);
            Route::post('/', [ActivityController::class, 'store']);
            Route::get('/{activity}', [ActivityController::class, 'show']);
            Route::patch('/{activity}', [ActivityController::class, 'update']);
            Route::delete('/{activity}', [ActivityController::class, 'destroy']);
            Route::post('/{activity}/media', [ActivityController::class, 'attachMedia']);
            Route::delete('/{activity}/media/{media_uuid}', [ActivityController::class, 'detachMedia']);

            // ── Planned roster for the activity (NOT attendance) ──────────────
            Route::prefix('/{activity}/participants')->group(function () {
                Route::get('/', [ActivityParticipantController::class, 'index']);
                Route::post('/', [ActivityParticipantController::class, 'store']);
                Route::delete('/{participant}', [ActivityParticipantController::class, 'destroy']);
            });

            // ── Objectives: what the activity sets out to achieve ─────────────
            Route::prefix('/{activity}/objectives')->group(function () {
                Route::get('/', [ActivityObjectiveController::class, 'index']);
                Route::post('/', [ActivityObjectiveController::class, 'store']);
                Route::patch('/{objective}', [ActivityObjectiveController::class, 'update']);
                Route::delete('/{objective}', [ActivityObjectiveController::class, 'destroy']);
            });
        });

        // ── Assignments (missionary deployment) ───────────────────────────────
        Route::prefix('/{campaign}/assignments')->group(function () {
            Route::get('/', [AssignmentController::class, 'index']);
            Route::post('/', [AssignmentController::class, 'store']);
            Route::get('/{assignment}', [AssignmentController::class, 'show']);
            Route::patch('/{assignment}', [AssignmentController::class, 'update']);
            Route::delete('/{assignment}', [AssignmentController::class, 'destroy']);
        });

        // ── Role Assignments (system roles scoped to campaign/site/team) ───────
        Route::prefix('/{campaign}/role-assignments')->group(function () {
            Route::get('/', [UserRoleAssignmentController::class, 'indexForCampaign']);
        });

        Route::prefix('/{campaign}/sites/{site}/role-assignments')->group(function () {
            Route::get('/', [UserRoleAssignmentController::class, 'indexForSite']);
        });

        Route::prefix('/{campaign}/teams/{team}/role-assignments')->group(function () {
            Route::get('/', [UserRoleAssignmentController::class, 'indexForTeam']);
        });
        Route::get('/org-units/{orgUnit}/role-assignments', [UserRoleAssignmentController::class, 'indexForOrgUnit']);

        // ── Field reports ──────────────────────────────────────────────────────
        Route::prefix('/{campaign}/reports')->group(function () {
            Route::get('/', [FieldReportController::class, 'index']);
            Route::post('/', [FieldReportController::class, 'store']);
            Route::get('/{report}', [FieldReportController::class, 'show']);
            Route::patch('/{report}', [FieldReportController::class, 'update']);
            // Live preparation: GET is a pure read; POST freezes the compiled
            // snapshot onto a draft. Both are declared before any static suffix
            // that could be confused with a report id.
            Route::get('/{report}/preparation', [FieldReportController::class, 'preparation']);
            Route::post('/{report}/prepare', [FieldReportController::class, 'prepare']);
            Route::post('/{report}/submit', [FieldReportController::class, 'submit']);
            Route::post('/{report}/review', [FieldReportController::class, 'review']);
            // Hierarchical chain: forward past an overdue review, consolidate
            // submitted lower-level reports, or generate a report from the
            // records that exist when a lower-level submission is delayed.
            Route::post('/{report}/forward', [FieldReportController::class, 'forward']);
            Route::post('/{report}/consolidate', [FieldReportController::class, 'consolidate']);
            Route::post('/{report}/recover', [FieldReportController::class, 'recover']);
            Route::get('/{report}/events', [FieldReportController::class, 'events']);
        });

        // ── Mission outcomes ──────────────────────────────────────────────────
        Route::prefix('/{campaign}/outcomes')->group(function () {
            Route::get('/', [MissionOutcomeController::class, 'index']);
            Route::post('/', [MissionOutcomeController::class, 'store']);
            Route::get('/{outcome}', [MissionOutcomeController::class, 'show']);
            Route::post('/{outcome}/follow-up', [MissionOutcomeController::class, 'assignFollowUp']);
            Route::post('/{outcome}/complete-follow-up', [MissionOutcomeController::class, 'completeFollowUp']);
        });
    });

    // ── Reference / lookup data ───────────────────────────────────────────────
    Route::get('/ref/programme-types', fn () => response()->json(
        ProgrammeType::where('is_active', true)->orderBy('label')->get()
    ));

    Route::get('/ref/org-unit-types', fn () => response()->json(
        OrgUnitType::where('is_active', true)->orderBy('sort_order')->get()
    ));

    Route::get('/ref/team-types', fn () => response()->json(
        TeamType::where('is_active', true)->orderBy('label')->get()
    ));

    Route::get('/ref/activity-types', fn () => response()->json(
        ActivityType::where('is_active', true)->orderBy('label')->get()
    ));

    Route::get('/ref/functional-role-types', fn () => response()->json(
        FunctionalRoleType::where('is_active', true)->orderBy('label')->get()
    ));

    Route::get('/ref/system-roles', fn () => response()->json(
        SystemRole::where('is_active', true)->orderBy('label')->get()
    ));

    Route::get('/ref/office-types', fn () => response()->json(
        OfficeType::with('orgUnitType')->where('is_active', true)->orderBy('label')->get()
    ));

    Route::get('/ref/org-units', fn () => response()->json(
        OrgUnit::with('type')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get()
    ));

    Route::get('/ref/outcome-types', fn () => response()->json(
        OutcomeType::where('is_active', true)->orderBy('sort_order')->get()
    ));

    Route::get('/ref/programmes', fn () => response()->json(
        Programme::orderBy('name')->get(['id', 'name'])
    ));

    Route::get('/ref/persons', function (Request $request) {
        $q = $request->input('search', '');

        return response()->json(
            Person::query()
                ->when($q, fn ($query) => $query->where(
                    DB::raw("CONCAT(first_name, ' ', last_name)"),
                    'ilike',
                    "%{$q}%"
                ))
                ->select('id', 'first_name', 'last_name', 'user_id')
                ->orderBy('first_name')
                ->limit(30)
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'full_name' => trim("{$p->first_name} {$p->last_name}"),
                ])
        );
    });

    Route::get('/ref/encounter-methods', fn () => response()->json(
        EncounterMethod::where('is_active', true)->orderBy('sort_order')->get()
    ));

    Route::get('/ref/engagement-stages', fn () => response()->json(
        EngagementStage::active()->ordered()->get()
    ));

    // ── Mission Translator ───────────────────────────────────────────────────────
    Route::prefix('translate')->group(function () {
        Route::post('/', [TranslationController::class, 'translate']);
        Route::get('/languages', [TranslationController::class, 'languages']);
    });

    // ── Speech Recognition ───────────────────────────────────────────────────────
    Route::post('/transcribe', [TranslationController::class, 'transcribe']);

    // ── Speech Synthesis ────────────────────────────────────────────────────────
    Route::post('/synthesize', [TranslationController::class, 'synthesize']);

    // ── People Register (Fieldwork) ──────────────────────────────────────────────
    Route::prefix('people')->group(function () {
        Route::get('/', [PersonController::class, 'index']);
        Route::post('/', [PersonController::class, 'store']);
        Route::get('/check-duplicates', [PersonController::class, 'checkDuplicates']);
        Route::get('/{person}', [PersonController::class, 'show']);
        Route::patch('/{person}', [PersonController::class, 'update']);
        Route::post('/{person}/photo', [PersonController::class, 'uploadPhoto']);
        Route::delete('/{person}/photo', [PersonController::class, 'removePhoto']);
        Route::post('/{person}/cover-photo', [PersonController::class, 'uploadCoverPhoto']);
        Route::delete('/{person}/cover-photo', [PersonController::class, 'removeCoverPhoto']);

        // Org memberships nested under person
        Route::get('/{person}/org-memberships', [OrgMembershipController::class, 'index']);
    });

    // Org memberships (standalone for admin management)
    Route::prefix('org-memberships')->group(function () {
        Route::post('/', [OrgMembershipController::class, 'store']);
        Route::patch('/{membership}', [OrgMembershipController::class, 'update']);
        Route::post('/{membership}/approve', [OrgMembershipController::class, 'approve']);
        Route::post('/{membership}/reject', [OrgMembershipController::class, 'reject']);
        Route::delete('/{membership}', [OrgMembershipController::class, 'destroy']);
    });

    // Organisational hierarchy
    Route::get('/org-hierarchy', [OrgHierarchyController::class, 'index']);
    Route::get('/org-units/search', [OrgHierarchyController::class, 'search']);
    Route::get('/org-units/{orgUnit}', [OrgHierarchyController::class, 'show']);

    // Onboarding (admin registration)
    Route::prefix('onboarding')->group(function () {
        Route::post('/register', [OnboardingController::class, 'register']);
        Route::patch('/people/{person}', [OnboardingController::class, 'update']);
    });

    // ── Outreach Encounters ───────────────────────────────────────────────────
    Route::prefix('outreach-encounters')->group(function () {
        Route::post('/', [OutreachEncounterController::class, 'store']);
        Route::get('/{encounter}', [OutreachEncounterController::class, 'show']);
        Route::post('/{encounter}/complete', [OutreachEncounterController::class, 'complete']);
        Route::post('/{encounter}/reschedule', [OutreachEncounterController::class, 'reschedule']);
    });

    // ── Outreach Contacts ─────────────────────────────────────────────────────
    Route::prefix('outreach-contacts')->group(function () {
        Route::get('/', [OutreachContactController::class, 'index']);
        Route::post('/', [OutreachContactController::class, 'store']);
        Route::get('/{contact}', [OutreachContactController::class, 'show']);
        Route::patch('/{contact}', [OutreachContactController::class, 'update']);
        Route::delete('/{contact}', [OutreachContactController::class, 'destroy']);
    });

    // ── Baptism Records ────────────────────────────────────────────────────────
    Route::prefix('baptism-records')->group(function () {
        Route::post('/', [BaptismRecordController::class, 'store']);
        Route::post('/{record}/verify', [BaptismRecordController::class, 'verify']);
    });

    // ── Role Assignments (global CRUD) ───────────────────────────────────────────
    Route::prefix('role-assignments')->group(function () {
        Route::post('/', [UserRoleAssignmentController::class, 'store']);
        Route::get('/{assignment}', [UserRoleAssignmentController::class, 'show']);
        Route::patch('/{assignment}', [UserRoleAssignmentController::class, 'update']);
        Route::delete('/{assignment}', [UserRoleAssignmentController::class, 'destroy']);
        Route::put('/{assignment}/permission-overrides', [UserRoleAssignmentController::class, 'setPermissionOverride']);
        Route::delete('/{assignment}/permission-overrides/{permission}', [UserRoleAssignmentController::class, 'removePermissionOverride']);
    });

    // ── Organisational Offices ───────────────────────────────────────────────────
    Route::get('/office-types', [OfficeController::class, 'indexOfficeTypes']);
    Route::get('/org-units/{orgUnit}/offices', [OfficeController::class, 'indexForOrgUnit']);
    Route::get('/people/{person}/offices', [OfficeController::class, 'indexForPerson']);
    Route::post('/offices', [OfficeController::class, 'store']);
    Route::get('/offices/{office}', [OfficeController::class, 'show']);
    Route::patch('/offices/{office}', [OfficeController::class, 'update']);
    Route::delete('/offices/{office}', [OfficeController::class, 'destroy']);

    // ── Departments ───────────────────────────────────────────────────────────────
    Route::get('/campaigns/{campaign}/departments', [DepartmentController::class, 'indexForCampaign']);
    Route::get('/campaigns/{campaign}/sites/{site}/departments', [DepartmentController::class, 'indexForSite']);
    Route::post('/campaigns/{campaign}/departments', [DepartmentController::class, 'store']);
    Route::get('/departments/{department}', [DepartmentController::class, 'show']);
    Route::patch('/departments/{department}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);
    Route::post('/departments/{department}/members', [DepartmentController::class, 'addMember']);
    Route::delete('/departments/{department}/members/{member}', [DepartmentController::class, 'removeMember']);

    // ── Attendance Registers (Fieldwork) ─────────────────────────────────────────
    Route::prefix('attendance-registers')->group(function () {
        Route::get('/', [AttendanceController::class, 'index']);

        // Aggregated, read-only summary. Registered BEFORE the {register}
        // placeholder so "/summary" is never mistaken for a register id.
        Route::get('/summary', [AttendanceController::class, 'summary']);

        Route::post('/', [AttendanceController::class, 'store']);
        Route::get('/{register}', [AttendanceController::class, 'show']);
        Route::get('/{register}/participants', [AttendanceController::class, 'participants']);
        Route::get('/{register}/reconciliation', [AttendanceController::class, 'reconciliation']);
        Route::post('/{register}/entries', [AttendanceController::class, 'updateEntries']);
        Route::post('/{register}/entries/{entry}/link', [AttendanceController::class, 'linkEntry']);
        Route::post('/{register}/submit', [AttendanceController::class, 'submit']);
        Route::post('/{register}/verify', [AttendanceController::class, 'verify']);
        Route::post('/{register}/reopen', [AttendanceController::class, 'reopen']);
    });

    // ── Media Library ─────────────────────────────────────────────────────────────
    Route::prefix('media')->group(function () {
        Route::get('/', [MediaController::class, 'index']);
        Route::post('/', [MediaController::class, 'store']);
        Route::get('/{uuid}', [MediaController::class, 'show']);
        Route::patch('/{uuid}', [MediaController::class, 'update']);
        Route::delete('/{uuid}', [MediaController::class, 'destroy']);
        Route::post('/{uuid}/attach', [MediaController::class, 'attach']);
        Route::post('/{uuid}/detach', [MediaController::class, 'detach']);
        Route::get('/{uuid}/url', [MediaController::class, 'getUrl']);
        Route::get('/{uuid}/thumbnail', [MediaController::class, 'getThumbnailUrl']);
    });

    // ── Resource Library ─────────────────────────────────────────────────────────────
    Route::prefix('resources')->group(function () {
        Route::get('/distribution-targets', [ResourceDistributionController::class, 'targets']);
        Route::get('/distribution-requests', [ResourceDistributionController::class, 'index']);
        Route::post('/{resource}/distribution-requests', [ResourceDistributionController::class, 'store']);
        Route::post('/distribution-requests/{distributionRequest}/resubmit', [ResourceDistributionController::class, 'resubmit']);
        Route::post('/distribution-requests/{distributionRequest}/decision', [ResourceDistributionController::class, 'decide']);
        Route::post('/distribution-requests/{distributionRequest}/revoke', [ResourceDistributionController::class, 'revoke']);
        // Static routes must be declared before the wildcard /{resource} route,
        // otherwise their first segment is captured as a resource id.
        Route::get('/reading-resources', [ResourceReadingProgressController::class, 'index']);
        Route::get('/saved-resources', [ResourceSavedController::class, 'index']);

        // Public resource listing and details
        Route::get('/', [ResourceController::class, 'index']);
        Route::get('/my-drafts', [ResourceController::class, 'myDrafts']);
        Route::post('/', [ResourceController::class, 'store']);
        Route::get('/{resource}', [ResourceController::class, 'show']);
        Route::get('/{resource}/versions', [ResourceController::class, 'versions']);
        Route::patch('/{resource}', [ResourceController::class, 'update']);
        Route::post('/{resource}/replace-file', [ResourceController::class, 'replaceFile']);
        Route::post('/{resource}/archive', [ResourceController::class, 'archive']);

        // Secure file access
        Route::get('/{resource}/preview', [ResourceFileController::class, 'preview']);
        Route::get('/{resource}/download', [ResourceFileController::class, 'download']);
        Route::get('/{resource}/thumbnail', [ResourceFileController::class, 'thumbnail']);
        Route::get('/{resource}/versions/{version}/preview', [ResourceFileController::class, 'previewVersion']);
        Route::get('/{resource}/versions/{version}/download', [ResourceFileController::class, 'downloadVersion']);

        // Reading progress
        Route::get('/{resource}/progress', [ResourceReadingProgressController::class, 'show']);
        Route::post('/{resource}/progress', [ResourceReadingProgressController::class, 'store']);

        // Bookmarks
        Route::get('/{resource}/bookmarks', [ResourceBookmarkController::class, 'index']);
        Route::post('/{resource}/bookmarks', [ResourceBookmarkController::class, 'store']);
        Route::delete('/{resource}/bookmarks/{bookmark}', [ResourceBookmarkController::class, 'destroy']);

        // Highlights
        Route::get('/{resource}/highlights', [ResourceHighlightController::class, 'index']);
        Route::post('/{resource}/highlights', [ResourceHighlightController::class, 'store']);
        Route::patch('/{resource}/highlights/{highlight}', [ResourceHighlightController::class, 'update']);
        Route::delete('/{resource}/highlights/{highlight}', [ResourceHighlightController::class, 'destroy']);

        // Notes
        Route::get('/{resource}/notes', [ResourceNoteController::class, 'index']);
        Route::post('/{resource}/notes', [ResourceNoteController::class, 'store']);
        Route::patch('/{resource}/notes/{note}', [ResourceNoteController::class, 'update']);
        Route::delete('/{resource}/notes/{note}', [ResourceNoteController::class, 'destroy']);

        // Saved resources
        Route::post('/{resource}/save', [ResourceSavedController::class, 'store']);
        Route::delete('/{resource}/save', [ResourceSavedController::class, 'destroy']);
        Route::get('/{resource}/saved-status', [ResourceSavedController::class, 'status']);

        // Downloads
        Route::post('/{resource}/download-record', [ResourceDownloadController::class, 'store']);
        Route::patch('/{resource}/download-record', [ResourceDownloadController::class, 'update']);
        Route::delete('/{resource}/download-record', [ResourceDownloadController::class, 'destroy']);
        Route::get('/{resource}/download-status', [ResourceDownloadController::class, 'status']);

        // AI Learning Companion
        Route::prefix('/{resource}/ai')->group(function () {
            Route::post('/chat', [ResourceAIController::class, 'chat']);
            Route::get('/conversations', [ResourceAIController::class, 'conversations']);
        });

        // Learning notes
        Route::prefix('/{resource}/learning-notes')->group(function () {
            Route::get('/', [ResourceAIController::class, 'learningNotes']);
            Route::post('/', [ResourceAIController::class, 'createLearningNote']);
            Route::put('/{note}', [ResourceAIController::class, 'updateLearningNote']);
            Route::delete('/{note}', [ResourceAIController::class, 'deleteLearningNote']);
        });
    });

    // AI Learning Companion (global)
    Route::prefix('ai')->group(function () {
        Route::get('/conversations', [ResourceAIController::class, 'allConversations']);
        Route::get('/conversations/{conversation}', [ResourceAIController::class, 'getConversation']);
        Route::delete('/conversations/{conversation}', [ResourceAIController::class, 'deleteConversation']);
        Route::post('/messages/{message}/retry', [ResourceAIController::class, 'retryMessage']);
        Route::post('/saved-explanations', [ResourceAIController::class, 'saveExplanation']);
        Route::get('/saved-explanations', [ResourceAIController::class, 'savedExplanations']);
    });

    // Saved and downloaded resources (personal library)
    Route::get('/saved-resources', [ResourceSavedController::class, 'index']);
    Route::get('/downloaded-resources', [ResourceDownloadController::class, 'index']);

    // Resource categories (reference data)
    Route::get('/resource-categories', [ResourceCategoryController::class, 'index']);
    Route::get('/resource-categories/{category}', [ResourceCategoryController::class, 'show']);

    // User's reading progress across all resources
    Route::get('/reading-progress', [ResourceReadingProgressController::class, 'index']);

});
