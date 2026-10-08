<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 79 · Trendradar nach Sarah Spork (BHG Trend Radar 2026): Inspiration → Hype → Trend.
 * Fundstücke (Beleg ohne Trend) liegen in der Team-Pinnwand; Trend als eigene Entität statt
 * Wissens-Dossier. Trend/Hype × Trendhierarchie (Mode → Konsum → Mega → Meta) × Kategorie,
 * Belege je Quelle (mit Datei, z. B. Instagram-Screenshot), Messreihen externer Quellen
 * (Google Trends über DataForSEO) und die Team-Schalter dafür. Additiv — die alten
 * Tabellen trend_meta/trend_taxonomy bleiben bis zum Rückbau stehen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_trends')) {
            Schema::create('foodalchemist_trends', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->string('name', 160);
                $table->string('slug', 180);
                $table->text('definition')->nullable();
                // Einordnung nach Sarah Spork — leer, solange niemand eingeordnet hat (Erfassung in < 60 s)
                $table->string('typ', 8)->nullable();              // trend | hype
                $table->string('ebene', 8)->nullable();            // mode | konsum | mega | meta
                $table->string('kategorie', 12)->nullable();       // food | getraenke | deko | event
                $table->string('food_cluster', 32)->nullable();    // Imbeck-Cluster, nur bei food
                $table->string('sicht', 16)->nullable();           // veranstalter | teilnehmer | beide (Events)
                $table->string('einordnung_quelle', 8)->nullable(); // manuell | ki
                $table->text('einordnung_begruendung')->nullable();
                // Konfidenz: aus den Belegen berechnet; konfidenz_manuell übersteuert
                $table->string('konfidenz', 8)->default('niedrig'); // hoch | mittel | niedrig
                $table->string('konfidenz_manuell', 8)->nullable();
                $table->boolean('befragung_bestaetigt')->default(false);
                $table->decimal('befragung_anteil', 5, 2)->nullable(); // Prozent der Befragten
                $table->string('status', 16)->default('gesichtet');  // gesichtet|geprueft|auf_radar|in_umsetzung|archiviert|verworfen
                $table->text('historische_einordnung')->nullable();
                $table->string('gartner_phase', 24)->nullable();
                $table->json('suchbegriffe')->nullable();          // Google Trends (DataForSEO)
                $table->json('hashtags')->nullable();              // Instagram-Beobachtung
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('geprueft_by')->nullable();
                $table->timestamp('geprueft_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['team_id', 'slug'], 'fa_trends_team_slug_uq');
                $table->index(['team_id', 'status'], 'fa_trends_team_status_idx');
            });
        }

        if (! Schema::hasTable('foodalchemist_trend_belege')) {
            Schema::create('foodalchemist_trend_belege', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                // NULL = Fundstück (Inspiration in der Team-Pinnwand), noch keinem Trend zugeordnet
                $table->unsignedBigInteger('trend_id')->nullable()->index();
                $table->string('quelle', 20);                      // siehe TrendVokabular::QUELLEN
                $table->string('titel', 255)->nullable();
                $table->string('url', 1000)->nullable();
                $table->text('notiz')->nullable();
                $table->json('schlagworte')->nullable();           // Fundstücke bündeln (Häufungen)
                $table->string('fundort', 160)->nullable();        // wo gesehen (Account, Lokal, Messe …)
                $table->date('beobachtet_am')->nullable();
                $table->decimal('anteil', 5, 2)->nullable();       // Befragung: Prozent der Nennungen
                $table->unsignedBigInteger('context_file_id')->nullable();
                $table->string('datei_name', 255)->nullable();
                $table->string('datei_mime', 100)->nullable();
                $table->unsignedBigInteger('signal_id')->nullable(); // automatisch aus einer Messung
                $table->boolean('fundstueck')->default(false);     // in der Pinnwand abgelegt (Inspiration)
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('foodalchemist_trend_signale')) {
            Schema::create('foodalchemist_trend_signale', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('trend_id')->nullable()->index();
                $table->string('quelle', 20);                      // google_trends
                $table->string('suchbegriff', 160);
                $table->string('region', 40)->nullable();
                $table->string('zeitraum', 32)->nullable();
                $table->json('werte')->nullable();                 // [{date_from, date_to, value}]
                $table->unsignedSmallInteger('durchschnitt')->nullable();
                $table->unsignedSmallInteger('spitze')->nullable();
                $table->date('spitze_am')->nullable();
                $table->decimal('veraenderung', 7, 2)->nullable(); // Punkte: letztes Viertel − erstes Viertel
                $table->string('richtung', 10)->nullable();        // steigend | stabil | fallend
                $table->boolean('spitze_ohne_sockel')->default(false); // Hype-Indiz
                $table->decimal('kosten_usd', 8, 4)->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['team_id', 'created_at'], 'fa_trend_signale_team_zeit_idx');
            });
        }

        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'trend_dataforseo_enabled')) {
                $table->boolean('trend_dataforseo_enabled')->default(false);
            }
            if (! Schema::hasColumn('foodalchemist_team_settings', 'trend_dataforseo_connection_id')) {
                $table->unsignedBigInteger('trend_dataforseo_connection_id')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_team_settings', 'trend_dataforseo_budget_usd')) {
                $table->decimal('trend_dataforseo_budget_usd', 8, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            foreach (['trend_dataforseo_enabled', 'trend_dataforseo_connection_id', 'trend_dataforseo_budget_usd'] as $spalte) {
                if (Schema::hasColumn('foodalchemist_team_settings', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
        Schema::dropIfExists('foodalchemist_trend_signale');
        Schema::dropIfExists('foodalchemist_trend_belege');
        Schema::dropIfExists('foodalchemist_trends');
    }
};
