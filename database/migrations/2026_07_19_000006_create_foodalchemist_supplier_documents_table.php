<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R9.1 (E3) — Vertrags-/Dokumenten-Ablage je Lieferant. v1: Metadaten + externer
 * File-Ref + Laufzeit + Kündigungsfrist (notice_period_days) → speist das
 * Fristen-Signal (E7). Echter S3-Upload später (Martin).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_supplier_documents')) {
            return;
        }
        Schema::create('foodalchemist_supplier_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('supplier_id')->constrained('foodalchemist_suppliers')->cascadeOnDelete();
            $table->string('kind', 32)->default('vertrag')->comment('vertrag|rahmenvereinbarung|zertifikat|sonstiges');
            $table->string('title')->nullable();
            $table->string('file_ref')->nullable()->comment('externer Datei-Verweis (URL/Pfad); echter Upload später');
            $table->date('term_start')->nullable();
            $table->date('term_end')->nullable()->index();
            $table->unsignedInteger('notice_period_days')->nullable()->comment('Kündigungsfrist in Tagen');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_supplier_documents');
    }
};
