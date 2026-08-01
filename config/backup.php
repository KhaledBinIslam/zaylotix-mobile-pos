<?php

return [
    // Which filesystem disk (see config/filesystems.php) `zaylotix:backup` writes to.
    // Point this at 's3' in production and configure the S3 credentials in .env.
    'disk' => env('BACKUP_DISK', 'local'),
];
