<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Macht die Klasse-Dimension (foodalchemist_vocab_klassen) zu einem Baum (Eltern/Kinder),
 * Pendant zur Concept-Kategorie. Pflege künftig in den Einstellungen → „Konzept-Taxonomie".
 *
 * Bewusst KEINE DB-FK (Schema::table auf bestehender Tabelle bricht sonst auf SQLite — vgl.
 * CLAUDE.md „Migrations-Fallen"): nur indizierte Spalte, referenzielle Integrität trägt der
 * Service (ConceptService::deleteKlasse hängt Kinder an den Eltern um). `concepts.klasse` /
 * `pakete.klasse` bleiben freie String-Slugs (keine Datenmigration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_vocab_klassen', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_vocab_klassen', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')->nullable()->after('team_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_vocab_klassen', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_vocab_klassen', 'parent_id')) {
                $table->dropColumn('parent_id');
            }
        });
    }
};
