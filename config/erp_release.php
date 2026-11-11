<?php

return [
    /*
    | Legacy recovery postings
    |
    | These screens repair accounting for records that were created before the current
    | posting controls existed (historical batch capitalization, legacy FIFO cost
    | recovery, historical dispatch cost, supplier short-credit, historical customer
    | return cost). They post to the General Ledger and inventory valuation, and they
    | have no automated acceptance tests yet. A new installation has no such records,
    | so they are switched off unless explicitly enabled after dedicated testing.
    |
    | Opening-stock valuation and the correction case workflow itself are tested and
    | are not affected by this switch.
    */
    'legacy_recovery_postings' => (bool) env('ERP_LEGACY_RECOVERY_POSTINGS', false),
];
