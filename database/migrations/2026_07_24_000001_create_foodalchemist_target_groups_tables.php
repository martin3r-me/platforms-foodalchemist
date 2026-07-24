<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

/**
 * Spec 19 „Foodbook-Leitstelle A–Z" — M1: Zielgruppen-Vokabular (Entscheidung 4 + 6).
 *
 * Eigenes Zielgruppen-Vokabular (Outlet nur optionaler Tag, KEINE primäre Ebene).
 * Ein Foodbook trägt 1–n Default-Zielgruppen, ein Kapitel 1–n, und beim Kapitel-Go
 * wird das aufgelöste Set auf das erzeugte Konzept gestempelt (Pivot statt Freitext ins
 * Legacy-Feld concepts.target_group, Entscheidung 6 — dieses bleibt unangetastet).
 *
 * FA-nativ (kein WaWi-Spiegel). Seeds pro bestehendem Team, idempotent über (team_id, name);
 * neue Teams pflegen über die Einstellungen nach (E3.3).
 *
 * Namens-Hinweis: Die Spec nennt die Pivots verkürzt `foodbook_target_groups` /
 * `chapter_target_groups` / `concept_target_groups`. Da alle Tabellen dieses Moduls in der
 * geteilten Plattform-DB `foodalchemist_`-präfigiert sind (Kollisions-Schutz + Modul-Konvention,
 * vgl. `foodalchemist_concept_service_moments`), werden die Pivots präfigiert angelegt.
 * Downstream-PHP nutzt Eloquent-Relations, nicht die Roh-Tabellennamen.
 */
return new class extends Migration
{
    /** Seed-Zielgruppen (Spec „z.B."-Set). Team-pflegbar ab E3.3. */
    private const ZIELGRUPPEN = [
        'Tagungsgast',
        'Bankett-Gast',
        'Mitarbeiter',
        'VIP-Gala',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_target_groups')) {
            Schema::create('foodalchemist_target_groups', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->string('name');
                $table->string('description')->nullable();
                $table->integer('sort_order')->default(100);
                $table->boolean('is_inactive')->default(false);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['team_id', 'name'], 'fa_target_groups_team_name_unique');
            });
        }

        // Pivot Foodbook-Default (1–n).
        if (! Schema::hasTable('foodalchemist_foodbook_target_groups')) {
            Schema::create('foodalchemist_foodbook_target_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('foodbook_id')->constrained('foodalchemist_foodbooks')->cascadeOnDelete();
                $table->foreignId('target_group_id')->constrained('foodalchemist_target_groups')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['foodbook_id', 'target_group_id'], 'fa_foodbook_target_groups_unique');
            });
        }

        // Pivot Kapitel (1–n).
        if (! Schema::hasTable('foodalchemist_chapter_target_groups')) {
            Schema::create('foodalchemist_chapter_target_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('chapter_id')->constrained('foodalchemist_foodbook_chapters')->cascadeOnDelete();
                $table->foreignId('target_group_id')->constrained('foodalchemist_target_groups')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['chapter_id', 'target_group_id'], 'fa_chapter_target_groups_unique');
            });
        }

        // Pivot Konzept-Stempel (Entscheidung 6 — Ziel des Kapitel-Go statt Legacy-Freitext).
        if (! Schema::hasTable('foodalchemist_concept_target_groups')) {
            Schema::create('foodalchemist_concept_target_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('concept_id')->constrained('foodalchemist_concepts')->cascadeOnDelete();
                $table->foreignId('target_group_id')->constrained('foodalchemist_target_groups')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['concept_id', 'target_group_id'], 'fa_concept_target_groups_unique');
            });
        }

        $this->seed();
    }

    /** Seeds pro bestehendem Team, idempotent über (team_id, name). */
    private function seed(): void
    {
        $teamIds = DB::table('teams')->pluck('id');
        foreach ($teamIds as $teamId) {
            foreach (array_values(self::ZIELGRUPPEN) as $i => $name) {
                $exists = DB::table('foodalchemist_target_groups')
                    ->where('team_id', $teamId)->where('name', $name)->exists();
                if ($exists) {
                    continue;
                }
                DB::table('foodalchemist_target_groups')->insert([
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

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_concept_target_groups');
        Schema::dropIfExists('foodalchemist_chapter_target_groups');
        Schema::dropIfExists('foodalchemist_foodbook_target_groups');
        Schema::dropIfExists('foodalchemist_target_groups');
    }
};
