<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Das Wissensbudget wird einstellbar.
 *
 * Bis hierher stand je Arbeitsschritt EINE Zahl in `config('foodalchemist.ai.knowledge_budget')`
 * — änderbar nur per Deploy. Die Wissens-Steuerung ZEIGT sie längst („Σ Pflicht / Budget"), und
 * genau dort endete es: wer sah, dass ein Schritt an seiner Grenze steht, konnte nichts tun.
 *
 * Das ist keine Kosmetik. Das Budget ist der Hebel für Kosten gegen Qualität: mehr Zeichen =
 * mehr Kontext = teurerer Call. Diese Abwägung gehört dem Betreiber, nicht dem Release-Zyklus.
 *
 * Form bewusst wie {@see foodalchemist_knowledge_routings}: GLOBAL, ohne `team_id`. Die ganze
 * Steuerschicht (Kanon-Global-Zeilen, Routings) gehört dem Master; ein zweites Eigentumsmodell
 * nur fürs Budget wäre genau die Doppelung, die Spec 52 abbaut.
 *
 * Die Tabelle startet LEER. Solange kein Eintrag existiert, gilt die Config unverändert — die
 * Migration ändert also für sich genommen kein einziges Verhalten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_knowledge_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('prompt_key', 64)->unique();
            $table->unsignedInteger('max_chars');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_knowledge_budgets');
    }
};
