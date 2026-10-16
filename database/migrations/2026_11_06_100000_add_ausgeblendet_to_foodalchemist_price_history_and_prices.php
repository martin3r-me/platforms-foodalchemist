<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preis-Stand als Fehler ausblenden (Dominique, 16.10.2026: ein Tag mit 63,82 € statt 1,82 €, Erfassungsfehler beim Lieferanten).
 * Nicht löschen: die Zeile bleibt, wird aber aus Linie, Trend und Statistik genommen und steht in „Frühere Preise" durchgestrichen mit Grund.
 * `ausgeblendet_preis` = der Preis, der ausgeblendet wurde: korrigiert Necta die Preiszeile später auf einen anderen Wert, gilt das Flag
 * nicht mehr (nur gegen den Fehlerwert). Historie-Zeilen ändert Necta nie; ihre Idempotenz läuft über `necta_historie_id` (insertOrIgnore).
 */
return new class extends Migration
{
    private const TABELLEN = ['foodalchemist_price_history', 'foodalchemist_prices'];

    public function up(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            if (Schema::hasColumn($tabelle, 'ausgeblendet_at')) {
                continue;
            }
            Schema::table($tabelle, function (Blueprint $table) {
                $table->timestamp('ausgeblendet_at')->nullable()->index();
                $table->unsignedBigInteger('ausgeblendet_von')->nullable();
                $table->string('ausgeblendet_grund', 500)->nullable();
                $table->decimal('ausgeblendet_preis', 14, 4)->nullable()->comment('der ausgeblendete Wert; ändert sich der Preis der Zeile, gilt das Flag nicht mehr');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            if (Schema::hasColumn($tabelle, 'ausgeblendet_at')) {
                Schema::table($tabelle, function (Blueprint $table) {
                    $table->dropIndex(['ausgeblendet_at']);
                    $table->dropColumn(['ausgeblendet_at', 'ausgeblendet_von', 'ausgeblendet_grund', 'ausgeblendet_preis']);
                });
            }
        }
    }
};
