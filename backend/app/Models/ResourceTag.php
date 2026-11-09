<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ResourceTag — tags for categorizing and searching resources.
 *
 * Flexible tagging system allowing multiple tags per resource.
 */
class ResourceTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'tag',
    ];

    public $timestamps = false;

    /**
     * The resource this tag belongs to.
     */
    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }
}
