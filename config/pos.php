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

    // Kill switch for the 2026-11-23 pooled-creation hooks (a product or
    // variant created in any shop of a shared_stock_pool group appears in
    // every member immediately — Product/ProductVariant::created()).
    // POOL_AUTO_ADOPT=false in .env turns the hooks off without a deploy;
    // creations then stay shop-local exactly as before the hooks existed,
    // until the owner's manual "ক্যাটালগ সিঙ্ক" lifts them.
    'pool_auto_adopt' => (bool) env('POOL_AUTO_ADOPT', true),
];
