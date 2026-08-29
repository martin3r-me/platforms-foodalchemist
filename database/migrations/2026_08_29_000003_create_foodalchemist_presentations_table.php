<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice F (publish-per-Betrieb): zusätzliche, BETRIEB-scopte Präsentationen NEBEN der
 * inline-Präsentation am Dokument-Kopf (HasPresentation). Ein Dokument behält seinen
 * Standard-Link; je Betrieb kann EINE weitere Präsentation dazukommen — eigener Token/
 * Slug/Snapshot/Design + eigene Freigabe, eingefroren mit den Preisen UND der Vorlage
 * dieses Betriebs. Additiv, kein Umbau des bestehenden Kopf-Präsentations-Systems.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_presentations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('presentable_type', 32);            // foodbook | speisekarte | speiseplan
            $table->unsignedBigInteger('presentable_id');
            $table->unsignedBigInteger('team_id')->index();    // Tenancy (Besitzer-Team)
            $table->unsignedBigInteger('outlet_id')->index();
            $table->boolean('enabled')->default(true);         // Freigabe an/aus
            $table->string('token', 64)->unique();
            $table->string('slug')->nullable()->unique();
            $table->string('design', 48)->default('editorial');
            $table->json('snapshot_json')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            // Genau EINE Betriebs-Präsentation je (Dokument, Betrieb).
            $table->unique(['presentable_type', 'presentable_id', 'outlet_id'], 'fa_pres_doc_outlet_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_presentations');
    }
};
