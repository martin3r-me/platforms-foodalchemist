<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 32 · C3 — Verkaufsjournal (echtes Ist auf der ERLÖSSEITE).
 *
 * Das Modul kannte bis hier nur die Kostenseite als Ist: `foodalchemist_purchase_transactions`
 * sagt, was tatsächlich eingekauft wurde. Was tatsächlich VERKAUFT wurde — Menge und Umsatz je
 * Gericht — wurde nirgends geführt. Solange das fehlt, sind drei Dinge nicht rechenbar:
 * die Ist-Marge, das Menu-Engineering (Renner/Penner) und die Abweichung zwischen
 * theoretischem und tatsächlichem Wareneinsatz. Was bis dahin „Controlling" hieß, war
 * Kalkulation — also Soll.
 *
 * Aufbau bewusst als Spiegelbild des Einkaufsjournals, damit beide Seiten gleich zu lesen sind:
 * eine Zeile = eine Verkaufsposition einer Periode.
 *
 *  - `recipe_id` ist NULLBAR. Eine Verkaufszeile, die sich keinem Gericht zuordnen lässt,
 *    bleibt mit ihrem Roh-Text liegen und wird zur Zuordnung angeboten — verworfen wird
 *    nichts, sonst verschwindet Umsatz still aus der Auswertung.
 *  - `source_hash` trägt die Idempotenz: derselbe Export zweimal eingelesen ergibt keine
 *    Dubletten (Muster `fa_purchtx_dedup_unique`).
 *  - KEIN customer_id — dieselbe bewusste Grenze wie beim Einkaufsjournal; die Kunden-
 *    Dimension ist ein eigenes Projekt. `source_scope_label` hält die rohe Bezeichnung
 *    (Betrieb/Kostenstelle/Kasse) fest, damit ein späterer Backfill möglich bleibt.
 *
 * Index-Namen explizit kurz gehalten (MySQL-Grenze 64 Zeichen).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_sales_facts')) {
            return;
        }

        Schema::create('foodalchemist_sales_facts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();

            // Gericht: gematcht (FK) ODER nur als Roh-Text (unaufgelöst beim Import).
            $table->foreignId('recipe_id')->nullable()
                ->constrained('foodalchemist_recipes')->nullOnDelete();
            $table->string('raw_label');                              // Verkaufsbezeichnung aus der Quelle

            // Mengengerüst (netto).
            $table->decimal('qty_sold', 14, 3)->nullable();           // verkaufte Portionen/Einheiten
            $table->decimal('revenue_net', 14, 2)->nullable();        // € netto
            $table->date('sold_at');                                  // Verkaufs-/Periodentag

            // Wie kam die Zuordnung zustande? Ehrlichkeit über die Herkunft des Matches —
            // wichtig, weil ein Fuzzy-Treffer eine andere Aussagekraft hat als eine Handzuordnung.
            $table->string('match_method', 32)->nullable();           // manual | terminology | token | none
            $table->decimal('match_confidence', 5, 2)->nullable();    // 0..100

            // Herkunft + Idempotenz.
            $table->string('source', 16)->default('csv_import');      // csv_import | manual
            $table->string('source_scope_label')->nullable();         // Betrieb/Kostenstelle/Kasse (roh)
            $table->string('import_batch_id')->nullable()->index();   // gruppiert einen Import-Lauf
            $table->string('source_hash', 64)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Leserichtung: Umsatz/Absatz je Team über die Zeit, und je Gericht.
            $table->index(['team_id', 'sold_at'], 'fa_salesfact_time_idx');
            $table->index(['team_id', 'recipe_id'], 'fa_salesfact_recipe_idx');
            $table->unique(['team_id', 'source_hash'], 'fa_salesfact_dedup_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_sales_facts');
    }
};
