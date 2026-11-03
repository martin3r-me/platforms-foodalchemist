<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94 · Betrieb am Konfigurator (wie outlet_id am Foodbook-Kopf): ohne eigenes Logo gilt beim Veröffentlichen das Logo
 * dieses Betriebs und wird im Snapshot eingefroren (Dominique 2026-11-03). Additiv, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_konfiguratoren', 'outlet_id')) {
            Schema::table('foodalchemist_konfiguratoren', function (Blueprint $table) {
                $table->unsignedBigInteger('outlet_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_konfiguratoren', 'outlet_id')) {
            Schema::table('foodalchemist_konfiguratoren', function (Blueprint $table) {
                $table->dropIndex(['outlet_id']);
                $table->dropColumn('outlet_id');
            });
        }
    }
};
