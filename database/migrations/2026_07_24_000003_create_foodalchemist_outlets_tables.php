<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 19 „Foodbook-Leitstelle A–Z" — E3.6 (M5-Hälfte, abkoppelbar): Outlet-Vokabular.
 *
 * Outlet ist ausdrücklich NUR ein optionaler Tag (Entscheidung 4) — KEINE primäre
 * Planungs-Ebene und NICHT Teil von `leitplanken()`/der Dimensions-Kaskade. Er dient der
 * betrieblichen Zuordnung eines Kapitels zu einer Ausgabestelle (Restaurant/Bankett/Bar …)
 * mit optionaler Farbe fürs spätere Tag-Rendering.
 *
 * FA-nativ (kein WaWi-Spiegel), team-eigen, team-pflegbar über die Einstellungen.
 * KEINE Seeds — Outlets sind rein team-definiert (anders als das Zielgruppen-Set aus M1).
 *
 * Additiv/idempotent (Live-Daten): neue Vokabel-Tabelle + eine nullable Tag-Spalte auf der
 * Bestandstabelle `foodalchemist_foodbook_chapters`. Gemäß Spec-Regel werden ALTERs auf
 * Bestandstabellen als `unsignedBigInteger + index` statt harter FK geführt.
 *
 * Die zweite M5-Hälfte (`foodbook_blocks.presentation_id` + `DarreichungResolver::fuerBlock`)
 * ist bewusst NICHT hier, sondern bleibt Teilschritt E7.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_outlets')) {
            Schema::create('foodalchemist_outlets', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->string('name');
                $table->string('color', 7)->nullable(); // Hex „#rrggbb" fürs Tag-Rendering
                $table->integer('sort_order')->default(100);
                $table->boolean('is_inactive')->default(false);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['team_id', 'name'], 'fa_outlets_team_name_unique');
            });
        }

        // Tag-Spalte am Kapitel (Bestandstabelle → unsignedBigInteger + index statt FK).
        if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'outlet_id')) {
            Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
                $table->unsignedBigInteger('outlet_id')->nullable()->index()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_foodbook_chapters', 'outlet_id')) {
            Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
                $table->dropIndex(['outlet_id']);
                $table->dropColumn('outlet_id');
            });
        }
        Schema::dropIfExists('foodalchemist_outlets');
    }
};
