<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foodbook-PDF-Redesign: pro-Foodbook-Branding fürs versendbare Vertriebs-Dokument.
 * Marken-Farbe (Default = bisheriges Violett → Bestand unverändert), optionale Bandfarbe,
 * Logo- + Cover-Bildpfad (public-Disk) und Footer-Text. Additiv, rückwärtskompatibel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'brand_color')) {
                $table->string('brand_color')->default('#6d28d9'); // Primär-/Akzentfarbe (Pipes, Header, Bänder)
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'band_color')) {
                $table->string('band_color')->nullable();          // Kopf-/Fußband; null → aus brand_color abgeleitet
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'logo_path')) {
                $table->string('logo_path')->nullable();           // storage/app/public/foodalchemist/branding/{id}/...
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'cover_image_path')) {
                $table->string('cover_image_path')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'footer_text')) {
                $table->string('footer_text')->nullable();         // Marken-URL/Absender-Zeile; null → Default-Footer
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            foreach (['brand_color', 'band_color', 'logo_path', 'cover_image_path', 'footer_text'] as $col) {
                if (Schema::hasColumn('foodalchemist_foodbooks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
