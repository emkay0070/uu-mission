<?php

namespace App\Models;

use App\Services\AuthorizationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemRole extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['code', 'label', 'scope_type', 'is_active', 'current_permission_version_id'];

    protected static function booted(): void
    {
        static::created(function (self $role): void {
            if (! Schema::hasTable('role_scope_compatibilities')) {
                return;
            }
            $scopes = match ($role->scope_type) {
                'global' => ['global'],
                'org_unit' => ['org_unit'],
                'campaign' => ['org_unit', 'campaign'],
                'site' => ['org_unit', 'campaign', 'site'],
                'team' => ['org_unit', 'campaign', 'site', 'team'],
                default => [$role->scope_type],
            };
            foreach ($scopes as $scope) {
                DB::table('role_scope_compatibilities')->insertOrIgnore([
                    'system_role_id' => $role->id,
                    'scope_type' => $scope,
                ]);
            }
        });
        static::updated(function (self $role): void {
            if ($role->wasChanged('is_active') && app()->bound(AuthorizationService::class)) {
                $role->roleAssignments()->with('user')->get()->each(function (UserRoleAssignment $assignment): void {
                    if ($assignment->user) {
                        app(AuthorizationService::class)->clearUserCache($assignment->user);
                    }
                });
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id');
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    public function currentPermissionVersion(): BelongsTo
    {
        return $this->belongsTo(RolePermissionVersion::class, 'current_permission_version_id');
    }

    public function permissionVersions(): HasMany
    {
        return $this->hasMany(RolePermissionVersion::class);
    }
}
