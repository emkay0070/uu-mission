<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityObjective;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ActivityObjectiveController — the goals one field activity sets out to achieve.
 *
 * WHY ACTIVITY OBJECTIVES EXIST
 *   A site day usually runs several activities, and each activity has its own
 *   purpose (an evangelism meeting aims at decisions; a health outreach aims at
 *   screenings). Those purposes must be recorded ONCE, against the activity, and
 *   then be visible wherever the activity is used: when a register is opened,
 *   when outcomes are recorded, and when a report is compiled.
 *
 * WHAT THIS IS NOT
 *   An objective is a statement of intent, not a workflow object. It has no
 *   status and no approval. Achievements are recorded as Mission Outcomes, which
 *   already exist. This keeps the "what we planned" record separate from the
 *   "what happened" record, so neither can silently overwrite the other.
 *
 * AUTHORISATION
 *   Objectives belong to an activity, so they inherit the activity's own
 *   boundaries: reading requires `activities.view` at the activity, changing
 *   requires `activities.update`. No separate permission code is introduced —
 *   that would be a second authorisation path for the same thing.
 */
class ActivityObjectiveController extends Controller
{
    /**
     * List an activity's objectives, ordered for display.
     *
     * GET /api/v1/campaigns/{campaign}/activities/{activity}/objectives
     */
    public function index(Campaign $campaign, Activity $activity): JsonResponse
    {
        $this->validateActivityScope($campaign, $activity);
        $this->authorize('view', $activity);

        return response()->json($activity->objectives()->get());
    }

    /**
     * Add an objective to an activity.
     *
     * POST /api/v1/campaigns/{campaign}/activities/{activity}/objectives
     */
    public function store(Request $request, Campaign $campaign, Activity $activity): JsonResponse
    {
        $this->validateActivityScope($campaign, $activity);
        $this->authorize('update', $activity);

        $validated = $request->validate([
            'title'       => ['required', 'string', 'max:250'],
            'description' => ['sometimes', 'nullable', 'string'],
            'sort_order'  => ['sometimes', 'integer'],
        ]);

        // Default the display order to "next", so objectives added through a
        // simple form keep the order they were written in without the client
        // having to compute it.
        $nextOrder = ((int) $activity->objectives()->max('sort_order')) + 1;

        $objective = $activity->objectives()->create([
            ...$validated,
            'sort_order' => $validated['sort_order'] ?? $nextOrder,
        ]);

        return response()->json($objective, 201);
    }

    /**
     * Update an objective.
     *
     * PATCH /api/v1/campaigns/{campaign}/activities/{activity}/objectives/{objective}
     */
    public function update(
        Request $request,
        Campaign $campaign,
        Activity $activity,
        ActivityObjective $objective,
    ): JsonResponse {
        $this->validateActivityScope($campaign, $activity);
        $this->validateObjectiveBelongsToActivity($activity, $objective);
        $this->authorize('update', $activity);

        $validated = $request->validate([
            'title'       => ['sometimes', 'string', 'max:250'],
            'description' => ['sometimes', 'nullable', 'string'],
            'sort_order'  => ['sometimes', 'integer'],
        ]);

        $objective->update($validated);

        return response()->json($objective->fresh());
    }

    /**
     * Remove an objective.
     *
     * DELETE /api/v1/campaigns/{campaign}/activities/{activity}/objectives/{objective}
     */
    public function destroy(Campaign $campaign, Activity $activity, ActivityObjective $objective): JsonResponse
    {
        $this->validateActivityScope($campaign, $activity);
        $this->validateObjectiveBelongsToActivity($activity, $objective);
        $this->authorize('update', $activity);

        $objective->delete();

        return response()->json(['message' => 'Objective removed.']);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function validateActivityScope(Campaign $campaign, Activity $activity): void
    {
        if ((int) $activity->campaign_id !== (int) $campaign->id) {
            abort(404, 'Activity not found within this campaign.');
        }
    }

    private function validateObjectiveBelongsToActivity(Activity $activity, ActivityObjective $objective): void
    {
        if ((int) $objective->activity_id !== (int) $activity->id) {
            abort(404, 'Objective not found within this activity.');
        }
    }
}
