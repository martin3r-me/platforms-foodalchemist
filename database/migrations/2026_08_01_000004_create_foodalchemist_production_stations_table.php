<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 30 E3 — Posten (Arbeitsplätze) mit Kapazität.
 *
 * ⚠️ ARCHITEKTUR-ENTSCHEIDUNG (Dominique, 2026-08-01): `docs/ARCHITEKTUR.md` führt
 * „Touren- und Personalplanung" unter den Nicht-Zielen des Moduls, Spec 18/20 notieren
 * „Keine Stationen-/Personal-Zuweisung in der Produktion". Diese Grenze wird bewusst
 * NEU GEZOGEN — an der Sache statt am Menschen:
 *
 *   Wir planen POSTEN (Arbeitsplätze mit einer Kapazität in Minuten pro Tag),
 *   NICHT MENSCHEN. Kein Schichtplan, keine Verfügbarkeiten, keine Abwesenheiten,
 *   keine Personalstammdaten. Der Verantwortliche an einer Auftragszeile bleibt ein
 *   freier Name — ein Etikett, kein Datensatz, und es gibt keine Aggregation darüber.
 *
 * Doku dazu: docs/PLANUNG/30_Produktion_Ausbau.md.
 *
 * WARUM eine eigene Tabelle und KEIN `foodalchemist_vocab_*`:
 *  1. Vokabular-Tabellen werden beim Import GELEERT (`ImportSliceCommand` macht
 *     `DB::table('foodalchemist_vocab_kitchen_equipment')->delete()`). Kapazitätsminuten
 *     dort wären beim nächsten Re-Import weg — und mit ihnen jede Zuteilung.
 *  2. Vokabular-`slug` ist global unique bei nullable `team_id`. Zwei Betriebe könnten
 *     nicht beide eine „Patisserie" mit eigener Kapazität führen. Kapazität ist physisch
 *     und standortgebunden, also team-eigen (`team_id` NOT NULL, kein globaler Seed).
 *  3. Equipment am Rezept beantwortet „was braucht dieses Rezept". Ein Posten beantwortet
 *     „wo wird gearbeitet". 40 geerbte Legacy-Chips („Stabmixer") sind keine Arbeitsplätze.
 *
 * Kapazität ist per Definition NETTO — produktiv verplanbare Minuten, Rüsten/Reinigen/Pause
 * bereits abgezogen. Deshalb bewusst KEINE eigene `ruestzeit_min`-Spalte: die wäre eine
 * zweite erfundene Zahl neben der ohnehin geschätzten `work_time_min` am Rezept.
 * Ebenso keine `parallelitaet`: zwei Kombidämpfer sind im Minuten-Aggregat schlicht 2 × 480.
 *
 * `kapazitaet_min_pro_tag` ist NULLABLE und das ist der wichtigste Schalter des ganzen
 * Features: ein Posten ohne Kapazität warnt NIE. Kapazitätsplanung ist opt-in je Posten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_production_stations')) {
            return;
        }

        Schema::create('foodalchemist_production_stations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index()
                ->comment('NOT NULL — Posten sind team-eigene Betriebsstammdaten, kein globaler Seed');

            $table->string('slug');
            $table->string('name');
            $table->string('group_name')->nullable()->comment('„Kalte Küche" / „Warme Küche" / „Patisserie"');

            $table->unsignedInteger('kapazitaet_min_pro_tag')->nullable()
                ->comment('NETTO produktive Minuten/Tag. NULL = kein Kapazitätsposten → warnt nie (opt-in).');
            $table->json('kapazitaet_wochentag')->nullable()
                ->comment('Nur Abweichungen, ISO 1=Mo…7=So, z. B. {"6":240,"7":0}');

            $table->unsignedInteger('sort_order')->default(100);
            $table->boolean('is_inactive')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'slug'], 'fa_prod_stations_team_slug_uq');
            $table->index(['team_id', 'sort_order'], 'fa_prod_stations_team_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_production_stations');
    }
};
