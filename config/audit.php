<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audit log retention
    |--------------------------------------------------------------------------
    |
    | How long audit rows stay queryable in the database before audit:prune
    | archives them to CSV (storage/app/audit-archive) and deletes them.
    | Two years by default - long enough for chargeback disputes, provider
    | compliance reviews, and internal investigations, while keeping the
    | audit_logs table (and its indexes) from growing without bound.
    |
    */

    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 730),

];
