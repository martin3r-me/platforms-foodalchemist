<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R6.11 · S3 (E4) — FA-Lab-Journal: schlanke, team-eigene Notiz-Tabelle als Senke
 * für Hypothesen-/Widerspruchs-Ergebnisse (Vault-Write ist headless nicht verfügbar).
 * evidence_tier hält die Provenienz-Stufe (T3=Hypothese …); source_ref = freie
 * Herkunfts-Referenz (z. B. "hypothesis:anchor:2", "widerspruch:doc:5").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_lab_notes')) {
            return;
        }
        Schema::create('foodalchemist_lab_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('evidence_tier', 8)->default('T3')->comment('T0=kuratiert/belegt … T3=Hypothese');
            $table->string('source_ref')->nullable()->comment('freie Herkunft, z. B. hypothesis:anchor:2 / widerspruch:doc:5');
            $table->string('created_via', 32)->default('manual');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_lab_notes');
    }
};
