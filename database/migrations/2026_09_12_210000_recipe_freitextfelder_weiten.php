<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `function` und `temperature` waren varchar(64) — und gemessen zu 98 % bzw. 86 % ausgereizt.
 *
 * Beide sind FREITEXT, den die KI im `recipe.eigenschaften`-Schritt schreibt („kulinarische
 * Funktion", „Serviertemperatur"), und der Prompt nennt dem Modell keine Längengrenze. Über
 * 2.347 Rezepte hinweg liegt der längste `function`-Wert bei 63 Zeichen — bündig an der Decke:
 *
 *     63 Z.  kaltes Gemüse-Püree / frische Beilagen- oder Garniturkomponente
 *     62 Z.  Fischgericht / frischer Teller als Vorspeise oder Zwischengang
 *
 * Ein Zeichen mehr, und `SQLSTATE[22001] Data too long` reisst den GANZEN Schreibvorgang mit —
 * inklusive work_time_min, setup_time_min, standzeit_min und batch_max_*, die im selben
 * `forceFill()->save()` hängen. Bei Rezept 3748 sind vier dieser Felder deshalb bis heute NULL,
 * und der einzige rote Reife-Blocker war ausgerechnet die Arbeitszeit.
 *
 * „Ging mal, geht jetzt nicht mehr" war also nie eine Regression: es ist ein Münzwurf bei jeder
 * Anreicherung, und er fällt umso öfter falsch, je ausführlicher das Modell antwortet.
 *
 * 255 statt 64: genug Luft für einen beschreibenden Halbsatz, ohne die Spalte zu einem Text-Feld
 * zu machen. Der Riegel gegen wirklich lange Antworten sitzt zusätzlich im Schreibpfad
 * (RecipeOneShotService) — die Spalte allein wäre nur eine höhere Decke, kein Schutz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $t) {
            $t->string('function', 255)->nullable()->change();
            $t->string('temperature', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Zurück auf 64 würde bestehende längere Werte abschneiden — MySQL wirft dabei je nach
        // sql_mode still oder laut. Der Rückweg kappt darum bewusst VORHER und sichtbar.
        foreach (['function', 'temperature'] as $spalte) {
            \DB::table('foodalchemist_recipes')->whereRaw("CHAR_LENGTH(`{$spalte}`) > 64")
                ->update([$spalte => \DB::raw("LEFT(`{$spalte}`, 64)")]);
        }
        Schema::table('foodalchemist_recipes', function (Blueprint $t) {
            $t->string('function', 64)->nullable()->change();
            $t->string('temperature', 64)->nullable()->change();
        });
    }
};
