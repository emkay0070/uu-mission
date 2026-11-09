<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ResourceCategory — manageable categories for the missionary resource library.
 *
 * Categories like Bible Studies, Evangelism, Mission Training, etc.
 * Configurable through the backend without code changes.
 */
class ResourceCategory extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'label',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Resources belonging to this category.
     */
    public function resources()
    {
        return $this->hasMany(Resource::class, 'category_id');
    }

    /**
     * Scope: only active categories.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: ordered by sort_order ascending.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
