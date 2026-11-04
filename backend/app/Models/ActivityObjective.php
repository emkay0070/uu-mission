<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ActivityObjective — what one field activity sets out to achieve.
 *
 * This is a lightweight statement of intent owned by a single Activity: a
 * title, an optional description and a display order. It is intentionally NOT a
 * workflow object — achievement is recorded through a Mission Outcome, never by
 * advancing an objective's "status".
 *
 * Objectives are read wherever the activity's purpose matters: while taking an
 * activity's attendance, while recording outcomes, and while a report is being
 * prepared.
 */
class ActivityObjective extends Model
{
    protected $fillable = [
        'activity_id', 'title', 'description', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
