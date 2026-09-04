<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 51 A — die Dichteklasse als Auffangnetz unter der Referenz-Füllung.
 *
 * Die genaue Basis für den Behälterbedarf ist die Referenz-Füllung je Zweck
 * (»in ein GN 1/1-65 passen 8 kg davon«, siehe `foodalchemist_recipe_containers`). Sie kommt aus
 * der Küche und enthält die Schüttdichte bereits — genauer als jede Dichtetabelle und ohne eine
 * Quelle zu brauchen, die es nicht gibt (kein Handelskatalog nennt maximale Füllhöhen).
 *
 * Nur: die pflegt niemand für ~1.400 Bestandsrezepte im Voraus. Deshalb eine Rangfolge, Muster
 * `RecipeRecomputeService::grammFaktor()` und Allergen-Konfidenz:
 *
 *   Rang 1  Referenz-Füllung am Rezept          → Konfidenz hoch
 *   Rang 2  DIESE Dichteklasse × nutzfaktor     → Konfidenz mittel
 *   Rang 3  Warengruppen-Default                → Konfidenz niedrig, sichtbar markiert
 *   Rang 4  nichts                              → kein Vorschlag, mit Grund
 *
 * Damit ist der Bestand vom ersten Tag an bemessbar und wird Rezept für Rezept genauer, ohne je
 * mehr zu behaupten als er weiss.
 *
 * Die Klasse hängt am REZEPT, nicht an der Zweck-Zeile: Abfüllen und Regenerieren desselben
 * Produkts haben dieselbe Dichte. Zwei Felder würden auseinanderlaufen. Was je Zweck verschieden
 * ist, ist die Skalierung (kalt darf hoch stehen, regeneriert nicht) — die steht dort.
 *
 * Lineage-Trio wie bei `dish_class_*` (GL-07): die KI darf die Klasse vorschlagen, das ist eine
 * Produkteigenschaft. Die ANZAHL Behälter darf sie nie vorschlagen — das ist eine Rechnung, und
 * die Datenbank kennt die Kilogramm exakt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_recipes')) {
            return;
        }

        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'dichteklasse')) {
                $table->string('dichteklasse', 16)->nullable()
                    ->comment('fluessig 1.0 | dicht 0.9 | schuettfaehig 0.6 | locker 0.2 — kg je Liter Nutzvolumen');
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'dichteklasse_source')) {
                $table->string('dichteklasse_source', 16)->nullable()
                    ->comment('Lineage GL-07: manual gewinnt gegen ai');
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'dichteklasse_ai_confidence')) {
                $table->decimal('dichteklasse_ai_confidence', 4, 3)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'dichteklasse_ai_reasoning')) {
                $table->text('dichteklasse_ai_reasoning')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_recipes')) {
            return;
        }

        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            foreach ([
                'dichteklasse', 'dichteklasse_source',
                'dichteklasse_ai_confidence', 'dichteklasse_ai_reasoning',
            ] as $spalte) {
                if (Schema::hasColumn('foodalchemist_recipes', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
