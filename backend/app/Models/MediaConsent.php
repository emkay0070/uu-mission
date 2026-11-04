<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MediaConsent — consent and safeguarding records for media.
 *
 * Tracks consent for identifiable individuals, particularly children
 * and vulnerable people, to ensure proper publication permissions.
 */
class MediaConsent extends Model
{
    protected $fillable = [
        'media_id',
        'subject_name',
        'subject_relationship',
        'consent_type',
        'is_minor',
        'guardian_name',
        'consent_details',
        'consent_date',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_minor' => 'boolean',
            'consent_date' => 'date',
        ];
    }

    /**
     * Media this consent applies to.
     */
    public function media()
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * User who recorded this consent.
     */
    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
