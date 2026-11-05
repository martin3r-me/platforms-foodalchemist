<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_wettbewerber', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $t->string('name', 190);
            $t->string('name_schluessel', 190);
            $t->string('website', 2048)->nullable();
            $t->json('aliases')->nullable();
            $t->json('suchbegriffe')->nullable();
            $t->json('ausschliessen')->nullable();
            $t->json('branchen')->nullable();
            $t->string('region', 2)->nullable();
            $t->string('gebiet', 190)->nullable();
            $t->text('notiz')->nullable();
            $t->boolean('aktiv')->default(true);
            $t->boolean('rss_aktiv')->default(false);
            $t->string('rhythmus', 20)->default('woechentlich');
            $t->foreignId('google_quelle_id')->nullable()->constrained('foodalchemist_trend_quellen', 'id', 'fa_wettbewerb_google_fk')->nullOnDelete();
            $t->foreignId('presse_quelle_id')->nullable()->constrained('foodalchemist_trend_quellen', 'id', 'fa_wettbewerb_presse_fk')->nullOnDelete();
            $t->json('quellen_ids')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['team_id', 'name_schluessel'], 'fa_wettbewerber_name_eindeutig');
        });
        Schema::create('foodalchemist_wettbewerb_funde', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $t->foreignId('wettbewerber_id')->constrained('foodalchemist_wettbewerber', 'id', 'fa_wettbewerb_profil_fk')->cascadeOnDelete();
            $t->foreignId('eingang_id')->constrained('foodalchemist_trend_eingang', 'id', 'fa_wettbewerb_eingang_fk')->cascadeOnDelete();
            $t->string('status', 20)->default('vorgeschlagen');
            $t->string('grund', 500);
            $t->string('bewegung', 30)->default('meldung');
            $t->text('einordnung')->nullable();
            $t->foreignId('geprueft_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('geprueft_at')->nullable();
            $t->timestamps();
            $t->unique(['wettbewerber_id', 'eingang_id'], 'fa_wettbewerb_fund_eindeutig');
            $t->index(['team_id', 'status'], 'fa_wettbewerb_pruefung');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_wettbewerb_funde');
        Schema::dropIfExists('foodalchemist_wettbewerber');
    }
};
