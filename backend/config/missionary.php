<?php

/**
 * Missionary baseline role provisioning configuration.
 *
 * This file controls the rollout policy for automatic missionary home
 * organisational affiliation verification and baseline role assignment.
 *
 * The policy is centrally controlled here so that a future rollout can
 * switch from automatic verification to mandatory authorised approval
 * without redesigning onboarding, organisational membership, or role
 * assignments.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Automatic home-affiliation verification
    |--------------------------------------------------------------------------
    |
    | When true, a missionary's declared home local-church affiliation during
    | onboarding is automatically treated as verified for the current rollout.
    | The OrgMembership is created 'approved' and the baseline Missionary role
    | is provisioned at the local-church scope immediately.
    |
    | When false, OrgMembership records created during onboarding are created
    | 'pending' and require authorised approval before the baseline Missionary
    | role is provisioned. This accommodates a future mandatory-verification
    | rollout without code changes.
    |
    | Applies ONLY to the home local-church affiliation and the baseline
    | Missionary role assignment. Other organisational appointments
    | (secretary, site coordinator, team leader, campaign roles) continue to
    | follow their existing separate authorisation processes regardless of
    | this setting.
    */
    'auto_verify_home_affiliation' => true,

];
