<?php

namespace App\Services;

use App\Models\OrgMembership;
use App\Models\OrgUnit;
use App\Models\SystemRole;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * MissionaryBaselineProvisioner — provisions the baseline missionary role
 * when a local-church organisational affiliation is verified.
 *
 * The baseline missionary role grants ordinary missionary access
 * (resources.view, resources.create, etc.) at the verified local-church scope.
 * It does NOT grant organisational administration, role assignment authority,
 * campaign/site/team management, or resource approval/publishing authority.
 *
 * Provisioning is idempotent: repeated calls for the same user at the same
 * local-church scope do not create duplicate active assignments.
 *
 * The automatic-verification policy is read from config('missionary').
 * When auto-verification is disabled, provisioning only occurs after an
 * authorised approval sets the OrgMembership to 'approved'.
 */
class MissionaryBaselineProvisioner
{
    public function provisionIfVerified(OrgMembership $membership): void
    {
        // Only provision for local-church affiliations
        if (! $this->isLocalChurch($membership->org_unit_id)) {
            return;
        }

        // Only provision when the affiliation is verified (approved)
        if ($membership->approval_status !== 'approved') {
            return;
        }

        $person = $membership->person;
        if (! $person || ! $person->user_id) {
            return;
        }

        $user = User::find($person->user_id);
        if (! $user || $user->status !== 'active') {
            return;
        }

        $role = SystemRole::where('code', 'missionary')->first();
        if (! $role || ! $role->is_active || ! $role->current_permission_version_id) {
            return;
        }

        DB::transaction(function () use ($user, $role, $membership) {
            // Idempotent: skip if an active baseline missionary assignment
            // already exists at this local-church scope.
            $existing = UserRoleAssignment::where('user_id', $user->id)
                ->where('system_role_id', $role->id)
                ->where('scope_type', 'org_unit')
                ->where('scoped_org_unit_id', $membership->org_unit_id)
                ->where('status', 'active')
                ->first();

            if ($existing) {
                return;
            }

            // When the home church changes, end any previous baseline
            // missionary assignment at a different LOCAL-CHURCH scope so the
            // user does not accumulate conflicting baseline assignments.
            //
            // Only assignments this provisioner could itself have created are
            // candidates for revocation: baseline missionary assignments live
            // exclusively at local-church scopes (provisioning is gated by
            // isLocalChurch()). Separately authorised higher-level
            // appointments (UUM, conference, field, district, zone) are
            // org_unit-scoped but NOT local-church-scoped, so they are never
            // touched here. Campaign, site and team appointments use a
            // different scope_type and are likewise never touched.
            // History is preserved: assignments are marked 'revoked', never
            // deleted. Note: 'revoked' is used because the DB check
            // constraint (ura_status_check) only permits 'active',
            // 'suspended' and 'revoked' — 'ended' is not allowed.
            UserRoleAssignment::where('user_id', $user->id)
                ->where('system_role_id', $role->id)
                ->where('scope_type', 'org_unit')
                ->where('scoped_org_unit_id', '!=', $membership->org_unit_id)
                ->where('status', 'active')
                ->join('org_units', 'org_units.id', '=', 'user_role_assignments.scoped_org_unit_id')
                ->join('org_unit_types', 'org_unit_types.id', '=', 'org_units.org_unit_type_id')
                ->where('org_unit_types.code', 'local_church')
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                ]);

            UserRoleAssignment::create([
                'user_id' => $user->id,
                'system_role_id' => $role->id,
                'scope_type' => 'org_unit',
                'scoped_org_unit_id' => $membership->org_unit_id,
                'role_permission_version_id' => $role->current_permission_version_id,
                'status' => 'active',
                'valid_from' => now()->toDateString(),
            ]);
        });
    }

    /**
     * Determine whether automatic home-affiliation verification is enabled
     * for the current rollout.
     *
     * When false, onboarding creates OrgMembership records as 'pending' and
     * the baseline role is only provisioned after authorised approval.
     */
    public function autoVerifyHomeAffiliation(): bool
    {
        return (bool) Config::get('missionary.auto_verify_home_affiliation', true);
    }

    private function isLocalChurch(int $orgUnitId): bool
    {
        $orgUnit = OrgUnit::with('type')->find($orgUnitId);

        return $orgUnit !== null
            && $orgUnit->type !== null
            && $orgUnit->type->code === 'local_church';
    }
}
