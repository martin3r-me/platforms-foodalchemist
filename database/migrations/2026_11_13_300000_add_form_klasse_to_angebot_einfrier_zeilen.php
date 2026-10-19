<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eingefrorenes Angebot hat feste Preise (Spec 82 §12 B3, Dominique 19.10.: ALLE Gerichte beim Versand, nicht nur Abo-Gerichte).
 * Je Zeile zusätzlich: Standard-Darreichung und Aufschlagsklasse (Nachvollziehbarkeit) und der VK je Darreichung/Form
 * (`vk_je_form` = {presentation_id: vk|null}) — Konzept-Slots können eine andere Form als den Standard tragen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_angebot_einfrier_zeilen', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_angebot_einfrier_zeilen', 'presentation_id')) {
                $table->unsignedBigInteger('presentation_id')->nullable()->comment('Standard-Darreichung beim Einfrieren (ohne FK, der Stand bleibt)');
            }
            if (! Schema::hasColumn('foodalchemist_angebot_einfrier_zeilen', 'markup_class_id')) {
                $table->unsignedBigInteger('markup_class_id')->nullable()->comment('Aufschlagsklasse der Standard-Darreichung beim Einfrieren');
            }
            if (! Schema::hasColumn('foodalchemist_angebot_einfrier_zeilen', 'vk_je_form')) {
                $table->json('vk_je_form')->nullable()->comment('{presentation_id: vk|null} — NULL-VK bei unvollständigem EK, nie 0');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_angebot_einfrier_zeilen', function (Blueprint $table) {
            foreach (['presentation_id', 'markup_class_id', 'vk_je_form'] as $c) {
                if (Schema::hasColumn('foodalchemist_angebot_einfrier_zeilen', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
