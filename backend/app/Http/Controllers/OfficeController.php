<?php

namespace App\Http\Controllers;

use App\Models\OfficeHeld;
use App\Models\OfficeType;
use App\Models\OrgUnit;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OfficeController — manage organisational office types and appointments.
 */
class OfficeController extends Controller
{
    /**
     * List all office types.
     * GET /api/v1/office-types
     */
    public function indexOfficeTypes(): JsonResponse
    {
        $officeTypes = OfficeType::with('orgUnitType')
            ->where('is_active', true)
            ->orderBy('label')
            ->get();

        return response()->json($officeTypes);
    }

    /**
     * List offices held for an organisational unit.
     * GET /api/v1/org-units/{orgUnit}/offices
     */
    public function indexForOrgUnit(Request $request, OrgUnit $orgUnit): JsonResponse
    {
        $this->authorize('viewAnyForOrgUnit', [OfficeHeld::class, $orgUnit]);

        $offices = OfficeHeld::with(['person', 'officeType', 'orgUnit'])
            ->where('org_unit_id', $orgUnit->id)
            ->orderBy('held_from', 'desc')
            ->get();

        return response()->json($offices);
    }

    /**
     * List offices held by a person.
     * GET /api/v1/people/{person}/offices
     */
    public function indexForPerson(Request $request, Person $person): JsonResponse
    {
        $this->authorize('viewAnyForPerson', OfficeHeld::class);

        $offices = OfficeHeld::with(['officeType', 'orgUnit'])
            ->where('person_id', $person->id)
            ->orderBy('held_from', 'desc')
            ->get();

        return response()->json($offices);
    }

    /**
     * Create a new office appointment.
     * POST /api/v1/offices
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
            'org_unit_id' => ['required', 'integer', 'exists:org_units,id'],
            'office_type_id' => ['required', 'integer', 'exists:office_types,id'],
            'held_from' => ['required', 'date'],
            'held_until' => ['nullable', 'date', 'after:held_from'],
        ]);

        $this->authorize('create', [OfficeHeld::class, $validated]);

        $office = OfficeHeld::create($validated);

        return response()->json($office->load(['person', 'officeType', 'orgUnit']), 201);
    }

    /**
     * View a specific office appointment.
     * GET /api/v1/offices/{office}
     */
    public function show(OfficeHeld $office): JsonResponse
    {
        $this->authorize('view', $office);

        return response()->json($office->load(['person', 'officeType', 'orgUnit']));
    }

    /**
     * Update an office appointment (e.g., end date).
     * PATCH /api/v1/offices/{office}
     */
    public function update(Request $request, OfficeHeld $office): JsonResponse
    {
        $this->authorize('update', $office);

        $validated = $request->validate([
            'held_from' => ['nullable', 'date'],
            'held_until' => ['nullable', 'date', 'after:held_from'],
        ]);

        $office->update($validated);

        return response()->json($office->load(['person', 'officeType', 'orgUnit']));
    }

    /**
     * Delete an office appointment.
     * DELETE /api/v1/offices/{office}
     */
    public function destroy(OfficeHeld $office): JsonResponse
    {
        $this->authorize('delete', $office);

        $office->delete();

        return response()->json(['message' => 'Office appointment deleted.']);
    }
}
