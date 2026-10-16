<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Spec 84 L1-B (Dominique 16.10.: „Die Vorlagen gehören dem Team.“) · Die globalen Brief-Vorlagen (team_id NULL, Seed aus
 * 2026_08_18_000001) werden je Hauptteam kopiert und danach gelöscht. Jede Vorlage gehört danach genau einem Team.
 *
 * Hauptteam = parent_team_id NULL UND personal_team = false (Kurator 16.10.; io: Teams 1–4 → 24 Kopien). Unter-Teams
 * bekommen keine eigene Kopie, sie sehen die ihres Oberteams über die Team-Kette.
 *
 * - Idempotent: ein Team, das den Slug schon hat (Unique team_id+slug), wird übersprungen; ohne NULL-Zeilen passiert nichts.
 * - Riegel: Kopieren und Löschen in EINER Transaktion; gelöscht wird erst, wenn jede fällige Kopie geschrieben ist.
 * - Verweise: keine gespeicherten (es gibt keine brief_template_id-Spalte; die Planung kopiert den Inhalt beim Chip-Klick).
 * - Gelöscht wird hart: die Zeilen sind Seed-Daten, und ein Soft-Delete ließe team_id-NULL-Leichen im Unique-Index stehen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_brief_templates') || ! Schema::hasTable('teams')) {
            return;
        }

        DB::transaction(function () {
            $globals = DB::table('foodalchemist_brief_templates')->whereNull('team_id')->whereNull('deleted_at')->get();
            if ($globals->isEmpty()) {
                return;
            }
            $teams = DB::table('teams')->whereNull('parent_team_id')
                ->when(Schema::hasColumn('teams', 'personal_team'), fn ($q) => $q->where('personal_team', false))
                ->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
            if ($teams === []) {
                return;   // frische Instanz ohne Teams: nichts zu verteilen, NULL-Zeilen bleiben (unsichtbar) stehen statt verloren zu gehen
            }

            $soll = 0;
            $geschrieben = 0;
            $now = now();
            foreach ($teams as $teamId) {
                $vorhanden = DB::table('foodalchemist_brief_templates')->where('team_id', $teamId)->pluck('slug')->all();
                foreach ($globals as $g) {
                    if (in_array($g->slug, $vorhanden, true)) {
                        continue;   // idempotent: Team hat den Slug schon
                    }
                    $soll++;
                    $geschrieben += DB::table('foodalchemist_brief_templates')->insert([
                        'uuid' => (string) Str::uuid7(),
                        'team_id' => $teamId,
                        'slug' => $g->slug,
                        'label' => $g->label,
                        'scope' => $g->scope,
                        'titel' => $g->titel,
                        'brief' => $g->brief,
                        'payload' => $g->payload,
                        'sort_order' => $g->sort_order,
                        'active' => $g->active,
                        'created_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]) ? 1 : 0;
                }
            }

            if ($geschrieben !== $soll) {
                throw new \RuntimeException("Spec 84 L1-B: nur {$geschrieben} von {$soll} Kopien geschrieben — globale Vorlagen bleiben stehen.");
            }

            $geloescht = DB::table('foodalchemist_brief_templates')->whereNull('team_id')->delete();
            Log::info('Spec 84 L1-B: Brief-Vorlagen je Hauptteam kopiert', [
                'hauptteams' => $teams, 'kopien' => $geschrieben, 'globale_geloescht' => $geloescht,
            ]);
        });
    }

    public function down(): void
    {
        // Bewusst kein Zurück: die Kopien gehören jetzt den Teams und können seitdem geändert worden sein.
    }
};
