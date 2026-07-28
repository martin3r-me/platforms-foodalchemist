<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 12·S3c-1 (Bauplan-Punkt 3) — die Rolle eines Slots wird ein Fremdschlüssel,
 * kein String-Vergleich.
 *
 * Seit 12·S3b bindet die Speisen-Hauptgruppe den Slot lexikografisch. Die *Zuordnung*
 * Slot → Hauptgruppe lief dabei über `MenuCandidatePoolService::slotSemantik()`, also
 * über einen 5-Zeichen-Präfix-Vergleich der Labels. Der trägt für Kanon-Bezeichnungen
 * („Hauptgang" ↔ „Hauptgericht"), und die Messung aus Lauf 60 hat gezeigt, wo er endet:
 * am Master lösen **5 von 6** distinkten Slot-Labels auf keine Hauptgruppe auf, weil der
 * Slot-Titel dort die Verkaufszeile ist („Main – Hyper Local · Geschmack aus der Region,
 * neu gedacht"). Gegen freie Prosa hilft weder eine Alias-Liste noch ein LLM — es hilft
 * nur, die Zuordnung **einmal zu entscheiden und hinzuschreiben** (→ D-019).
 *
 * Darum diese Spalte. Sie ist der Ort, an dem die Entscheidung liegt; der Vorschlags-Weg
 * dorthin (Alias → Semantik-Fallback → LLM, jeweils als Review-Zeile) ist S3c-2 und
 * schreibt in dieselbe Spalte. Damit verschwindet die Fehlerklasse „Vorspeise vs
 * Vorspeisen" **ganz**, sobald ein Slot gebunden ist — nicht abgemildert.
 *
 * **Additiv, nullable, kein Backfill.** NULL heißt „nicht gebunden" und fällt auf den
 * Label-Pfad zurück; das Bestandsverhalten bleibt damit byte-identisch (Riegel:
 * `SlotSemantikGoldenTest` aus S3a, unverändert grün). Ein Backfill per Label-Match
 * wäre das Gegenteil des Etappen-Ziels: er würde genau die Näherung, die ersetzt werden
 * soll, als menschliche Entscheidung ausgeben.
 *
 * **Warum `unsignedBigInteger` + Index statt `foreignId()->constrained()`:** dieselbe
 * Bauform wie `chapter_id` in der Ursprungs-Migration derselben Tabelle
 * (2026_07_13_000010). Die Hauptgruppen-Tabelle ist ein team-pflegbares Vokabular mit
 * SoftDeletes — eine harte FK-Einschränkung würde das Löschen einer Hauptgruppe an
 * Gerüst-Slots scheitern lassen, obwohl `recipes.dish_main_group_id` (die Gericht-Seite
 * derselben Beziehung) es ebenfalls ohne Constraint hält. Die Zulässigkeit prüft
 * stattdessen `PlanningFrameService` beim Schreiben, inklusive Team-Sichtbarkeit — eine
 * Fremd-ID darf keine Sichtbarkeit erzeugen.
 *
 * Nicht dabei (bewusst): eine Herkunfts-Spalte (`…_source`). Solange nur der Mensch
 * bzw. `planning.PUT` schreibt, wäre sie ein Feld ohne Wirkung — genau das Anti-Muster,
 * das V-043 katalogisiert. Sie gehört zu S3c-2, wo es einen Vorschlag zu unterscheiden gibt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_planning_frame_slots')) {
            return;
        }
        if (Schema::hasColumn('foodalchemist_planning_frame_slots', 'dish_main_group_id')) {
            return;
        }
        Schema::table('foodalchemist_planning_frame_slots', function (Blueprint $table) {
            $table->unsignedBigInteger('dish_main_group_id')->nullable()->after('slot_type')
                ->index('fa_planning_slot_dish_hg_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('foodalchemist_planning_frame_slots', 'dish_main_group_id')) {
            return;
        }
        Schema::table('foodalchemist_planning_frame_slots', function (Blueprint $table) {
            $table->dropIndex('fa_planning_slot_dish_hg_idx');
            $table->dropColumn('dish_main_group_id');
        });
    }
};
