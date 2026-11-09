<?php

namespace App\Http\Controllers;

use App\Models\OrgMembership;
use App\Models\Person;
use App\Services\MissionaryBaselineProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrgMembershipController extends Controller
{
    public function __construct(private MissionaryBaselineProvisioner $baselineProvisioner) {}

    /**
     * List org memberships for a person.
     *
     * GET /api/v1/people/{person}/org-memberships
     */
    public function index(Request $request, Person $person): JsonResponse
    {
        $memberships = $person->orgMemberships()->with(['orgUnit', 'approvedBy'])->get();

        return response()->json($memberships);
    }

    /**
     * Create a new org membership.
     *
     * POST /api/v1/org-memberships
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', OrgMembership::class);

        $request->validate([
            'person_id' => ['required', 'exists:people,id'],
            'org_unit_id' => ['required', 'exists:org_units,id'],
            'membership_type' => ['required', 'in:member,associate,partner'],
            'joined_on' => ['sometimes', 'date'],
            'membership_number' => ['sometimes', 'string', 'max:50'],
            'baptism_date' => ['sometimes', 'date'],
            'baptism_location' => ['sometimes', 'string', 'max:200'],
        ]);

        // Check for duplicate membership
        $existing = OrgMembership::where('person_id', $request->person_id)
            ->where('org_unit_id', $request->org_unit_id)
            ->whereNull('ended_on')
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'org_unit_id' => ['Person already has an active membership with this org unit.'],
            ]);
        }

        $membership = OrgMembership::create([
            'person_id' => $request->person_id,
            'org_unit_id' => $request->org_unit_id,
            'membership_type' => $request->membership_type,
            'joined_on' => $request->input('joined_on', now()->toDateString()),
            'membership_number' => $request->membership_number,
            'baptism_date' => $request->baptism_date,
            'baptism_location' => $request->baptism_location,
            'approval_status' => 'pending',
        ]);

        return response()->json($membership->load(['person', 'orgUnit']), 201);
    }

    /**
     * Update an org membership.
     *
     * PATCH /api/v1/org-memberships/{id}
     */
    public function update(Request $request, OrgMembership $membership): JsonResponse
    {
        $this->authorize('update', $membership);

        $request->validate([
            'membership_type' => ['sometimes', 'in:member,associate,partner'],
            'ended_on' => ['sometimes', 'date'],
            'membership_number' => ['sometimes', 'string', 'max:50'],
            'baptism_date' => ['sometimes', 'date'],
            'baptism_location' => ['sometimes', 'string', 'max:200'],
            'approval_status' => ['sometimes', 'in:pending,approved,rejected'],
            'rejection_reason' => ['sometimes', 'string'],
        ]);

        $membership->update($request->only([
            'membership_type',
            'ended_on',
            'membership_number',
            'baptism_date',
            'baptism_location',
            'approval_status',
            'rejection_reason',
        ]));

        // Set approval metadata if status changed to approved
        if ($request->filled('approval_status') && $request->approval_status === 'approved') {
            $membership->update([
                'approved_by_user_id' => $request->user()->id,
                'approved_at' => now(),
            ]);
        }

        // Provision baseline missionary role for verified local-church affiliation
        if ($request->filled('approval_status') && $request->approval_status === 'approved') {
            $this->baselineProvisioner->provisionIfVerified($membership);
        }

        return response()->json($membership->load(['person', 'orgUnit', 'approvedBy']));
    }

    /**
     * Approve an org membership.
     *
     * POST /api/v1/org-memberships/{id}/approve
     */
    public function approve(Request $request, OrgMembership $membership): JsonResponse
    {
        $this->authorize('approve', $membership);

        if ($membership->approval_status === 'approved') {
            return response()->json(['message' => 'Membership is already approved.'], 400);
        }

        $membership->update([
            'approval_status' => 'approved',
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
        ]);

        // Provision baseline missionary role for verified local-church affiliation
        $this->baselineProvisioner->provisionIfVerified($membership);

        return response()->json($membership->load(['person', 'orgUnit', 'approvedBy']));
    }

    /**
     * Reject an org membership.
     *
     * POST /api/v1/org-memberships/{id}/reject
     */
    public function reject(Request $request, OrgMembership $membership): JsonResponse
    {
        $this->authorize('reject', $membership);

        $request->validate([
            'rejection_reason' => ['required', 'string'],
        ]);

        if ($membership->approval_status === 'rejected') {
            return response()->json(['message' => 'Membership is already rejected.'], 400);
        }

        $membership->update([
            'approval_status' => 'rejected',
            'rejection_reason' => $request->rejection_reason,
        ]);

        return response()->json($membership->load(['person', 'orgUnit']));
    }

    /**
     * Delete an org membership.
     *
     * DELETE /api/v1/org-memberships/{id}
     */
    public function destroy(OrgMembership $membership): JsonResponse
    {
        $membership->delete();

        return response()->json(['message' => 'Membership deleted successfully.']);
    }
}
