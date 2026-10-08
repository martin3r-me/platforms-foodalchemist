<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 76 · Lagerarten am Rezept (Mehrfachauswahl): welche Lagerarten möglich sind (gekuehlt | tiefgekuehlt | trocken)
 * + Haltbarkeit „trocken". `storage_type` bleibt die Standard-Lagerart. Additiv; Backfill aus den vorhandenen Angaben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'storage_types')) {
                $table->json('storage_types')->nullable();
                $table->unsignedSmallInteger('shelf_life_dry_days')->nullable();
            }
        });
        // Backfill: Standard-Lagerart + jede Lagerart mit Haltbarkeit gilt als möglich
        DB::table('foodalchemist_recipes')
            ->where(fn ($q) => $q->whereNotNull('storage_type')->orWhereNotNull('shelf_life_chilled_days')->orWhereNotNull('shelf_life_frozen_days'))
            ->whereNull('storage_types')
            ->orderBy('id')->select(['id', 'storage_type', 'shelf_life_chilled_days', 'shelf_life_frozen_days'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $r) {
                    $arten = [];
                    foreach (['gekuehlt' => $r->shelf_life_chilled_days !== null, 'tiefgekuehlt' => $r->shelf_life_frozen_days !== null, 'trocken' => false] as $art => $hat) {
                        if ($hat || $r->storage_type === $art) {
                            $arten[] = $art;
                        }
                    }
                    DB::table('foodalchemist_recipes')->where('id', $r->id)->update(['storage_types' => json_encode($arten)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn(['storage_types', 'shelf_life_dry_days']);
        });
    }
};
