<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wissens-Kanäle je KI-Call (2026-09-06, „man sieht in der UI nie komplett, was benutzt wurde").
 *
 * `knowledge_used` bleibt die flache Audit-Liste (GL-13 §6, Dedup-/Vergleichs-Vertrag) und wird
 * NICHT angetastet. Daneben braucht das Rezept-Detail aber die Sicht des Kontext-Inspektors:
 * WELCHER Kanal ein Dossier in den Prompt gebracht hat — Kanon (verbindlich), Cross-Cutting,
 * Domäne, Niveau, Pairing, … Diese Zuordnung kennt nur der Gateway zum Zeitpunkt des Calls;
 * sie später aus der flachen Liste zu rekonstruieren wäre ein Etikett, das lügt (der Kanon
 * ändert sich, die Liste nicht). Darum: eigene JSON-Spalte, geschrieben im selben Insert.
 *
 * Form: {"kanon": ["slug@v2", …], "cross_cutting": […], "domain": […], …} — leere Kanäle fehlen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_ai_call_log', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_ai_call_log', 'knowledge_channels')) {
                $table->json('knowledge_channels')->nullable()->after('knowledge_used')
                    ->comment('Wissens-Dossiers je Kanal (kanon/gebunden/cross_cutting/domain/…) — Inspektor-Sicht des Calls');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_ai_call_log', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_ai_call_log', 'knowledge_channels')) {
                $table->dropColumn('knowledge_channels');
            }
        });
    }
};
