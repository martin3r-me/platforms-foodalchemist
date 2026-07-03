<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

/**
 * Umbau-Spec Darreichungen Phase 4: Concepter-Facetten statt tiefem Kategorien-Baum.
 * 4 flache Dimensionen (Review F3–F6, alle in Einstellungen pflegbar):
 *   - Servierform (einfach)   → FK auf foodalchemist_servierformen (Scharnier zu Darreichungen)
 *   - Eventtyp (einfach)      → foodalchemist_eventtypen
 *   - Einsatzmoment (mehrfach)→ foodalchemist_einsatzmomente + Pivot
 *   - Saison (mehrfach)       → foodalchemist_saisons + Pivot
 * FA-nativ (kein WaWi-Spiegel). Seeds pro bestehendem Team; neue Teams pflegen
 * über die Einstellungen nach.
 */
return new class extends Migration
{
    private const EINSATZMOMENTE = [
        'Frühstück', 'Brunch', 'Vormittagspause', 'Lunch', 'Nachmittagspause',
        'Apéro/Empfang', 'Dinner', 'Late-Night/Mitternachtssnack', 'Ganztags/Tagungspauschale',
    ];

    private const EVENTTYPEN = [
        'Konferenz/Tagung', 'Seminar/Workshop', 'Gala/Bankett', 'Firmenfeier',
        'Sommerfest', 'Weihnachtsfeier', 'Messe/Ausstellung', 'Produktpräsentation/Launch',
        'Empfang/Vernissage', 'Hochzeit', 'Privatfeier/Jubiläum', 'Sportevent',
        'Betriebsverpflegung',
    ];

    private const SAISONS = ['Frühling', 'Sommer', 'Herbst', 'Winter', 'ganzjährig'];

    public function up(): void
    {
        foreach (['foodalchemist_einsatzmomente', 'foodalchemist_eventtypen', 'foodalchemist_saisons'] as $vocabTable) {
            if (Schema::hasTable($vocabTable)) {
                continue;
            }
            Schema::create($vocabTable, function (Blueprint $table) use ($vocabTable) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->string('name');
                $table->integer('sort_order')->default(100);
                $table->boolean('is_inactive')->default(false);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['team_id', 'name'], 'fa_'.str_replace('foodalchemist_', '', $vocabTable).'_team_name_unique');
            });
        }

        if (! Schema::hasColumn('foodalchemist_concepts', 'servierform_id')) {
            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                $table->foreignId('servierform_id')->nullable()
                    ->constrained('foodalchemist_servierformen')->nullOnDelete();
                $table->foreignId('eventtyp_id')->nullable()
                    ->constrained('foodalchemist_eventtypen')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('foodalchemist_concept_einsatzmomente')) {
            Schema::create('foodalchemist_concept_einsatzmomente', function (Blueprint $table) {
                $table->id();
                $table->foreignId('concept_id')->constrained('foodalchemist_concepts')->cascadeOnDelete();
                $table->foreignId('einsatzmoment_id')->constrained('foodalchemist_einsatzmomente')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['concept_id', 'einsatzmoment_id'], 'fa_concept_einsatzmomente_unique');
            });
        }

        if (! Schema::hasTable('foodalchemist_concept_saisons')) {
            Schema::create('foodalchemist_concept_saisons', function (Blueprint $table) {
                $table->id();
                $table->foreignId('concept_id')->constrained('foodalchemist_concepts')->cascadeOnDelete();
                $table->foreignId('saison_id')->constrained('foodalchemist_saisons')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['concept_id', 'saison_id'], 'fa_concept_saisons_unique');
            });
        }

        $this->seed();
    }

    /** Seeds pro bestehendem Team, idempotent über (team_id, name). */
    private function seed(): void
    {
        $teamIds = DB::table('teams')->pluck('id');
        $seedSets = [
            'foodalchemist_einsatzmomente' => self::EINSATZMOMENTE,
            'foodalchemist_eventtypen' => self::EVENTTYPEN,
            'foodalchemist_saisons' => self::SAISONS,
        ];
        foreach ($teamIds as $teamId) {
            foreach ($seedSets as $tableName => $namen) {
                foreach (array_values($namen) as $i => $name) {
                    $exists = DB::table($tableName)
                        ->where('team_id', $teamId)->where('name', $name)->exists();
                    if ($exists) {
                        continue;
                    }
                    DB::table($tableName)->insert([
                        'uuid' => (string) UuidV7::generate(),
                        'team_id' => $teamId,
                        'name' => $name,
                        'sort_order' => ($i + 1) * 10,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_concept_saisons');
        Schema::dropIfExists('foodalchemist_concept_einsatzmomente');
        if (Schema::hasColumn('foodalchemist_concepts', 'servierform_id')) {
            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('servierform_id');
                $table->dropConstrainedForeignId('eventtyp_id');
            });
        }
        Schema::dropIfExists('foodalchemist_saisons');
        Schema::dropIfExists('foodalchemist_eventtypen');
        Schema::dropIfExists('foodalchemist_einsatzmomente');
    }
};
