<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KI-Abrechnung je Mandant (16.10.2026): wer hat einen Aufruf verursacht und was hat er gekostet.
 *
 * `team_id` bleibt das Daten-Team (Sichtbarkeit in den Einstellungen). Neu fürs Geld:
 *  - abrechnung_team_id: Kunden-Hauptteam des Auslösers (Unter-Team → sein Hauptteam), NULL bei „plattform“/„unbekannt“
 *  - verursacher: mandant | plattform | unbekannt (plattform = Master-Team und Läufe ohne Nutzer, nicht abrechenbar)
 *  - kosten_usd + preis_* + usd_eur: Preis und Kurs EINGEFROREN je Aufruf (spätere Preisänderungen betreffen nur neue Aufrufe);
 *    kosten_usd NULL = Modell ohne Preis (nie 0)
 * Keine Rückfüllung: Altzeilen bleiben NULL und zählen nicht zur Abrechnung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_ai_call_log', function (Blueprint $table) {
            $table->unsignedBigInteger('abrechnung_team_id')->nullable()->after('team_id');
            $table->string('verursacher', 12)->nullable()->after('abrechnung_team_id');
            $table->decimal('kosten_usd', 14, 8)->nullable()->after('tokens_out');
            $table->decimal('preis_in_mio', 10, 4)->nullable()->after('kosten_usd');
            $table->decimal('preis_cached_mio', 10, 4)->nullable()->after('preis_in_mio');
            $table->decimal('preis_out_mio', 10, 4)->nullable()->after('preis_cached_mio');
            $table->decimal('usd_eur', 10, 6)->nullable()->after('preis_out_mio');

            $table->index(['abrechnung_team_id', 'created_at'], 'fa_ai_log_abrechnung_idx');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_ai_call_log', function (Blueprint $table) {
            $table->dropIndex('fa_ai_log_abrechnung_idx');
            $table->dropColumn(['abrechnung_team_id', 'verursacher', 'kosten_usd', 'preis_in_mio', 'preis_cached_mio', 'preis_out_mio', 'usd_eur']);
        });
    }
};
