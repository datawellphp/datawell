<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Data Sources
    |--------------------------------------------------------------------------
    |
    | The DataSource classes registered with Datawell. Each source is
    | identified on the wire by the literal key it declares; class names
    | are authoring sugar only and never appear in schemas or requests.
    |
    */

    'sources' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | The effective timezone resolves through a chain: the acting user's
    | HasTimezone contract, then an app-registered resolver, then this
    | value, then app.timezone. Leave null to fall through to the app.
    |
    */

    'timezone' => null,

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Page size defaults and ceilings (D39). The ceiling is stricter on
    | delegated channels — an agent acting for the user gets less per call.
    |
    */

    'page' => [
        'default' => 25,
        'max' => 100,
        'max_delegated' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Buckets
    |--------------------------------------------------------------------------
    |
    | Grouped requests return at most this many buckets, with an explicit
    | `truncated` flag when the cap is hit (D39) — never a silent cap.
    |
    */

    'buckets' => [
        'max' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    |
    | The write side (D40-D45). `chunk` rows per handler call unless the action
    | overrides or declares wholeSet; targets larger than `sync_limit` (or any
    | action declared queued) dispatch as a bus batch. Failure and skip lists
    | on reports are capped at `max_failures` with an explicit truncated flag.
    | `record` persists runs: 'queued' (tier 1) or 'always' (tier 2 audit
    | trail); rows prune after `retention_days`.
    |
    */

    'actions' => [
        'chunk' => 100,
        'sync_limit' => 100,
        'max_failures' => 50,
        'record' => 'queued',
        'retention_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Many-values
    |--------------------------------------------------------------------------
    |
    | A many-valued relation field carries at most this many references per
    | row, plus the total (D21). The remainder is retrievable through the
    | executor's values() primitive (D39) — never silently dropped.
    |
    */

    'values' => [
        'max' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Definition Lint
    |--------------------------------------------------------------------------
    |
    | Definitions are checked at boot so that wrong ones fail loudly at
    | authoring time. `enabled` null means "everywhere except production".
    | Warnings (a missing description, for example) are logged, thrown,
    | or ignored per `warnings`.
    |
    */

    'lint' => [
        'enabled' => null,
        'warnings' => 'log',
    ],

];
