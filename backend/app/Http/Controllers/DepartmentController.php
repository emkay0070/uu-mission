<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Department;
use App\Models\MissionSite;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DepartmentController — manages departments within campaigns and sites.
 *
 * Departments are operational groups (e.g., Youth Ministries, Women's Ministries)
 * scoped to campaigns or specific mission sites.
 */
class DepartmentController extends Controller
{
    public function __construct(private AuthorizationService $auth) {}

    /**
     * List departments for a campaign.
     * GET /api/v1/campaigns/{campaign}/departments
     */
    public function indexForCampaign(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('viewAny', [Department::class, $campaign]);

        $departments = Department::with(['leader', 'site'])
            ->where('campaign_id', $campaign->id)
            ->orderBy('name')
            ->get();

        return response()->json($departments);
    }

    /**
     * List departments for a site.
     * GET /api/v1/campaigns/{campaign}/sites/{site}/departments
     */
    public function indexForSite(Request $request, Campaign $campaign, MissionSite $site): JsonResponse
    {
        // Verify site belongs to campaign
        if ($site->campaign_id !== $campaign->id) {
            return response()->json(['message' => 'Site does not belong to this campaign'], 404);
        }

        $this->authorize('viewAny', [Department::class, $campaign]);

        $departments = Department::with(['leader'])
            ->where('campaign_id', $campaign->id)
            ->where('mission_site_id', $site->id)
            ->orderBy('name')
            ->get();

        return response()->json($departments);
    }

    /**
     * View a specific department.
     * GET /api/v1/departments/{department}
     */
    public function show(Department $department): JsonResponse
    {
        $this->authorize('view', $department);

        return response()->json($department->load(['leader', 'site', 'members.person']));
    }

    /**
     * Create a new department.
     * POST /api/v1/campaigns/{campaign}/departments
     */
    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('create', [Department::class, $campaign]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'mission_site_id' => ['nullable', 'integer', 'exists:mission_sites,id'],
            'leader_person_id' => ['nullable', 'integer', 'exists:people,id'],
        ]);

        // If site_id is provided, verify it belongs to the campaign
        if (!empty($validated['mission_site_id'])) {
            $site = MissionSite::find($validated['mission_site_id']);
            if (!$site || $site->campaign_id !== $campaign->id) {
                return response()->json(['message' => 'Site does not belong to this campaign'], 422);
            }
        }

        $department = Department::create([
            'campaign_id' => $campaign->id,
            'mission_site_id' => $validated['mission_site_id'] ?? null,
            'name' => $validated['name'],
            'leader_person_id' => $validated['leader_person_id'] ?? null,
        ]);

        return response()->json($department->load(['leader', 'site']), 201);
    }

    /**
     * Update a department.
     * PATCH /api/v1/departments/{department}
     */
    public function update(Request $request, Department $department): JsonResponse
    {
        $this->authorize('update', $department);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'leader_person_id' => ['nullable', 'integer', 'exists:people,id'],
        ]);

        $department->update($validated);

        return response()->json($department->load(['leader', 'site']));
    }

    /**
     * Delete a department.
     * DELETE /api/v1/departments/{department}
     */
    public function destroy(Department $department): JsonResponse
    {
        $this->authorize('delete', $department);

        $department->delete();

        return response()->json(['message' => 'Department deleted.']);
    }

    /**
     * Add a member to a department.
     * POST /api/v1/departments/{department}/members
     */
    public function addMember(Request $request, Department $department): JsonResponse
    {
        $this->authorize('update', $department);

        $validated = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
            'role_in_dept' => ['nullable', 'string', 'max:100'],
        ]);

        $member = \App\Models\DeptMember::create([
            'department_id' => $department->id,
            'person_id' => $validated['person_id'],
            'role_in_dept' => $validated['role_in_dept'] ?? null,
        ]);

        return response()->json($member->load('person'), 201);
    }

    /**
     * Remove a member from a department.
     * DELETE /api/v1/departments/{department}/members/{member}
     */
    public function removeMember(Department $department, \App\Models\DeptMember $member): JsonResponse
    {
        $this->authorize('update', $department);

        if ($member->department_id !== $department->id) {
            return response()->json(['message' => 'Member does not belong to this department'], 404);
        }

        $member->delete();

        return response()->json(['message' => 'Member removed from department.']);
    }
}
