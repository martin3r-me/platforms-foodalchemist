<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

/**
 * Umbau-Spec Darreichungen Phase 4: Concepter-Facetten statt tiefem Kategorien-Baum.
 * 4 flache Dimensionen (Review F3–F6, alle in Einstellungen pflegbar):
 *   - Servierform (einfach)   → FK auf foodalchemist_serving_forms (Scharnier zu Darreichungen)
 *   - Eventtyp (einfach)      → foodalchemist_event_types
 *   - Einsatzmoment (mehrfach)→ foodalchemist_service_moments + Pivot
 *   - Saison (mehrfach)       → foodalchemist_seasons + Pivot
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
        foreach (['foodalchemist_service_moments', 'foodalchemist_event_types', 'foodalchemist_seasons'] as $vocabTable) {
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

        if (! Schema::hasColumn('foodalchemist_concepts', 'serving_form_id')) {
            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                $table->foreignId('serving_form_id')->nullable()
                    ->constrained('foodalchemist_serving_forms')->nullOnDelete();
                $table->foreignId('event_type_id')->nullable()
                    ->constrained('foodalchemist_event_types')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('foodalchemist_concept_service_moments')) {
            Schema::create('foodalchemist_concept_service_moments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('concept_id')->constrained('foodalchemist_concepts')->cascadeOnDelete();
                $table->foreignId('service_moment_id')->constrained('foodalchemist_service_moments')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['concept_id', 'service_moment_id'], 'fa_concept_einsatzmomente_unique');
            });
        }

        if (! Schema::hasTable('foodalchemist_concept_seasons')) {
            Schema::create('foodalchemist_concept_seasons', function (Blueprint $table) {
                $table->id();
                $table->foreignId('concept_id')->constrained('foodalchemist_concepts')->cascadeOnDelete();
                $table->foreignId('season_id')->constrained('foodalchemist_seasons')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['concept_id', 'season_id'], 'fa_concept_saisons_unique');
            });
        }

        $this->seed();
    }

    /** Seeds pro bestehendem Team, idempotent über (team_id, name). */
    private function seed(): void
    {
        $teamIds = DB::table('teams')->pluck('id');
        $seedSets = [
            'foodalchemist_service_moments' => self::EINSATZMOMENTE,
            'foodalchemist_event_types' => self::EVENTTYPEN,
            'foodalchemist_seasons' => self::SAISONS,
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
        Schema::dropIfExists('foodalchemist_concept_seasons');
        Schema::dropIfExists('foodalchemist_concept_service_moments');
        if (Schema::hasColumn('foodalchemist_concepts', 'serving_form_id')) {
            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('serving_form_id');
                $table->dropConstrainedForeignId('event_type_id');
            });
        }
        Schema::dropIfExists('foodalchemist_seasons');
        Schema::dropIfExists('foodalchemist_event_types');
        Schema::dropIfExists('foodalchemist_service_moments');
    }
};
