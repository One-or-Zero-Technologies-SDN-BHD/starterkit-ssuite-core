<?php

return [

    /*
    | Every app that shares the central database waits for this lock before
    | running migrations, so two deploys never alter the central schema at once.
    | The timeout is how long (seconds) a deploy waits before failing.
    */
    'central_migration_lock' => [
        'enabled' => env('SSUITE_CENTRAL_MIGRATION_LOCK', true),
        'connection' => env('SSUITE_CENTRAL_MIGRATION_LOCK_CONNECTION', 'central'),
        'timeout' => (int) env('SSUITE_CENTRAL_MIGRATION_LOCK_TIMEOUT', 600),
    ],

];
