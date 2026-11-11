<?php

namespace App\Http\Controllers\Concerns;

/**
 * Refuses the legacy recovery posting actions listed by the controller unless
 * config('erp_release.legacy_recovery_postings') is enabled. See config/erp_release.php.
 */
trait BlocksUnverifiedLegacyRecovery
{
    public function callAction($method, $parameters)
    {
        if (in_array($method, static::UNVERIFIED_LEGACY_ACTIONS, true) && ! config('erp_release.legacy_recovery_postings')) {
            abort(403, 'Legacy accounting recovery is switched off in this release because it has not passed acceptance testing. No record was changed.');
        }

        return $this->{$method}(...array_values($parameters));
    }
}
