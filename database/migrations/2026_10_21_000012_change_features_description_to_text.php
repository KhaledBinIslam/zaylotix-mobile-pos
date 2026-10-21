<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real production incident (2026-10-21): seeding the newest batch of
 * Phase 4 features failed live — "SQLSTATE[22001]: String data, right
 * truncated" — because `features.description` is a plain `string`
 * (VARCHAR(255)) and three of this session's own descriptions ran past
 * that (259/306/256 chars). SQLite (what the whole test suite runs on)
 * never enforces a VARCHAR length at all, so this sailed through 900+
 * passing tests and only surfaced against the real MySQL column.
 *
 * Root-cause fix, not a one-off trim: TEXT has no practical length cap,
 * so no future description can ever hit this again. Raw SQL rather than
 * Schema::table()->change() — this codebase has never used ->change()
 * before and that needs doctrine/dbal, which isn't a declared
 * dependency here; a plain ALTER TABLE needs nothing new installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // sqlite (what the whole test suite runs on) never enforces a
        // VARCHAR length at all — nothing to widen there, and its ALTER
        // TABLE dialect doesn't support MODIFY COLUMN in the first place
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE features MODIFY description TEXT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE features MODIFY description VARCHAR(255) NULL');
    }
};
