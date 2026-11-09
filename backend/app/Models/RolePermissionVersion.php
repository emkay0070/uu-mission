<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RolePermissionVersion extends Model
{
    protected $fillable = ['system_role_id', 'version', 'created_by_user_id', 'change_reason', 'published_at'];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('published_at') !== null) {
                throw new \LogicException('Published role permission versions are immutable.');
            }
        });
        static::deleting(function (self $version): void {
            if ($version->published_at !== null) {
                throw new \LogicException('Published role permission versions cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(SystemRole::class, 'system_role_id');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission_version_items', 'role_permission_version_id', 'permission_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class, 'role_permission_version_id');
    }
}
