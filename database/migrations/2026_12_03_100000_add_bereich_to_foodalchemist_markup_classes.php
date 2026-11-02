<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94 · Aufschlagsklassen bekommen einen Bereich: `speisen` (Bestand, Concepter und Darreichungen) oder `leistung`
 * (Personal, Equipment, Logistik im Konfigurator). So mischen sich Leistungs- und Speisenklassen nie in einer Auswahl.
 * Rein additiv: Default `speisen`, der Bestand bleibt unverändert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_markup_classes', 'bereich')) {
            return;
        }
        Schema::table('foodalchemist_markup_classes', function (Blueprint $table) {
            $table->string('bereich', 16)->default('speisen')->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_markup_classes', 'bereich')) {
            Schema::table('foodalchemist_markup_classes', function (Blueprint $table) {
                $table->dropIndex(['bereich']);
                $table->dropColumn('bereich');
            });
        }
    }
};
