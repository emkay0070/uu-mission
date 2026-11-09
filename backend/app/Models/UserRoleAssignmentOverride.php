<?php

namespace App\Models;

use App\Services\AuthorizationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRoleAssignmentOverride extends Model
{
    protected $fillable = ['user_role_assignment_id', 'permission_id', 'effect', 'created_by_user_id', 'reason'];

    protected static function booted(): void
    {
        $clear = function (self $override): void {
            if (app()->bound(AuthorizationService::class)) {
                $assignment = UserRoleAssignment::find($override->user_role_assignment_id);
                if ($assignment) {
                    app(AuthorizationService::class)->clearUserCache($assignment->user);
                }
            }
        };
        static::saved($clear);
        static::deleted($clear);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(UserRoleAssignment::class, 'user_role_assignment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
