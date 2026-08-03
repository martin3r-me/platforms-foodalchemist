<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nebenbefund-Fix (Planungs-Kaskade P2): Concept-Status `aktiv` → `active` kanonisieren.
 *
 * Die Writer (`ConceptService::setStatus`/`duplicate`, `ConceptsSearchTool`) schreiben englisch
 * `active`; ein Leser (`DataQualityService::konzepteInGebrauch`) fragte deutsch `aktiv` ab → ein
 * freigegebenes Konzept (P2: Status `active`) galt dort nicht als „in Gebrauch". Golden Rule =
 * Schema/Werte englisch → `active` ist kanonisch. Diese Migration zieht etwaige Bestands-Zeilen
 * mit `aktiv` nach (idempotent; Sandbox hat keine, demo evtl. historische).
 *
 * NUR Concepts — Ausgabeformen (foodbook/menu_cards/menu_plans) nutzen den AusgabeStatus, der
 * `aktiv` bewusst als kanonischen Wert führt; die bleiben unangetastet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_concepts') && Schema::hasColumn('foodalchemist_concepts', 'status')) {
            DB::table('foodalchemist_concepts')->where('status', 'aktiv')->update(['status' => 'active']);
        }
    }

    public function down(): void
    {
        // Normalisierung ist bewusst nicht rückführbar (keine verlässliche Zuordnung).
    }
};
