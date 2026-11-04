<?php

namespace App\Http\Controllers;

use App\Domain\WorkflowDefinitions;
use App\Models\Campaign;
use App\Models\CampaignObjective;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CampaignObjectiveController — manages campaign objectives.
 *
 * Objectives are the specific goals a campaign aims to achieve.
 * They have a status workflow: pending → in_progress → achieved → dropped.
 */
class CampaignObjectiveController extends Controller
{
    /**
     * List objectives for a campaign.
     *
     * GET /api/v1/campaigns/{campaign}/objectives
     */
    public function index(Campaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $objectives = $campaign->objectives()->orderBy('sort_order')->get();

        return response()->json($objectives);
    }

    /**
     * Create a new objective.
     *
     * POST /api/v1/campaigns/{campaign}/objectives
     */
    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(['pending', 'in_progress', 'achieved', 'dropped'])],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $objective = $campaign->objectives()->create([
            ...$validated,
            'status' => $validated['status'] ?? 'pending',
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json($objective, 201);
    }

    /**
     * Update an objective.
     *
     * PATCH /api/v1/campaigns/{campaign}/objectives/{objective}
     */
    public function update(Request $request, Campaign $campaign, CampaignObjective $objective): JsonResponse
    {
        $this->validateObjectiveBelongsToCampaign($campaign, $objective);
        $this->authorize('update', $campaign);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:250'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:'.WorkflowDefinitions::validationIn(['pending', 'in_progress', 'achieved', 'dropped'])],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $objective->update($validated);

        return response()->json($objective->fresh());
    }

    /**
     * Delete an objective.
     *
     * DELETE /api/v1/campaigns/{campaign}/objectives/{objective}
     */
    public function destroy(Campaign $campaign, CampaignObjective $objective): JsonResponse
    {
        $this->validateObjectiveBelongsToCampaign($campaign, $objective);
        $this->authorize('update', $campaign);

        $objective->delete();

        return response()->json(['message' => 'Objective removed.'], 200);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function validateObjectiveBelongsToCampaign(Campaign $campaign, CampaignObjective $objective): void
    {
        if ($objective->campaign_id !== $campaign->id) {
            abort(404, 'Objective not found within this campaign.');
        }
    }
}
