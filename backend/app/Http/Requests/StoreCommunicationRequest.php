<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreCommunicationRequest — validation for creating a communication.
 *
 * Scope integrity is validated here: exactly one scope reference must be
 * supplied for non-global scopes, and none for global.
 */
class StoreCommunicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorisation is handled by the policy/controller.
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'communication_type' => [
                'required',
                'string',
                'in:announcement,organisational_update,instruction',
            ],
            'scope_type' => ['required', 'string', 'in:global,org_unit,campaign,site,team'],
            'scoped_org_unit_id' => [
                'required_if:scope_type,org_unit',
                'nullable', 'integer', 'exists:org_units,id',
                'prohibited_unless:scope_type,org_unit',
            ],
            'scoped_campaign_id' => [
                'required_if:scope_type,campaign',
                'nullable', 'integer', 'exists:campaigns,id',
                'prohibited_unless:scope_type,campaign',
            ],
            'scoped_site_id' => [
                'required_if:scope_type,site',
                'nullable', 'integer', 'exists:mission_sites,id',
                'prohibited_unless:scope_type,site',
            ],
            'scoped_team_id' => [
                'required_if:scope_type,team',
                'nullable', 'integer', 'exists:teams,id',
                'prohibited_unless:scope_type,team',
            ],
            'requires_acknowledgement' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'status' => ['sometimes', 'string', 'in:draft,published'],
        ];
    }

    public function messages(): array
    {
        return [
            'scoped_org_unit_id.required_if' => 'An organisational unit is required for org-unit communications.',
            'scoped_campaign_id.required_if' => 'A campaign is required for campaign communications.',
            'scoped_site_id.required_if' => 'A mission site is required for site communications.',
            'scoped_team_id.required_if' => 'A team is required for team communications.',
        ];
    }
}
