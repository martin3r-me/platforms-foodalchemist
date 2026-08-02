<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stufe 3 P3.1 — Küchen-Rollen mit Kostensatz (Küchenchef / Koch / Hilfskoch …).
 *
 * ⚠️ ARCHITEKTUR-ENTSCHEIDUNG (Dominique, 2026-08-02) — Fortschreibung der Grenze von
 * 2026-08-01 („Posten statt Personen"): Ab hier gibt es ROLLEN mit Sätzen, und ein Posten
 * wird mit einer ANZAHL je Rolle besetzt. Daraus folgt Kapazität (Köpfe × Schicht-Minuten)
 * UND Produktionskosten (Σ Rollensatz × Minuten).
 *
 *   Erlaubt ist ausschließlich die ROLLEN-/POSTEN-Ebene (Aggregat). NICHT erlaubt bleiben:
 *   namentliche Personen, Schichtpläne, Verfügbarkeiten, Abwesenheiten, Personalstammdaten,
 *   Stundenkonten und JEDE Aggregation je Person. Kein `user_id`, kein Namensfeld hier.
 *   Die Wand von 2026-08-01 gilt weiter — sie wird nur um „Rolle als Kostenträger" ergänzt.
 *
 * Doku: docs/PLANUNG/30_Produktion_Ausbau.md §1.
 *
 * Eigene Tabelle, KEIN `foodalchemist_vocab_*` (dieselben drei Gründe wie bei den Posten,
 * Migration 2026_08_01_000004): Vokabular wird beim Import geleert, `slug` ist global unique,
 * und der Satz ist standort-/team-eigen. Daher `team_id` NOT NULL, kein globaler Seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_kitchen_roles')) {
            return;
        }

        Schema::create('foodalchemist_kitchen_roles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index()
                ->comment('NOT NULL — Rollen sind team-eigene Betriebsstammdaten, kein globaler Seed');

            $table->string('slug');
            $table->string('name')->comment('Rolle, kein Mensch: „Küchenchef" / „Koch" / „Hilfskoch"');

            $table->decimal('stundensatz_eur', 8, 2)->nullable()
                ->comment('Kostensatz €/Std der Rolle. NULL = fällt auf den flachen Team-Stundensatz zurück.');

            $table->unsignedInteger('sort_order')->default(100);
            $table->boolean('is_inactive')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'slug'], 'fa_kitchen_roles_team_slug_uq');
            $table->index(['team_id', 'sort_order'], 'fa_kitchen_roles_team_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_kitchen_roles');
    }
};
