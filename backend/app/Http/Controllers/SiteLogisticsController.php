<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\MissionSite;
use App\Models\SiteLogistics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SiteLogisticsController — manages logistics arrangements for mission sites.
 *
 * Logistics include venue, transport, accommodation, materials, and budget tracking.
 * This is intentionally simple — focused on operational readiness rather than
 * full inventory or procurement management.
 */
class SiteLogisticsController extends Controller
{
    /**
     * Get logistics for a site.
     *
     * GET /api/v1/campaigns/{campaign}/sites/{site}/logistics
     */
    public function show(Campaign $campaign, MissionSite $site): JsonResponse
    {
        $this->validateSiteBelongsToCampaign($campaign, $site);
        $this->authorize('view', $site);

        $logistics = $site->logistics ?? new SiteLogistics(['mission_site_id' => $site->id]);

        return response()->json($logistics->load('responsiblePerson'));
    }

    /**
     * Create or update logistics for a site.
     *
     * PUT /api/v1/campaigns/{campaign}/sites/{site}/logistics
     */
    public function upsert(Request $request, Campaign $campaign, MissionSite $site): JsonResponse
    {
        $this->validateSiteBelongsToCampaign($campaign, $site);
        $this->authorize('update', $site);

        $validated = $request->validate([
            'venue_name' => ['sometimes', 'nullable', 'string', 'max:250'],
            'venue_address' => ['sometimes', 'nullable', 'string'],
            'venue_contact' => ['sometimes', 'nullable', 'string', 'max:200'],
            'venue_confirmed' => ['sometimes', 'boolean'],
            'transport_arrangements' => ['sometimes', 'nullable', 'string'],
            'transport_confirmed' => ['sometimes', 'boolean'],
            'accommodation_details' => ['sometimes', 'nullable', 'string'],
            'accommodation_confirmed' => ['sometimes', 'boolean'],
            'materials_needed' => ['sometimes', 'nullable', 'string'],
            'materials_status' => ['sometimes', 'nullable', 'string'],
            'budget_allocated' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'budget_spent' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'responsible_person_id' => ['sometimes', 'nullable', 'integer', 'exists:people,id'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $logistics = $site->logistics ?? new SiteLogistics(['mission_site_id' => $site->id]);
        $logistics->fill($validated);
        $logistics->save();

        return response()->json($logistics->load('responsiblePerson'));
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function validateSiteBelongsToCampaign(Campaign $campaign, MissionSite $site): void
    {
        if ($site->campaign_id !== $campaign->id) {
            abort(404, 'Site not found within this campaign.');
        }
    }
}
