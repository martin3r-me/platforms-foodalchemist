<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §6 / Schritt 5: Flag „Standardausstattung" am Koch-Equipment-Vokabular.
 *
 * Standardausstattung (Topf, Pfanne …) gilt in jedem Teamprofil als vorhanden, solange das Team sie nicht
 * AUSDRÜCKLICH als nicht_vorhanden markiert. Damit warnt der Machbarkeits-Check nicht an halben Katalogen.
 * Default = aus; nur eine Spalte, keine Datenänderung (welche Einträge Standard sind, bestätigt Dominique).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_vocab_kitchen_equipment', 'is_standard')) {
            return;
        }
        Schema::table('foodalchemist_vocab_kitchen_equipment', function (Blueprint $table) {
            $table->boolean('is_standard')->default(false)->comment('Standardausstattung: gilt als vorhanden, solange nicht ausdrücklich nicht_vorhanden (Spec 82 §6)');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_vocab_kitchen_equipment', 'is_standard')) {
            Schema::table('foodalchemist_vocab_kitchen_equipment', fn (Blueprint $table) => $table->dropColumn('is_standard'));
        }
    }
};
