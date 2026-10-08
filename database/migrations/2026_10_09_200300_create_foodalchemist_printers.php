<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec 78 · Druckerprofile (Format + Ränder je Drucker) und Zuordnung der Etikettenvorlage. Additiv. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_printers')) {
            Schema::create('foodalchemist_printers', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->string('name', 120);
                $table->string('modell', 32)->default('eigen');
                $table->string('format', 16)->default('rolle_62');    // Format-Key oder „eigen"
                $table->decimal('breite_mm', 6, 1)->nullable();
                $table->decimal('hoehe_mm', 6, 1)->nullable();
                $table->decimal('versatz_x_mm', 5, 1)->default(0);
                $table->decimal('versatz_y_mm', 5, 1)->default(0);
                $table->unsignedBigInteger('outlet_id')->nullable();
                $table->string('arbeitsplatz', 16)->nullable();      // kueche | lager | buero
                $table->boolean('is_default')->default(false);       // Standard je Arbeitsplatz
                $table->string('notiz', 200)->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
        Schema::table('foodalchemist_label_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_label_templates', 'printer_id')) {
                $table->unsignedBigInteger('printer_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_label_templates', function (Blueprint $table) {
            $table->dropColumn('printer_id');
        });
        Schema::dropIfExists('foodalchemist_printers');
    }
};
