<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * OutcomeType — configurable classification of spiritual results.
 *
 * Codes stored in mission_outcomes.outcome_type reference this table.
 * The FK (on pgsql) enforces referential integrity at the DB level.
 * On application startup, the codes are cached and used for validation
 * by MissionOutcomeController.
 */
class OutcomeType extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'label', 'description',
        'sort_order', 'triggers_follow_up',
        'include_in_stats', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'triggers_follow_up' => 'boolean',
            'include_in_stats'   => 'boolean',
            'is_active'          => 'boolean',
        ];
    }

    /** Return active codes as a flat array — used for validation. */
    public static function activeCodes(): array
    {
        return static::where('is_active', true)->pluck('code')->toArray();
    }
}
