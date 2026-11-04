<?php

use GP247\Core\Support\PreChange\PreChange;
use GP247\Core\Support\PreChange\PreChangeGate;
use GP247\Core\Support\PreChange\PreChangeOutcome;

if (!function_exists('gp247_pre_change') && !in_array('gp247_pre_change', config('gp247_functions_except', []))) {
    /**
     * Ask the "before change" registrants whether a risky change may run.
     *
     * @param string               $operation e.g. PreChange::SYSTEM_UPDATE, PreChange::EXTENSION_UPDATE.
     * @param array<string, mixed> $scope     What the change touches.
     * @param string|null          $from      Version / value before the change.
     * @param string|null          $to        Version / value after the change.
     * @return PreChangeOutcome
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-pre-change-hook
     * @aidlc-adr system-cli_pre-change-registry
     */
    function gp247_pre_change(string $operation, array $scope = [], ?string $from = null, ?string $to = null): PreChangeOutcome
    {
        return (new PreChangeGate)->run(new PreChange($operation, $scope, $from, $to));
    }
}
