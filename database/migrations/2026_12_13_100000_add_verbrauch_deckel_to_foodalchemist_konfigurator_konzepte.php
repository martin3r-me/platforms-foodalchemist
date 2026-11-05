<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94c · Stufe 8c: Getränkepaket nach Verbrauch mit optionalem Höchstbetrag (Deckel) für das ganze Event. Die Schätzung läuft
 * über die Einzelabrechnung (zzgl.), der Deckel begrenzt sie im Link und steht an der Angebots-Position (payload_json.deckel). Additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_konfigurator_konzepte', 'verbrauch_deckel')) {
            Schema::table('foodalchemist_konfigurator_konzepte', fn (Blueprint $t) => $t->decimal('verbrauch_deckel', 10, 2)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_konfigurator_konzepte', 'verbrauch_deckel')) {
            Schema::table('foodalchemist_konfigurator_konzepte', fn (Blueprint $t) => $t->dropColumn('verbrauch_deckel'));
        }
    }
};
