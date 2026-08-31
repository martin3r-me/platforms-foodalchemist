<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ebene 2: eigenes Präsentations-Logo je Betrieb. Beim Betriebs-Link (publishForOutlet) ersetzt es
 * das Dokument-Logo (Foodbook/Speisekarte/Speiseplan); nicht gesetzt → Dokument-Logo (Fallback).
 * Speicherung als Core-ContextFile (context_file_id) + Pfad, wie das Foodbook-Branding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_outlets', function (Blueprint $table) {
            $table->unsignedBigInteger('logo_context_file_id')->nullable()->after('presentation_design');
            $table->string('logo_path')->nullable()->after('logo_context_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_outlets', function (Blueprint $table) {
            $table->dropColumn(['logo_context_file_id', 'logo_path']);
        });
    }
};
