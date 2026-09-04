<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `foodalchemist_cascade_runs.deckel_hinweise` (json, nullable) — was ein Lauf NICHT erzeugt hat.
 *
 * Anlass: die Generierung trägt sechs Deckel gegen Runaway-Kosten, und keiner davon erreicht den
 * Menschen. Drei schreiben ihre Zahl nach `cascade_runs.params` — und werden dort systematisch
 * weggeworfen: die Status-DTO filtert `params` gegen
 * {@see \Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession::ALLOWED_GENERATION_PARAMS},
 * weil dieser Beutel die wirksamen LEITPLANKEN eines Laufs zeigen soll. Ein ERGEBNIS-Hinweis
 * darin ist eine Kategorienverwechslung, keine Filter-Lücke. Die anderen drei Deckel melden gar
 * nichts.
 *
 * Wie sich das konkret auswirkt: ein Standard-Speiseplan-Zyklus sind 4 Wochen × 3 Linien ×
 * 5 Werktage = 60 Zellen, `SPEISEPLAN_MAX_ZELLEN` ist 30. Die Hälfte des Auftrags entfällt also
 * im Normalfall — unsichtbar. Der zugehörige Test pinnte das Schweigen sogar als erwartetes
 * Verhalten (`assertSet('kaskadeMeldung', null)`) und nannte es im Kommentar „kein stiller
 * Deckel".
 *
 * Diese Spalte ist der richtige Ort dafür, weil sie ein ERGEBNIS des Laufs trägt, nicht seine
 * Steuerung — dieselbe Rolle wie `cohesion_warning` (Migration 2026_08_14_000011), die aus
 * demselben Grund eine eigene Spalte bekam und in der Status-DTO neben den Leitplanken steht,
 * ohne durch deren Filter zu laufen.
 *
 * Form: eine Liste von Einträgen, damit ein Lauf mehrere Deckel gleichzeitig treffen kann
 * (ein großer Speiseplan kann Zellen UND Sub-Rezept-Schritte deckeln):
 *
 *   [{"deckel":"speiseplan_zellen","grenze":30,"verlangt":60,"offen":30,"text":"…"}, …]
 *
 * `text` reist mit, weil der Hinweis auch dann verständlich sein muss, wenn er Wochen später in
 * einem Lauf-Detail gelesen wird — ein Schlüssel allein („speiseplan_zellen") verlangt vom Leser
 * Code-Wissen. `null` = kein Deckel gegriffen; ein Nichts wird nicht als Hinweis verkauft
 * (gleiche Regel wie bei `cohesion_warning`).
 *
 * Additiv/idempotent (hasColumn-Guard). Bestandsläufe bleiben `null` → unverändertes Verhalten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_cascade_runs') && ! Schema::hasColumn('foodalchemist_cascade_runs', 'deckel_hinweise')) {
            Schema::table('foodalchemist_cascade_runs', function (Blueprint $table) {
                $table->json('deckel_hinweise')->nullable()
                    ->comment('Was der Lauf NICHT erzeugt hat: [{deckel, grenze, verlangt, offen, text}]');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_cascade_runs') && Schema::hasColumn('foodalchemist_cascade_runs', 'deckel_hinweise')) {
            Schema::table('foodalchemist_cascade_runs', function (Blueprint $table) {
                $table->dropColumn('deckel_hinweise');
            });
        }
    }
};
