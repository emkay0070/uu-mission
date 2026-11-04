<?php

namespace App\Http\Controllers;

use App\Domain\WorkflowDefinitions;
use App\Models\Campaign;
use App\Models\CampaignAssignment;
use App\Models\Language;
use App\Models\Media;
use App\Models\MissionSite;
use App\Models\Person;
use App\Models\PersonLanguage;
use App\Models\PersonMissionExperience;
use App\Models\PersonSkill;
use App\Models\PersonTraining;
use App\Models\SkillCategory;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * WorkspaceController — authenticated missionary's personal workspace data.
 *
 * These endpoints return the authenticated user's own assignment and team
 * information. They do NOT accept client-supplied person IDs or user IDs.
 *
 * Security:
 *   - User is derived from Sanctum authentication ($request->user()).
 *   - Person is resolved through the user->person relationship.
 *   - Authorization is enforced for returned campaign/site/team data.
 *   - Returns null consistently when no data exists (no 404s).
 */
class WorkspaceController extends Controller
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * Return the authenticated user's current mission assignment.
     *
     * GET /api/v1/workspace/my-assignment
     *
     * A "current" assignment is one with status 'confirmed' or 'arrived'.
     * Terminal statuses (completed, withdrew, no_show) are excluded.
     *
     * If the user has no person profile or no current assignment, returns null.
     */
    public function myAssignment(Request $request): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        // 1. Preferred source: a current campaign deployment. It carries the
        //    richest metadata (arrival/departure workflow, functional role).
        if ($person) {
            $assignment = $person->campaignAssignments()
                ->with([
                    'campaign',
                    'site',
                    'team.teamType',
                    'functionalRoleType',
                ])
                ->whereIn('status', ['confirmed', 'arrived'])
                ->orderBy('created_at', 'desc')
                ->first();

            if ($assignment) {
                return response()->json([
                    'assignment' => $this->formatCampaignAssignment($assignment),
                ]);
            }
        }

        // 2. Fallback source: the authoritative record of operational access —
        //    the user's active role assignment. This makes the workspace
        //    usable for people appointed organisationally without a campaign
        //    deployment. It resolves CONTEXT ONLY; it grants no permissions.
        return response()->json([
            'assignment' => $this->resolveAssignmentContextFromRoles($user),
        ]);
    }

    /**
     * Shape a campaign deployment into the my-assignment response payload.
     */
    private function formatCampaignAssignment(CampaignAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'source' => 'campaign_assignment',
            'scope_type' => $assignment->assigned_team_id ? 'team' : ($assignment->mission_site_id ? 'site' : 'campaign'),
            'status' => $assignment->status,
            'expected_arrival' => $assignment->expected_arrival?->toIso8601String(),
            'actual_arrival' => $assignment->actual_arrival?->toIso8601String(),
            'departure' => $assignment->departure?->toIso8601String(),
            'notes' => $assignment->notes,
            'campaign' => [
                'id' => $assignment->campaign->id,
                'name' => $assignment->campaign->name,
                'description' => $assignment->campaign->description,
                'status' => $assignment->campaign->status,
                'start_date' => $assignment->campaign->start_date?->toIso8601String(),
                'end_date' => $assignment->campaign->end_date?->toIso8601String(),
            ],
            'site' => $assignment->site ? [
                'id' => $assignment->site->id,
                'name' => $assignment->site->name,
                'description' => $assignment->site->description,
                'status' => $assignment->site->status,
                'location_description' => $assignment->site->location_description,
                'latitude' => $assignment->site->latitude,
                'longitude' => $assignment->site->longitude,
                'operational_from' => $assignment->site->operational_from?->toIso8601String(),
                'operational_until' => $assignment->site->operational_until?->toIso8601String(),
            ] : null,
            'team' => $assignment->team ? [
                'id' => $assignment->team->id,
                'name' => $assignment->team->name,
                'description' => $assignment->team->description,
                'team_type' => $assignment->team->teamType ? [
                    'id' => $assignment->team->teamType->id,
                    'code' => $assignment->team->teamType->code,
                    'label' => $assignment->team->teamType->label,
                ] : null,
            ] : null,
            'functional_role' => $assignment->functionalRoleType ? [
                'id' => $assignment->functionalRoleType->id,
                'code' => $assignment->functionalRoleType->code,
                'label' => $assignment->functionalRoleType->label,
            ] : null,
        ];
    }

    /**
     * Derive the workspace context from the user's active role assignments.
     *
     * Role assignments are the authoritative record of operational access, so
     * the workspace context must be resolvable from them alone — a user may be
     * granted access by an organisational appointment without ever having a
     * campaign deployment row.
     *
     * Most-specific scope wins: team → site → campaign → org_unit. An
     * org_unit appointment resolves to the campaign it covers; when an
     * appointment spans several campaigns the most relevant one is chosen
     * deterministically (active first, then most recently started).
     *
     * Returns null when the user holds no assignment that can express a
     * campaign/site context.
     */
    private function resolveAssignmentContextFromRoles(User $user): ?array
    {
        $today = now()->toDateString();

        $assignments = UserRoleAssignment::with([
                'scopedTeam.campaign',
                'scopedTeam.site',
                'scopedTeam.teamType',
                'scopedSite.campaign',
                'scopedCampaign',
            ])
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->orderBy('id')
            ->get();

        // 1. Team-scoped appointment — the most specific context available.
        foreach ($assignments as $assignment) {
            $team = $assignment->scope_type === 'team' ? $assignment->scopedTeam : null;

            if ($team && $team->campaign) {
                return $this->formatRoleAssignmentContext($team->campaign, $team->site, $team, 'team', (int) $team->id);
            }
        }

        // 2. Site-scoped appointment.
        foreach ($assignments as $assignment) {
            $site = $assignment->scope_type === 'site' ? $assignment->scopedSite : null;

            if ($site && $site->campaign) {
                return $this->formatRoleAssignmentContext($site->campaign, $site, null, 'site', (int) $site->id);
            }
        }

        // 3. Campaign-scoped appointment.
        foreach ($assignments as $assignment) {
            $campaign = $assignment->scope_type === 'campaign' ? $assignment->scopedCampaign : null;

            if ($campaign) {
                return $this->formatRoleAssignmentContext($campaign, null, null, 'campaign', (int) $campaign->id);
            }
        }

        // 4. Org-unit appointment — resolve to a campaign the unit leads.
        foreach ($assignments as $assignment) {
            if ($assignment->scope_type !== 'org_unit' || $assignment->scoped_org_unit_id === null) {
                continue;
            }

            $campaignIds = $this->auth->campaignIdsForOrgUnit((int) $assignment->scoped_org_unit_id);

            if ($campaignIds === []) {
                continue;
            }

            $campaign = Campaign::whereIn('id', $campaignIds)
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderByDesc('start_date')
                ->orderByDesc('created_at')
                ->first();

            if ($campaign) {
                return $this->formatRoleAssignmentContext(
                    $campaign,
                    null,
                    null,
                    'org_unit',
                    (int) $assignment->scoped_org_unit_id
                );
            }
        }

        return null;
    }

    /**
     * Shape a role-assignment-derived context into the my-assignment payload.
     *
     * Workflow fields that only exist on a campaign deployment (arrival dates,
     * notes, functional role) are reported as null rather than invented.
     */
    private function formatRoleAssignmentContext(
        Campaign $campaign,
        ?MissionSite $site,
        ?Team $team,
        string $scopeType,
        int $scopeId
    ): array {
        return [
            'id' => $scopeId,
            'source' => 'role_assignment',
            'scope_type' => $scopeType,
            'status' => 'active',
            'expected_arrival' => null,
            'actual_arrival' => null,
            'departure' => null,
            'notes' => null,
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'description' => $campaign->description,
                'status' => $campaign->status,
                'start_date' => $campaign->start_date?->toIso8601String(),
                'end_date' => $campaign->end_date?->toIso8601String(),
            ],
            'site' => $site ? [
                'id' => $site->id,
                'name' => $site->name,
                'description' => $site->description,
                'status' => $site->status,
                'location_description' => $site->location_description,
                'latitude' => $site->latitude,
                'longitude' => $site->longitude,
                'operational_from' => $site->operational_from?->toIso8601String(),
                'operational_until' => $site->operational_until?->toIso8601String(),
            ] : null,
            'team' => $team ? [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description,
                'team_type' => $team->teamType ? [
                    'id' => $team->teamType->id,
                    'code' => $team->teamType->code,
                    'label' => $team->teamType->label,
                ] : null,
            ] : null,
            'functional_role' => null,
        ];
    }

    /**
     * Return the authenticated user's team and team-member information.
     *
     * GET /api/v1/workspace/my-team
     *
     * Returns the team from the user's current assignment, or null if:
     *   - User has no person profile
     *   - User has no current assignment
     *   - Assignment has no team assigned
     *   - User is not authorized to view the team
     *
     * Includes all team members with their roles and basic person info.
     */
    public function myTeam(Request $request): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        // 1. Preferred source: the team on a current campaign deployment.
        if ($person) {
            $assignment = $person->campaignAssignments()
                ->with(['team'])
                ->whereIn('status', ['confirmed', 'arrived'])
                ->orderBy('created_at', 'desc')
                ->first();

            if ($assignment && $assignment->team) {
                return response()->json(['team' => $this->formatTeam($assignment->team)]);
            }
        }

        // 2. Fallback: resolve the team from the authoritative access record.
        //    A team-scoped role assignment names the team directly; otherwise
        //    the person's own active team membership is used.
        $team = $this->resolveTeamFromRoleAssignments($user)
            ?? ($person ? $this->resolveTeamFromMembership($person) : null);

        return response()->json([
            'team' => $team ? $this->formatTeam($team) : null,
        ]);
    }

    /**
     * Resolve the team named by the user's active team-scoped role assignment.
     */
    private function resolveTeamFromRoleAssignments(User $user): ?Team
    {
        $today = now()->toDateString();

        $assignment = UserRoleAssignment::with(['scopedTeam.teamType'])
            ->where('user_id', $user->id)
            ->where('scope_type', 'team')
            ->whereNotNull('scoped_team_id')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->orderBy('id')
            ->first();

        return $assignment?->scopedTeam;
    }

    /**
     * Resolve the team from the person's own current (un-ended) membership.
     */
    private function resolveTeamFromMembership(Person $person): ?Team
    {
        $membership = $person->teamMemberships()
            ->with(['team.teamType'])
            ->whereNull('ended_on')
            ->orderBy('joined_on', 'desc')
            ->first();

        return $membership?->team;
    }

    /**
     * Shape a team and its current members into the my-team response payload.
     */
    private function formatTeam(Team $team): array
    {
        $members = $team->members()
            ->with('person')
            ->whereNull('ended_on')
            ->orderByRaw("CASE role_in_team WHEN 'leader' THEN 1 WHEN 'deputy' THEN 2 ELSE 3 END")
            ->orderBy('joined_on')
            ->get();

        return [
            'id' => $team->id,
            'name' => $team->name,
            'description' => $team->description,
            'team_type' => $team->teamType ? [
                'id' => $team->teamType->id,
                'code' => $team->teamType->code,
                'label' => $team->teamType->label,
            ] : null,
            'members' => $members->map(function (TeamMember $member) {
                return [
                    'id' => $member->id,
                    'role_in_team' => $member->role_in_team,
                    'joined_on' => $member->joined_on?->toIso8601String(),
                    'person' => [
                        'id' => $member->person->id,
                        'first_name' => $member->person->first_name,
                        'last_name' => $member->person->last_name,
                        'full_name' => $member->person->full_name,
                        'gender' => $member->person->gender,
                    ],
                ];
            })->toArray(),
        ];
    }

    /**
     * Return the mission sites the authenticated user is authorised to record
     * outcomes at, with their campaign context.
     *
     * GET /api/v1/workspace/my-sites
     *
     * The authorised set is resolved entirely on the backend from the user's
     * active role assignments via AuthorizationService — campaign, site and
     * team scopes are expanded to concrete (campaign, site) pairs, validity
     * dates and revocations are honoured, and a team assignment is resolved to
     * the parent site it belongs to. The client must never derive this list
     * from raw scopes; it simply renders what it is given.
     *
     * Read-only: this endpoint resolves CONTEXT and grants no permissions of
     * its own. It returns an empty list when the user holds no outcomes.record
     * scope.
     */
    public function mySites(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = MissionSite::query()
            ->with(['campaign:id,name,status'])
            ->orderBy('name');

        // scopeQuery fails closed: no authorised scopes yields no rows.
        $this->auth->scopeQuery(
            $query,
            $this->auth->getAuthorisedScopes($user, 'outcomes.record'),
            'campaign_id',
            'id'
        );

        $sites = $query->get()->map(fn (MissionSite $site) => [
            'id'       => $site->id,
            'name'     => $site->name,
            'status'   => $site->status,
            'campaign' => $site->campaign ? [
                'id'     => $site->campaign->id,
                'name'   => $site->campaign->name,
                'status' => $site->campaign->status,
            ] : null,
        ])->values();

        return response()->json(['sites' => $sites]);
    }

    /**
     * Return the authenticated user's complete profile information.
     *
     * GET /api/v1/workspace/my-profile
     *
     * Returns:
     *   - Missionary profile (skills, languages, bio, training)
     *   - Organisational memberships
     *   - Current office held
     *   - Current assignment
     *   - Assignment history
     */
    public function myProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        if (! $person) {
            return response()->json([
                'missionary_profile' => null,
                'org_memberships' => [],
                'current_office' => null,
                'assignment' => null,
                'assignment_history' => [],
            ]);
        }

        // Load missionary profile with relationships
        $missionaryProfile = $person->missionaryProfile;
        if ($missionaryProfile) {
            $missionaryProfile->load(['homeOrgUnit.type', 'skills']);
        }

        // Load person relationships
        $person->load(['addresses', 'skills', 'languages.language', 'missionExperience', 'training', 'coverPhoto']);

        // Load organisational memberships
        $orgMemberships = $person->orgMemberships()
            ->with('orgUnit.type')
            ->orderBy('joined_on', 'desc')
            ->get();

        // Load current office (held_until is null)
        $currentOffice = $person->officesHeld()
            ->with(['officeType', 'orgUnit'])
            ->whereNull('held_until')
            ->orderBy('held_from', 'desc')
            ->first();

        // Load current assignment
        $currentAssignment = $person->campaignAssignments()
            ->with(['campaign', 'site', 'functionalRoleType'])
            ->whereIn('status', ['confirmed', 'arrived'])
            ->orderBy('created_at', 'desc')
            ->first();

        // Load assignment history (all assignments)
        $assignmentHistory = $person->campaignAssignments()
            ->with(['campaign', 'site', 'functionalRoleType'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($assignment) {
                return [
                    'id' => $assignment->id,
                    'status' => $assignment->status,
                    'campaign' => [
                        'id' => $assignment->campaign->id,
                        'name' => $assignment->campaign->name,
                    ],
                    'site' => $assignment->site ? [
                        'id' => $assignment->site->id,
                        'name' => $assignment->site->name,
                    ] : null,
                    'functional_role' => $assignment->functionalRoleType ? [
                        'id' => $assignment->functionalRoleType->id,
                        'label' => $assignment->functionalRoleType->label,
                    ] : null,
                    'expected_arrival' => $assignment->expected_arrival?->toIso8601String(),
                    'departure' => $assignment->departure?->toIso8601String(),
                ];
            })->toArray();

        return response()->json([
            'person' => [
                'id' => $person->id,
                'first_name' => $person->first_name,
                'middle_name' => $person->middle_name,
                'last_name' => $person->last_name,
                'preferred_name' => $person->preferred_name,
                'full_name' => $person->full_name,
                'gender' => $person->gender,
                'date_of_birth' => $person->date_of_birth,
                'nationality' => $person->nationality,
                'phone' => $person->phone,
                'whatsapp_phone' => $person->whatsapp_phone,
                'alternative_phone' => $person->alternative_phone,
                'email' => $person->email,
                'preferred_contact_method' => $person->preferred_contact_method,
                'emergency_contact_name' => $person->emergency_contact_name,
                'emergency_contact_relationship' => $person->emergency_contact_relationship,
                'emergency_contact_phone' => $person->emergency_contact_phone,
                'occupation' => $person->occupation,
                'photo_path' => $person->photo_path,
                'cover_photo_url' => $person->coverPhoto?->getUrl(),
            ],
            'missionary_profile' => $missionaryProfile ? [
                'person_id' => $missionaryProfile->person_id,
                'home_org_unit_id' => $missionaryProfile->home_org_unit_id,
                'deployment_status' => $missionaryProfile->deployment_status,
                'bio' => $missionaryProfile->bio,
                'training_notes' => $missionaryProfile->training_notes,
                'home_org_unit' => $missionaryProfile->homeOrgUnit ? [
                    'id' => $missionaryProfile->homeOrgUnit->id,
                    'name' => $missionaryProfile->homeOrgUnit->name,
                    'type' => [
                        'code' => $missionaryProfile->homeOrgUnit->type->code,
                        'label' => $missionaryProfile->homeOrgUnit->type->label,
                    ],
                ] : null,
                'skills' => $missionaryProfile->skills->map(fn ($skill) => [
                    'id' => $skill->id,
                    'skill_name' => $skill->skill_name,
                    'proficiency_level' => $skill->proficiency_level,
                ])->toArray(),
            ] : null,
            'addresses' => $person->addresses->map(fn ($address) => [
                'id' => $address->id,
                'country' => $address->country,
                'region' => $address->region,
                'district' => $address->district,
                'county_municipality' => $address->county_municipality,
                'parish_town' => $address->parish_town,
                'village_area' => $address->village_area,
                'physical_address' => $address->physical_address,
                'is_primary' => $address->is_primary,
            ])->toArray(),
            'skills' => $person->skills->map(fn ($skill) => [
                'id' => $skill->id,
                'skill_category' => $skill->skill_category,
                'skill_name' => $skill->skill_name,
                'proficiency_level' => $skill->proficiency_level,
                'years_experience' => $skill->years_experience,
            ])->toArray(),
            'languages' => $person->languages->map(fn ($lang) => [
                'id' => $lang->id,
                'language_code' => $lang->language_code,
                'language_name' => $lang->language ? $lang->language->name : $lang->language_code,
                'is_native' => $lang->is_native,
                'proficiency_level' => $lang->proficiency_level,
            ])->toArray(),
            'mission_experience' => $person->missionExperience->map(fn ($exp) => [
                'id' => $exp->id,
                'mission_name' => $exp->mission_name,
                'location' => $exp->location,
                'started_on' => $exp->started_on?->toIso8601String(),
                'ended_on' => $exp->ended_on?->toIso8601String(),
                'role' => $exp->role,
                'description' => $exp->description,
            ])->toArray(),
            'training' => $person->training->map(fn ($training) => [
                'id' => $training->id,
                'training_type' => $training->training_type,
                'training_name' => $training->training_name,
                'training_provider' => $training->training_provider,
                'completed_on' => $training->completed_on?->toIso8601String(),
                'expires_on' => $training->expires_on?->toIso8601String(),
                'certificate_number' => $training->certificate_number,
                'notes' => $training->notes,
            ])->toArray(),
            'org_memberships' => $orgMemberships->map(fn ($membership) => [
                'id' => $membership->id,
                'org_unit_id' => $membership->org_unit_id,
                'membership_type' => $membership->membership_type,
                'joined_on' => $membership->joined_on?->toIso8601String(),
                'ended_on' => $membership->ended_on?->toIso8601String(),
                'org_unit' => $membership->orgUnit ? [
                    'id' => $membership->orgUnit->id,
                    'name' => $membership->orgUnit->name,
                    'type' => [
                        'code' => $membership->orgUnit->type->code,
                        'label' => $membership->orgUnit->type->label,
                    ],
                ] : null,
            ])->toArray(),
            'current_office' => $currentOffice ? [
                'id' => $currentOffice->id,
                'org_unit_id' => $currentOffice->org_unit_id,
                'office_type_id' => $currentOffice->office_type_id,
                'held_from' => $currentOffice->held_from->toIso8601String(),
                'held_until' => $currentOffice->held_until?->toIso8601String(),
                'office_type' => $currentOffice->officeType ? [
                    'id' => $currentOffice->officeType->id,
                    'code' => $currentOffice->officeType->code,
                    'label' => $currentOffice->officeType->label,
                ] : null,
                'org_unit' => $currentOffice->orgUnit ? [
                    'id' => $currentOffice->orgUnit->id,
                    'name' => $currentOffice->orgUnit->name,
                ] : null,
            ] : null,
            'assignment' => $currentAssignment ? [
                'id' => $currentAssignment->id,
                'status' => $currentAssignment->status,
                'expected_arrival' => $currentAssignment->expected_arrival?->toIso8601String(),
                'actual_arrival' => $currentAssignment->actual_arrival?->toIso8601String(),
                'departure' => $currentAssignment->departure?->toIso8601String(),
                'campaign' => [
                    'id' => $currentAssignment->campaign->id,
                    'name' => $currentAssignment->campaign->name,
                    'status' => $currentAssignment->campaign->status,
                    'start_date' => $currentAssignment->campaign->start_date?->toIso8601String(),
                    'end_date' => $currentAssignment->campaign->end_date?->toIso8601String(),
                ],
                'site' => $currentAssignment->site ? [
                    'id' => $currentAssignment->site->id,
                    'name' => $currentAssignment->site->name,
                    'status' => $currentAssignment->site->status,
                ] : null,
                'functional_role' => $currentAssignment->functionalRoleType ? [
                    'id' => $currentAssignment->functionalRoleType->id,
                    'code' => $currentAssignment->functionalRoleType->code,
                    'label' => $currentAssignment->functionalRoleType->label,
                ] : null,
            ] : null,
            'assignment_history' => $assignmentHistory,
        ]);
    }

    /**
     * Update the authenticated user's missionary profile.
     *
     * PATCH /api/v1/workspace/my-profile
     *
     * Allows updating:
     *   - Bio
     *   - Training notes
     *   - Home organisation
     */
    public function updateMyProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        if (! $person) {
            return response()->json(['message' => 'No person profile found'], 404);
        }

        $validated = $request->validate([
            'bio' => ['sometimes', 'nullable', 'string'],
            'training_notes' => ['sometimes', 'nullable', 'string'],
            'home_org_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:org_units,id'],
        ]);

        // Get or create missionary profile
        $missionaryProfile = $person->missionaryProfile;
        if (! $missionaryProfile) {
            $missionaryProfile = $person->missionaryProfile()->create([
                'person_id' => $person->id,
                'home_org_unit_id' => $validated['home_org_unit_id'] ?? null,
                'deployment_status' => null,
                'bio' => $validated['bio'] ?? null,
                'training_notes' => $validated['training_notes'] ?? null,
            ]);
        } else {
            $missionaryProfile->update($validated);
        }

        return response()->json($missionaryProfile->fresh());
    }

    /**
     * Update the authenticated user's personal details.
     *
     * PATCH /api/v1/workspace/my-person
     *
     * Allows updating:
     *   - First name
     *   - Last name
     *   - Phone
     *   - Date of birth
     *   - Gender
     */
    public function updateMyPerson(Request $request): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        if (! $person) {
            return response()->json(['message' => 'No person profile found'], 404);
        }

        $validated = $request->validate([
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:male,female,unspecified'],
        ]);

        // Ensure at least one name is provided
        if (isset($validated['first_name']) && isset($validated['last_name'])) {
            if (empty($validated['first_name']) && empty($validated['last_name'])) {
                return response()->json(['message' => 'At least one name (first or last) is required'], 422);
            }
        }

        $person->update($validated);

        return response()->json($person->fresh());
    }

    /**
     * Add a language to the authenticated user's profile.
     *
     * POST /api/v1/workspace/my-languages
     *
     * Creates the language in the reference table if it doesn't exist,
     * then links it to the person with proficiency data.
     */
    public function addMyLanguage(Request $request): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        if (! $person) {
            return response()->json(['message' => 'No person profile found'], 404);
        }

        $validated = $request->validate([
            'language_name' => ['required', 'string', 'max:100'],
            'proficiency_level' => ['required', 'string', 'in:basic,conversational,fluent,native'],
            'is_native' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        // Generate a simple code from the language name (lowercase, max 10 chars)
        $languageCode = strtolower(substr($validated['language_name'], 0, 10));
        // Remove spaces and special characters
        $languageCode = preg_replace('/[^a-z0-9]/', '', $languageCode);

        // Find or create language in reference table
        $language = Language::firstOrCreate(
            ['code' => $languageCode],
            [
                'name' => $validated['language_name'],
                'native_name' => $validated['language_name'],
                'supports_translation' => false, // Default to false for user-added languages
                'supports_stt' => false,
                'supports_tts' => false,
                'is_active' => true,
            ]
        );

        // Create or update person language record
        $personLanguage = PersonLanguage::updateOrCreate(
            [
                'person_id' => $person->id,
                'language_code' => $language->code,
            ],
            [
                'is_native' => $validated['is_native'] ?? false,
                'proficiency_level' => $validated['proficiency_level'],
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json([
            'language' => $language,
            'person_language' => $personLanguage,
        ], 201);
    }

    /**
     * Remove a language from the authenticated user's profile.
     *
     * DELETE /api/v1/workspace/my-languages/{languageCode}
     */
    public function removeMyLanguage(Request $request, string $languageCode): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        if (! $person) {
            return response()->json(['message' => 'No person profile found'], 404);
        }

        $deleted = PersonLanguage::where('person_id', $person->id)
            ->where('language_code', $languageCode)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Language not found in your profile'], 404);
        }

        return response()->json(['message' => 'Language removed']);
    }

    /**
     * Add a skill to the authenticated user's profile.
     *
     * POST /api/v1/workspace/my-skills
     *
     * Creates the skill category in reference table if it doesn't exist,
     * then links the skill to the person.
     */
    public function addMySkill(Request $request): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        if (! $person) {
            return response()->json(['message' => 'No person profile found'], 404);
        }

        $validated = $request->validate([
            'skill_category' => ['required', 'string', 'max:100'],
            'skill_name' => ['required', 'string', 'max:150'],
            'proficiency_level' => ['required', 'string', 'in:beginner,intermediate,advanced,expert'],
            'years_experience' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        // Find or create skill category in reference table
        $skillCategory = SkillCategory::firstOrCreate(
            ['label' => $validated['skill_category']],
            [
                'code' => strtolower(substr(preg_replace('/[^a-z0-9]/', '', $validated['skill_category']), 0, 50)),
                'description' => null,
                'is_active' => true,
            ]
        );

        // Create person skill record
        $personSkill = PersonSkill::create([
            'person_id' => $person->id,
            'skill_category' => $skillCategory->label,
            'skill_name' => $validated['skill_name'],
            'proficiency_level' => $validated['proficiency_level'],
            'years_experience' => $validated['years_experience'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'skill_category' => $skillCategory,
            'person_skill' => $personSkill,
        ], 201);
    }

    /**
     * Remove a skill from the authenticated user's profile.
     *
     * DELETE /api/v1/workspace/my-skills/{skillId}
     */
    public function removeMySkill(Request $request, int $skillId): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        if (! $person) {
            return response()->json(['message' => 'No person profile found'], 404);
        }

        $deleted = PersonSkill::where('person_id', $person->id)
            ->where('id', $skillId)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Skill not found in your profile'], 404);
        }

        return response()->json(['message' => 'Skill removed']);
    }

    /**
     * Add mission experience to the authenticated user's profile.
     *
     * POST /api/v1/workspace/my-mission-experience
     */
    public function addMyMissionExperience(Request $request): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        if (! $person) {
            return response()->json(['message' => 'No person profile found'], 404);
        }

        $validated = $request->validate([
            'mission_name' => ['required', 'string', 'max:200'],
            'location' => ['sometimes', 'nullable', 'string', 'max:200'],
            'started_on' => ['sometimes', 'nullable', 'date'],
            'ended_on' => ['sometimes', 'nullable', 'date', 'after:started_on'],
            'role' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $experience = PersonMissionExperience::create([
            'person_id' => $person->id,
            ...$validated,
        ]);

        return response()->json($experience, 201);
    }

    /**
     * Remove mission experience from the authenticated user's profile.
     *
     * DELETE /api/v1/workspace/my-mission-experience/{experienceId}
     */
    public function removeMyMissionExperience(Request $request, int $experienceId): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        if (! $person) {
            return response()->json(['message' => 'No person profile found'], 404);
        }

        $deleted = PersonMissionExperience::where('person_id', $person->id)
            ->where('id', $experienceId)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Mission experience not found in your profile'], 404);
        }

        return response()->json(['message' => 'Mission experience removed']);
    }

    /**
     * Add training to the authenticated user's profile.
     *
     * POST /api/v1/workspace/my-training
     */
    public function addMyTraining(Request $request): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        if (! $person) {
            return response()->json(['message' => 'No person profile found'], 404);
        }

        $validated = $request->validate([
            'training_type' => ['required', 'string', 'max:100'],
            'training_name' => ['required', 'string', 'max:200'],
            'training_provider' => ['sometimes', 'nullable', 'string', 'max:200'],
            'completed_on' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date', 'after:completed_on'],
            'certificate_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $training = PersonTraining::create([
            'person_id' => $person->id,
            ...$validated,
        ]);

        return response()->json($training, 201);
    }

    /**
     * Remove training from the authenticated user's profile.
     *
     * DELETE /api/v1/workspace/my-training/{trainingId}
     */
    public function removeMyTraining(Request $request, int $trainingId): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        if (! $person) {
            return response()->json(['message' => 'No person profile found'], 404);
        }

        $deleted = PersonTraining::where('person_id', $person->id)
            ->where('id', $trainingId)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Training not found in your profile'], 404);
        }

        return response()->json(['message' => 'Training removed']);
    }

    /**
     * Upload profile photo for the authenticated user.
     *
     * POST /api/v1/workspace/my-profile-photo
     */
    public function uploadProfilePhoto(Request $request): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        if (! $person) {
            return response()->json(['message' => 'No person profile found'], 404);
        }

        $request->validate([
            'photo' => ['required', 'file', 'image', 'max:5120'], // Max 5MB
        ]);

        $file = $request->file('photo');

        // Generate unique filename
        $filename = 'profile-' . $person->id . '-' . time() . '.' . $file->getClientOriginalExtension();

        // Store file
        $path = $file->storeAs('profile-photos', $filename, 'public');

        // Create media record
        $media = Media::create([
            'uuid' => (string) \Str::uuid(),
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $filename,
            'mime_type' => $file->getMimeType(),
            'media_type' => 'image',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'file_size' => $file->getSize(),
            'width' => getimagesize($file)[0] ?? null,
            'height' => getimagesize($file)[1] ?? null,
            'uploaded_by_user_id' => $user->id,
            'visibility' => 'public',
            'processing_status' => 'completed',
        ]);

        // Update person
        $person->media_id = $media->id;
        $person->photo_path = $media->getUrl();
        $person->save();

        return response()->json([
            'message' => 'Profile photo uploaded',
            'photo_path' => $person->photo_path,
            'media' => $media,
        ]);
    }

    /**
     * Upload cover photo for the authenticated user.
     *
     * POST /api/v1/workspace/my-cover-photo
     */
    public function uploadCoverPhoto(Request $request): JsonResponse
    {
        $user = $request->user();
        $person = $user->person;

        if (! $person) {
            return response()->json(['message' => 'No person profile found'], 404);
        }

        $request->validate([
            'photo' => ['required', 'file', 'image', 'max:10240'], // Max 10MB
        ]);

        $file = $request->file('photo');

        // Generate unique filename
        $filename = 'cover-' . $person->id . '-' . time() . '.' . $file->getClientOriginalExtension();

        // Store file
        $path = $file->storeAs('cover-photos', $filename, 'public');

        // Create media record
        $media = Media::create([
            'uuid' => (string) \Str::uuid(),
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $filename,
            'mime_type' => $file->getMimeType(),
            'media_type' => 'image',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'file_size' => $file->getSize(),
            'width' => getimagesize($file)[0] ?? null,
            'height' => getimagesize($file)[1] ?? null,
            'uploaded_by_user_id' => $user->id,
            'visibility' => 'public',
            'processing_status' => 'completed',
        ]);

        // Update person
        $person->cover_photo_media_id = $media->id;
        $person->save();

        return response()->json([
            'message' => 'Cover photo uploaded',
            'cover_photo_url' => $media->getUrl(),
            'media' => $media,
        ]);
    }
}
