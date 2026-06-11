<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2-09 / GL-01-BLOCKER: LA-Allergene (Quelle wawi `allergens`, 139.012 Zeilen).
 *
 * 14 EU-Allergene im 4-Wert-Modell als Strings (AllergenValue; NULL = unbekannt/
 * unbewertet — Quell-Kodierung 0). Getreide-/Nuss-Unterarten (wheat…queensland)
 * lossless als `details`-JSON (nur Nicht-Null-Werte). `quelle` = Lineage:
 * NULL = Import, 'manual' = UI-Edit (GL-07; KI-Pfad kommt später).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_item_allergens', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->index()->comment('= Quell-supplier_item_id');
            $table->foreignId('supplier_item_id')->unique()->constrained('foodalchemist_supplier_items')->cascadeOnDelete();

            foreach ([
                'glutenhaltiges_getreide', 'krebstiere', 'eier', 'fisch', 'erdnuesse', 'soja', 'milch',
                'schalenfruechte', 'sellerie', 'senf', 'sesam', 'schwefeldioxid', 'lupinen', 'weichtiere',
            ] as $allergen) {
                $table->string("allergen_{$allergen}", 16)->nullable();
            }
            $table->json('details')->nullable()->comment('Unterarten (weizen/roggen/…, mandel/…) — nur Nicht-Null');
            $table->string('quelle', 16)->nullable()->comment('NULL=Import | manual (GL-07-Lineage)');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_item_allergens');
    }
};
