<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateCommunicationRequest — validation for updating a communication.
 *
 * Only lifecycle and content fields may be changed; scope is immutable after
 * creation.
 */
class UpdateCommunicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorisation is handled by the policy/controller.
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'communication_type' => [
                'sometimes', 'string',
                'in:announcement,organisational_update,instruction',
            ],
            'requires_acknowledgement' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'status' => ['sometimes', 'string', 'in:draft,published,revoked'],
        ];
    }
}
