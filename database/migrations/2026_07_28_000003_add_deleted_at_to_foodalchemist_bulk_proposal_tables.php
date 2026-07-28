<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 22 · H3c-2 — V-032, zweite Hälfte: die beiden Vorschlags-Speicher bekommen ihr Model.
 *
 * `foodalchemist_bulk_proposals` (2026-06-12) und `foodalchemist_bulk_gp_proposals`
 * (2026-07-01) sind nach H3a die letzten Fach-Tabellen des Moduls ohne Eloquent-Model;
 * jeder der achtzehn Zugriffe läuft über `DB::table()` mit handgeschriebener uuid und
 * handgeschriebenem `json_encode`/`json_decode`. Der Trait-Vertrag des Moduls
 * (`PolicyTest`) verlangt von jedem Model `SoftDeletes` — die Spalte kommt darum hier,
 * additiv und nullable, im selben Zug wie bei `bulk_runs` in H3a.
 *
 * Gelöscht wird auch hier nichts: ein Vorschlag ist ein Vorgang mit Endzuständen
 * (`offen` → `uebernommen` | `verworfen` | `leer`), kein Stammdatum. Die Spalte ist die
 * Zusicherung, dass die Vorschlags-Historie eines KI-Laufs — die Audit-Spur, an der die
 * Provider-Kosten und die Accept-/Reject-Stempel hängen — nicht hart verschwinden kann.
 *
 * ⚠️ Kein Rückbau, kein Backfill, keine Semantik-Änderung an `value`: der `array`-Cast der
 * neuen Models erzeugt exakt dieselbe Ablage-Form wie der bisherige Handbetrieb
 * (`tests/Feature/BulkProposalSpeicherGoldenTest.php` friert sie auf der Spalte ein).
 */
return new class extends Migration
{
    /** Beide Tabellen, dieselbe Spalte — je Tabelle einzeln geprüft, damit sich eine halb
     *  angewendete Migration beim nächsten Lauf heilt statt auszusteigen (Muster aus H3a). */
    private const TABELLEN = ['foodalchemist_bulk_proposals', 'foodalchemist_bulk_gp_proposals'];

    public function up(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            if (! Schema::hasTable($tabelle) || Schema::hasColumn($tabelle, 'deleted_at')) {
                continue;
            }
            Schema::table($tabelle, function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            if (! Schema::hasTable($tabelle) || ! Schema::hasColumn($tabelle, 'deleted_at')) {
                continue;
            }
            Schema::table($tabelle, function (Blueprint $table) {
                $table->dropColumn('deleted_at');
            });
        }
    }
};
