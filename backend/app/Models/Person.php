<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Person — the human being behind every platform interaction.
 *
 * Decoupled from User: a person can exist without an account.
 * emergency_contact_encrypted is encrypted at the application layer before storage.
 */
class Person extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'registered_by_user_id', 'first_name', 'middle_name', 'last_name', 'preferred_name', 'gender',
        'date_of_birth', 'nationality', 'phone', 'whatsapp_phone', 'alternative_phone', 'email', 'preferred_contact_method',
        'photo_path', 'media_id', 'cover_photo_media_id', 'emergency_contact_encrypted', 'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone',
        'address', 'occupation', 'national_id_type', 'national_id_number',
    ];

    protected $appends = ['full_name'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    /** Full name helper. */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function missionaryProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(MissionaryProfile::class);
    }

    public function orgMemberships(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrgMembership::class);
    }

    public function campaignAssignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CampaignAssignment::class);
    }

    public function teamMemberships(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function activityParticipations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ActivityParticipant::class);
    }

    public function officesHeld(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OfficeHeld::class);
    }

    public function attendanceEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AttendanceEntry::class);
    }

    public function registeredBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    public function outreachEncounters(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OutreachEncounter::class);
    }

    public function baptismRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BaptismRecord::class);
    }

    public function outreachContact(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(OutreachContact::class);
    }

    public function addresses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PersonAddress::class);
    }

    public function skills(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PersonSkill::class);
    }

    public function languages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PersonLanguage::class);
    }

    public function missionExperience(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PersonMissionExperience::class);
    }

    public function training(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PersonTraining::class);
    }

    public function mediaAttachments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'attachable');
    }

    public function profilePhoto(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    public function coverPhoto(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_photo_media_id');
    }


}
