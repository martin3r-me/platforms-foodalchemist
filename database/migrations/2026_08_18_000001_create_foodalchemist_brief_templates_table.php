<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Schnellstart-Vorlagen (Brief-Templates) — kunden-anlegbare Startpunkte für die Planung-Erzeugung.
 * Eine Vorlage ist ein benannter Schnappschuss: Brief + Kreativ-Modus + der komplette Leitplanken-Stand
 * (`payload.regler`), je Scope (rezept|gericht|concept). team_id NULL = kuratierte Global-Vorlage
 * (BHG-Default, read-only für Kunden); team-eigene sind editierbar (D1-Sichtbarkeit: Global ∪ eigene Kette).
 *
 * Löst die frühere PHP-Konstante `Planung\Index::BRIEF_VORLAGEN` ab (die 6 werden hier als Globals geseedet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_brief_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = kuratierte Global-Vorlage (D1)');
            $table->string('slug', 120);
            $table->string('label');
            $table->string('scope', 24)->comment('rezept|gericht|concept');
            $table->string('titel')->nullable();
            $table->text('brief');
            $table->json('payload')->nullable()->comment('{creative_mode, regler:{…Leitplanken-Snapshot}}');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'scope', 'active']);
            $table->unique(['team_id', 'slug']);
        });

        // Seed: die 6 bisher hartcodierten kuratierten Vorlagen als Globals (team_id NULL).
        // payload.regler spiegelt die alten Kontext-Felder (sektor/occasion/serviceform) 1:1.
        $now = now();
        $seed = [
            ['catering_empfang_flying', 'Catering — Empfang / Flying', 'Fingerfood-Häppchen für einen Steh-Empfang, in einem Bissen ohne Besteck essbar und formstabil (auch nach kurzer Wartezeit auf der Platte). Ansprechend im Flying Service zu reichen.', 'catering', 'empfang', 'flying'],
            ['catering_galadinner', 'Catering — Galadinner (Hauptgang)', 'Warmer Hauptgang für ein gesetztes Galadinner im Tellerservice, gehobenes Niveau, sauber anrichtbar und regenerierfähig für den Bankett-Ausstoß.', 'catering', 'dinner', 'tellerservice'],
            ['bgm_mittagstisch', 'Betriebsgastro — Mittagstisch', 'Mittagsgericht für die Betriebsgastronomie am Buffet, warmhalte- und ausgabestabil über die Mittagslinie, kalkulierbarer Wareneinsatz und alltagstaugliche Zutaten.', 'betriebsgastronomie', 'lunch', 'buffet'],
            ['care_mittag', 'Care / Klinik — Mittagsverpflegung', 'Mittagsgericht für die Care-/Klinikverpflegung im Tellerservice, gut kaufähig und bekömmlich, zurückhaltend gewürzt und regenerierfähig aus der Zentralküche.', 'care', 'lunch', 'tellerservice'],
            ['schule_mittag', 'Schule / Kita — Mittagsverpflegung', 'Kindgerechtes Mittagsgericht für Schule/Kita am Buffet, mild gewürzt und akzeptanzstark, an DGE-Qualitätsstandards orientiert und in Serie ausgabefähig.', 'schule_kita', 'lunch', 'buffet'],
            ['restaurant_hauptgang', 'Restaurant — à la carte Hauptgang', 'À-la-carte-Hauptgang fürs Restaurant im Tellerservice, à la minute abrufbar, sauberes Plating und eine klare geschmackliche Handschrift.', 'restaurant', 'dinner', 'tellerservice'],
        ];
        $rows = [];
        foreach ($seed as $i => [$slug, $label, $brief, $sektor, $occasion, $serviceform]) {
            $rows[] = [
                'uuid' => (string) Str::uuid7(),
                'team_id' => null,
                'slug' => $slug,
                'label' => $label,
                'scope' => 'gericht',
                'titel' => null,
                'brief' => $brief,
                'payload' => json_encode([
                    'regler' => ['sektor' => $sektor, 'occasion' => $occasion, 'serviceform' => $serviceform],
                ], JSON_UNESCAPED_UNICODE),
                'sort_order' => ($i + 1) * 10,
                'active' => true,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('foodalchemist_brief_templates')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_brief_templates');
    }
};
