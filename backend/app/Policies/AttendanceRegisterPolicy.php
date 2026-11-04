<?php

namespace App\Policies;

use App\Models\AttendanceRegister;
use App\Models\User;
use App\Services\AuthorizationService;

/**
 * AttendanceRegisterPolicy — authorization for attendance register management.
 *
 * An attendance register always belongs to exactly one campaign and one
 * mission site. Permission checks therefore resolve the register's real
 * (campaign, site) position through AuthorizationService so that a site
 * secretary at Site A cannot read or edit Site B's registers.
 *
 * `attendance.submit` counts as view access because a missionary must be able
 * to open the register they are expected to fill in — the seeded missionary
 * role holds attendance.submit but not attendance.view.
 */
class AttendanceRegisterPolicy
{
    /** Codes that grant read access to attendance data. */
    private const VIEW_PERMISSIONS = ['attendance.view', 'attendance.submit'];

    /** Codes that grant the ability to record/submit attendance. */
    private const SUBMIT_PERMISSIONS = ['attendance.submit'];

    /**
     * Codes that grant the ability to verify a register.
     *
     * Verification is a distinct, supervisory act — it closes a register for
     * correction — so it is NOT implied by attendance.submit. A missionary may
     * fill a register in but may not certify it.
     */
    private const VERIFY_PERMISSIONS = ['attendance.verify'];

    public function __construct(private AuthorizationService $auth) {}

    /**
     * Listing registers requires a view permission in at least one scope.
     * The controller constrains the result set to those scopes.
     */
    public function viewAny(User $user): bool
    {
        return $this->auth->getAuthorisedScopes($user, self::VIEW_PERMISSIONS) !== [];
    }

    public function view(User $user, AttendanceRegister $register): bool
    {
        return $this->canAccessRegister($user, $register, self::VIEW_PERMISSIONS);
    }

    /**
     * Creating a register requires a submit permission in at least one scope;
     * the controller verifies the specific campaign/site that was supplied.
     */
    public function create(User $user): bool
    {
        return $this->auth->getAuthorisedScopes($user, self::SUBMIT_PERMISSIONS) !== [];
    }

    public function update(User $user, AttendanceRegister $register): bool
    {
        // Submitted and verified registers are immutable through this endpoint.
        if ($register->status === 'submitted' || $register->status === 'verified') {
            return false;
        }

        return $this->canAccessRegister($user, $register, self::SUBMIT_PERMISSIONS);
    }

    public function delete(User $user, AttendanceRegister $register): bool
    {
        // For now, we don't allow deleting registers directly.
        return false;
    }

    /**
     * Verifying a register requires the supervisory verify permission at the
     * register's site (or campaign-wide). Deliberately narrower than submit.
     */
    public function verify(User $user, AttendanceRegister $register): bool
    {
        return $this->canAccessRegister($user, $register, self::VERIFY_PERMISSIONS);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * A register is accessible when the user holds any of the given
     * permissions at the register's site, or campaign-wide.
     *
     * @param  array<int, string>  $permissions
     */
    private function canAccessRegister(User $user, AttendanceRegister $register, array $permissions): bool
    {
        $site     = $register->site;
        $campaign = $register->campaign;

        if ($site !== null && $this->auth->hasPermissionForSite($user, $permissions, $site)) {
            return true;
        }

        return $campaign !== null && $this->auth->hasPermissionForCampaign($user, $permissions, $campaign);
    }
}
