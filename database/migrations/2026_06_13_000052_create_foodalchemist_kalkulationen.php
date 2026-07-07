<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-K10 / Doc 16 §11: Standalone Kalkulations-Composer (Prüfung, entkoppelt vom
 * Concepter). Eine `kalkulation` = benannte Positionsliste; Positionen referenzieren
 * Gericht / Basisrezept / GP (ziehen Wareneinsatz + Arbeitszeit als Snapshot) oder
 * sind freie Zeilen. HK1/HK2 = Positionen + Settings-Zuschläge (mehrstufig, §10).
 * Bewusst KEIN FK auf Concepter-Concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_calculations')) {
            Schema::create('foodalchemist_calculations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->string('title');
                $table->decimal('margin_override_pct', 7, 2)->nullable();   // sonst Team-Marge
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('foodalchemist_calculation_positions')) {
            Schema::create('foodalchemist_calculation_positions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->foreignId('calculation_id')->constrained('foodalchemist_calculations')->cascadeOnDelete();
                $table->string('type', 16);                                 // gericht | basisrezept | gp | frei
                $table->unsignedBigInteger('ref_id')->nullable();          // recipe_id bzw. gp_id (kein harter FK: Snapshot-Charakter)
                $table->string('label');
                $table->string('unit', 24)->nullable();                 // Portion | kg | …
                $table->decimal('quantity', 12, 3)->default(1);
                $table->decimal('einzel_ek', 12, 4)->default(0);           // Wareneinsatz je Einheit (Snapshot, überschreibbar)
                $table->unsignedInteger('work_time_min')->nullable();    // gezogen (Snapshot), für den Lohn-Block
                $table->integer('position')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_calculation_positions');
        Schema::dropIfExists('foodalchemist_calculations');
    }
};
