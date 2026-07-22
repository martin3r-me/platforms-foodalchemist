<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 18 — Produktionsaufträge (N-Track, Ableger von Spec 17/Bestellwesen).
 *
 * `foodalchemist_production_orders` = EIN Auftrag je (team, production_date), der
 * mehrere Ziele (Konzept+Personen ODER Gericht+Portionen) desselben Produktionstags
 * aggregiert. Aggregation ist notwendig, nicht nur Komfort: Sub-Rezept-Ansätze werden
 * aufgerundet (ceil), und ceil(a)+ceil(b) ≠ ceil(a+b) — zwei Ziele mit je <1 Ansatz
 * derselben Zutat müssen GEMEINSAM gerundet werden. Der Ein-offener-Auftrag-je-Tag-Guard
 * läuft im Service (Transaktion + Lock), keine partielle Unique-Constraint (SQLite-
 * Test-Portabilität, Präzedenz R0.2/Spec 17).
 *
 * `foodalchemist_production_order_lines` = eine Zeile PRO REZEPT (nicht pro Ziel).
 * Rechen-Wahrheit kommt unverändert aus PlanungsblattService::produktionsblattFuerZiele()
 * (neue, rein additive Methode — explodiere()/topsAus() bleiben unverändert). Snapshot-
 * Felder (zubereitung/arbeitszeit_min/darreichung/zutaten) frieren beim Übergang
 * planned→in_progress ein; solange `planned` werden sie bei jedem Recompute aufgefrischt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_production_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index()->comment('Besitzer-Team (D1)');
            $table->date('production_date')->comment('Produktionstag — Pflichtfeld');
            $table->string('status', 16)->default('planned')->index()->comment('ProductionOrderStatus: planned|in_progress|done|cancelled');
            $table->string('reference')->nullable()->comment('Anlass/Event-Bezeichnung (frei)');
            $table->json('targets')->nullable()->comment('[{source_ref, concept_id|recipe_id, persons|portions, label}] — Eingabe-Spezifikation');
            $table->json('warnungen')->nullable()->comment('Cache der letzten Recompute-Warnungen aus PlanungsblattService');
            $table->text('note')->nullable();
            $table->dateTime('started_at')->nullable()->comment('planned→in_progress: Snapshot friert ein');
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'production_date']);
        });

        Schema::create('foodalchemist_production_order_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('production_order_id')->constrained('foodalchemist_production_orders')->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();

            $table->boolean('is_basisrezept')->default(false);
            $table->unsignedTinyInteger('tiefe')->default(0)->comment('Longest-Path-Tiefe aus explodiere() — nur Anzeige-Reihenfolge');
            $table->decimal('ansaetze', 10, 3)->default(0)->comment('finalisierte Batches (Basisrezept ganzzahlig, VK linear/fraktional)');
            $table->decimal('benoetigt_ansaetze', 10, 3)->default(0)->comment('Rohbedarf vor Rundung — Transparenz');
            $table->unsignedInteger('portionen')->nullable()->comment('nur VK-Gericht: Ansätze × Portionen/Batch');
            $table->decimal('basis_yield_kg', 10, 3)->nullable();
            $table->decimal('produzierte_menge_kg', 10, 3)->nullable();
            $table->unsignedInteger('arbeitszeit_min')->nullable()->comment('Snapshot: recipe.work_time_min × ansaetze');
            $table->text('zubereitung')->nullable()->comment('Snapshot recipe.preparation — friert bei in_progress ein');
            $table->json('darreichung')->nullable()->comment('Snapshot Regeneration/Behälter/Vehikel (nur VK)');
            $table->json('zutaten')->nullable()->comment('Snapshot der Zutaten-Zeilen (gp/sub/ungemappt)');
            $table->string('note')->nullable()->comment('manuelle Küchen-Notiz, übersteht Recompute (per recipe_id gematcht)');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['production_order_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_production_order_lines');
        Schema::dropIfExists('foodalchemist_production_orders');
    }
};
