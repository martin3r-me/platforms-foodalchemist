<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 22 · H4a — V-009: ein Signal, das immer wiederkehrt, war von einem einmaligen
 * nicht zu unterscheiden.
 *
 * Der Docblock der Ursprungs-Migration (2026_06_16_000103) verspricht „Severity, Status,
 * **Historie** und Dedup" — die Historie ist die eine der vier Zusagen, die faktisch
 * fehlte: die Tabelle trägt `created_at`, `erledigt_at`, `ignoriert_at`, aber keinen
 * Zeitpunkt und keinen Zähler für „wieder gesehen". Der Dedup-Zweig in
 * `SignalService::erzeuge()` schrieb bisher nur Severity/Titel/Beschreibung/Payload fort;
 * ein Dauerbrenner, der heute Nacht zum sechzigsten Mal zugeschlagen hat, sah aus wie
 * ein Einzelfund vom Anlage-Tag — und sank in der nach `created_at` sortierten Inbox
 * für immer nach unten.
 *
 * Fachlich ist genau das die interessante Unterscheidung: ein Befund, der nach jedem Fix
 * zurückkommt, ist ein Prozess-Problem, kein Datenfehler.
 *
 * **Additiv und ohne Backfill.** `seen_count` startet bei 1 (= „einmal gesehen", die
 * Anlage selbst), `last_seen_at` bleibt bei Alt-Zeilen NULL statt auf `created_at`
 * geraten zu werden: „wir wissen es nicht" ist für eine Historie die ehrlichere Angabe
 * als ein Wert, der eine Sichtung behauptet, die nie protokolliert wurde. Ab der ersten
 * Wieder-Emission trägt jede Zeile echte Werte.
 *
 * Nicht dabei (bewusst): `reopened_count` bzw. der Verweis auf die Vorgänger-Zeile über
 * geschlossene Signale hinweg. Der Dedup greift nur auf offene Zeilen (eingefroren in
 * `SignalLifecycleGoldenTest`), und eine Kette über Status-Grenzen ist eine eigene
 * Lifecycle-Entscheidung — sie steht in V-009 als zweiter Halbsatz und wartet dort.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_signals')) {
            return;
        }
        Schema::table('foodalchemist_signals', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_signals', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_signals', 'seen_count')) {
                $table->unsignedInteger('seen_count')->default(1);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_signals')) {
            return;
        }
        foreach (['last_seen_at', 'seen_count'] as $spalte) {
            if (! Schema::hasColumn('foodalchemist_signals', $spalte)) {
                continue;
            }
            Schema::table('foodalchemist_signals', function (Blueprint $table) use ($spalte) {
                $table->dropColumn($spalte);
            });
        }
    }
};
