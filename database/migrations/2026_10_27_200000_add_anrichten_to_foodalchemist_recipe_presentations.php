<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 83 B3i/B3l (Entscheide Dominique 14.10.2026): Anrichte-Zeit je DARREICHUNG eines Gerichts.
 *
 *  - anrichten_wert: Zeit je Einheit der Form. Die Einheit hängt an der Servierform (ProductionTimeService::ANRICHTEN_FORMEN):
 *    Teller/Box/Theke/Foodtruck = Sekunden je Portion · Flying/Fingerfood = Sekunden je Stück ·
 *    Buffet/Pause/Sharing = Minuten je Platte bzw. Ausgabe-Behälter · Live-Station = 0 (vor Ort). NULL = Startwert.
 *  - anrichten_pauschal_min: Pauschale je Auftragszeile (Rüsten am Pass), Minuten. NULL = Startwert 0.
 *
 * Bewusst NICHT `work_time_surcharge_min` (WaWi-Import, ganze Minuten, wird nur angezeigt, Bedeutung offen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipe_presentations', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipe_presentations', 'anrichten_wert')) {
                $table->decimal('anrichten_wert', 8, 2)->nullable()->after('work_time_surcharge_min');
            }
            if (! Schema::hasColumn('foodalchemist_recipe_presentations', 'anrichten_pauschal_min')) {
                $table->decimal('anrichten_pauschal_min', 8, 2)->nullable()->after('anrichten_wert');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipe_presentations', function (Blueprint $table) {
            foreach (['anrichten_wert', 'anrichten_pauschal_min'] as $spalte) {
                if (Schema::hasColumn('foodalchemist_recipe_presentations', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
