<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipment-Zeilen tragen keine Provenienz — als einzige der vier Satelliten-Tabellen.
 *
 * `foodalchemist_recipe_containers` und `foodalchemist_recipe_regenerations` führen seit Spec 51
 * `source` (manual|ki) plus `ai_confidence`/`ai_reasoning`, und die Anker-Mappings ebenso. Nur
 * `foodalchemist_recipe_equipment` hatte id, recipe_id, equipment_id, note — sonst nichts.
 *
 * Das ist nicht nur eine fehlende Spalte, sondern der Grund für einen stillen Datenverlust:
 * die Anreicherung schreibt mit `$recipe->equipment()->sync(...)`, und sync ERSETZT die ganze
 * Liste. Ein von Hand gesetztes Gerät verschwindet beim nächsten Voll-Lauf, ohne Spur und ohne
 * Meldung. Bei Ankern und Regeneration steht im selben Code ausdrücklich „manual gewinnt"
 * (Inv. 3) — beim Equipment fehlte die Grundlage dafür, weil man manuell und KI gar nicht
 * unterscheiden konnte.
 *
 * Default `manual`: was VOR dieser Migration da war, ist entweder von Hand gesetzt oder
 * importiert — in beiden Fällen eine menschliche Entscheidung, die die KI nicht überschreiben
 * darf. Die Annahme ist bewusst konservativ: sie schützt Bestand, statt ihn freizugeben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipe_equipment', function (Blueprint $t) {
            $t->string('source', 16)->default('manual')->after('note');
            $t->decimal('ai_confidence', 4, 3)->nullable()->after('source');
            $t->text('ai_reasoning')->nullable()->after('ai_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipe_equipment', function (Blueprint $t) {
            $t->dropColumn(['source', 'ai_confidence', 'ai_reasoning']);
        });
    }
};
