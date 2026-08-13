<?php

/*
 * App overrides for saad/ai-kit — the package deep-merges these over its
 * defaults, so only deviations live here.
 */
return [

    'usage' => [
        /*
         * Dual-write transition (M2): ai-kit records every turn into
         * ai_usage_events, while SpendLedger keeps draining the spend
         * collector itself to write the legacy ai_usage rows. Once a clean
         * week of both ledgers reconciling against OpenRouter passes, flip
         * this to true and retire SpendLedger's captureContextCosts() path.
         */
        'drain_spend' => false,
    ],

];
