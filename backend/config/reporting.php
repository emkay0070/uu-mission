<?php

/**
 * Reporting configuration.
 *
 * RECORDED AS CONFIGURATION, NOT AS A MAGIC NUMBER
 * ------------------------------------------------
 * The review window is the period a reviewer at one rung of the reporting
 * chain has to act before the report is forwarded onward with the delay
 * recorded. It is operational policy, not a code fact: a small campaign may
 * want a few hours, a large one a day. Putting it here means it can be tuned
 * per deployment without touching the review workflow.
 *
 * Forwarding on expiry is NEVER approval. The report advances so that higher
 * levels are not blocked indefinitely, while its review state continues to say
 * which rung still owes a review.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Review window
    |--------------------------------------------------------------------------
    |
    | How long, in hours, a reviewer has before an un-actioned review is
    | forwarded to the next level with the delay recorded. Applied when a
    | report is submitted (review_due_at = submitted_at + this many hours).
    |
    */
    'review_window_hours' => (int) env('REPORT_REVIEW_WINDOW_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Default report level
    |--------------------------------------------------------------------------
    |
    | The level assumed when a report is created without one. Site-level is the
    | historical default, so existing clients keep working unchanged.
    |
    */
    'default_level' => env('REPORT_DEFAULT_LEVEL', 'site'),

];
