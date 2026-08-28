<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

/**
 * #9 (Dominique 2026-08-28): foodalchemist_gp_forms auf den Satelliten-Standard heben —
 * uuid (HasUuidV7) + deleted_at (SoftDeletes), analog zu foodalchemist_gp_count_unit_defaults.
 * Der Trait-Vertrag (PolicyTest) verlangt für jedes Model LogsActivity + HasUuidV7 + SoftDeletes;
 * das Modell war beim ersten Wurf (100000) noch ein blankes Model. Backfill vergibt uuids für die
 * aus dem piece_default_g-Import bereits vorhandenen Zeilen. Idempotent (hasColumn-Guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_gp_forms')) {
            return;
        }

        Schema::table('foodalchemist_gp_forms', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_gp_forms', 'uuid')) {
                $table->uuid('uuid')->nullable()->after('id');
            }
            if (! Schema::hasColumn('foodalchemist_gp_forms', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Bestand (aus dem 100000-piece_default_g-Backfill) mit uuid v7 versorgen.
        DB::table('foodalchemist_gp_forms')->whereNull('uuid')->orderBy('id')
            ->select('id')->chunkById(500, function ($rows) {
                foreach ($rows as $r) {
                    DB::table('foodalchemist_gp_forms')->where('id', $r->id)
                        ->update(['uuid' => (string) UuidV7::generate()]);
                }
            });

        // uuid-Unique erst nach Backfill (keine NULL-Kollision). try/catch = idempotent cross-DB
        // (bei Re-Run existiert der Index schon; Laravel führt die Migration ohnehin nur einmal aus).
        try {
            Schema::table('foodalchemist_gp_forms', function (Blueprint $table) {
                $table->unique('uuid', 'fa_gp_forms_uuid_unique');
            });
        } catch (\Throwable $e) {
            // Index bereits vorhanden — harmlos.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_gp_forms')) {
            return;
        }
        Schema::table('foodalchemist_gp_forms', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_gp_forms', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
