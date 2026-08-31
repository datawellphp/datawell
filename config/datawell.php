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
