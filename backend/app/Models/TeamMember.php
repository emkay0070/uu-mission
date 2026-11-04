<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamMember extends Model
{
    public $timestamps = false;

    protected $fillable = ['team_id', 'person_id', 'role_in_team', 'joined_on', 'ended_on'];

    protected function casts(): array
    {
        return [
            'joined_on'  => 'date',
            'ended_on'   => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function team(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function person(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
