<?php

namespace App\Http\Controllers;

use App\Models\OrgUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrgHierarchyController extends Controller
{
    /**
     * Get full organisational hierarchy.
     *
     * GET /api/v1/org-hierarchy
     */
    public function index(Request $request): JsonResponse
    {
        $query = OrgUnit::query();

        if ($request->filled('type')) {
            $query->where('org_unit_type_id', $request->type);
        }

        $orgUnits = $query->orderBy('name')->get();

        // Build hierarchical structure
        $hierarchy = $this->buildHierarchy($orgUnits);

        return response()->json($hierarchy);
    }

    /**
     * Search org units by name.
     *
     * GET /api/v1/org-units/search
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2'],
        ]);

        $results = OrgUnit::where('name', 'like', '%' . $request->q . '%')
            ->orWhere('short_code', 'like', '%' . $request->q . '%')
            ->with('orgUnitType')
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json([
            'results' => $results->map(fn ($unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'short_code' => $unit->short_code,
                'type' => $unit->orgUnitType->name ?? null,
                'parent_id' => $unit->parent_id,
            ]),
        ]);
    }

    /**
     * Get org unit with full parent chain.
     *
     * GET /api/v1/org-units/{id}
     */
    public function show(Request $request, OrgUnit $orgUnit): JsonResponse
    {
        $orgUnit->load('orgUnitType');

        // Build parent chain
        $chain = collect([$orgUnit]);
        $current = $orgUnit;
        while ($current->parent_id) {
            $current = OrgUnit::with('orgUnitType')->find($current->parent_id);
            if ($current) {
                $chain->push($current);
            } else {
                break;
            }
        }

        // Get children
        $children = OrgUnit::where('parent_id', $orgUnit->id)
            ->with('orgUnitType')
            ->orderBy('name')
            ->get();

        return response()->json([
            'org_unit' => $orgUnit,
            'parent_chain' => $chain->reverse()->values(),
            'children' => $children,
        ]);
    }

    /**
     * Build hierarchical structure from flat org units.
     */
    private function buildHierarchy($orgUnits): array
    {
        $grouped = $orgUnits->groupBy('parent_id');
        $rootUnits = $grouped->get(null, collect());

        $buildTree = function ($units) use (&$buildTree, $grouped) {
            return $units->map(function ($unit) use (&$buildTree, $grouped) {
                $children = $grouped->get($unit->id, collect());
                return [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'short_code' => $unit->short_code,
                    'parent_id' => $unit->parent_id,
                    'org_unit_type_id' => $unit->org_unit_type_id,
                    'children' => $buildTree($children),
                ];
            })->toArray();
        };

        return $buildTree($rootUnits);
    }
}
