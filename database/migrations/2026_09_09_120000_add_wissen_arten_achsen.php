<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('foodalchemist_knowledge_documents', function (Blueprint $table) {
            $table->json('geltung')->nullable();
            $table->json('datenwerte')->nullable();
        });
        Schema::table('foodalchemist_knowledge_routings', function (Blueprint $table) {
            $table->string('category', 24)->nullable()->change();
            $table->string('art', 24)->nullable();
            $table->unique(['feature', 'art'], 'fa_wissen_routing_art_unique');
        });
    }
    public function down(): void
    {
        // Kein Datenverlust durch stilles Zurückübersetzen von Arten in Kategorien.
        if (\Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_routings')->whereNotNull('art')->exists()) {
            throw new RuntimeException('Arten-Routings vor dem Rollback explizit entfernen.');
        }
        Schema::table('foodalchemist_knowledge_routings', function (Blueprint $table) {
            $table->dropUnique('fa_wissen_routing_art_unique');
            $table->dropColumn('art');
            $table->string('category', 24)->nullable(false)->change();
        });
        Schema::table('foodalchemist_knowledge_documents', fn (Blueprint $table) => $table->dropColumn(['geltung', 'datenwerte']));
    }
};
