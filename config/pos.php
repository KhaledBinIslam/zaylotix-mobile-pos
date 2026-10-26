<?php

return [
    // Above this many products, PosController::index() stops sending the
    // whole catalog as a page prop and switches to server-side search + a
    // background sync into the offline cache instead (see
    // PosController::FULL_CATALOG_LIMIT's own docblock and
    // docs/FIX_PLAN.md's scale-follow-up design note). Env-overridable so
    // this can be tuned without a deploy, and so a test can exercise the
    // 'search' path against a small, fast-to-seed product count.
    'full_catalog_limit' => (int) env('POS_FULL_CATALOG_LIMIT', 1500),

    // How many products PosController::catalogSyncChunk() returns per
    // call — the background sync (useOfflineCatalog.js) loops this
    // repeatedly until every product is cached. Same env-overridable
    // reasoning as above.
    'catalog_sync_chunk_size' => (int) env('POS_CATALOG_SYNC_CHUNK_SIZE', 500),
];
