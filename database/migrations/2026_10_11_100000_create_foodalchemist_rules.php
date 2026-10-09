<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 81 Teil C — Regeln als Daten.
 *
 * `foodalchemist_rules`: mechanische Regelwerk-Regeln (Vokabular, Ersetzung, Pflichtangabe, Verbot, Zuordnung,
 * Schwelle), ausgeführt vom Regel-Motor. `schluessel` ist der feste Name, über den der Code eine Regel holt
 * (z. B. `gp.9.zustand`) — Seeds bleiben so wiederholbar, Einsatzstellen hängen nicht an Titeln.
 * `foodalchemist_rule_versions`: jede gespeicherte Fassung (Zurückrollen = alte Fassung erneut speichern).
 * Befunde bekommen ihre Herkunft (`quelle` code|ki, `rule_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global (nur Master-Team schreibt)');
            $table->string('schluessel', 96);
            $table->string('regelwerk', 24)->comment('gp|basisrezept|la|vk|matching|haccp|…');
            $table->string('paragraph', 32)->nullable();
            $table->string('titel');
            $table->string('art', 16)->comment('vokabular|ersetzung|pflichtangabe|verbot|zuordnung|schwelle');
            $table->string('ziel', 48)->comment('gp.name|gp.zustand|rezept.name|rezeptzeile|la.match|…');
            $table->string('wirkung', 16)->default('warnen')->comment('korrigieren|warnen|blockieren');
            $table->json('params');
            $table->json('beispiele')->nullable();
            $table->boolean('aktiv')->default(false)->comment('Neue Regeln starten inaktiv; Aktivieren = Kuration');
            $table->unsignedInteger('version')->default(1);
            $table->text('notiz')->nullable();
            $table->string('dossier_slug')->nullable()->comment('Dossier, aus dem die Regel stammt (Teil E)');
            $table->string('created_via', 16)->default('seed');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'schluessel']);
            $table->index(['ziel', 'aktiv']);
        });

        Schema::create('foodalchemist_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id')->index();
            $table->unsignedInteger('version');
            $table->string('wirkung', 16);
            $table->json('params');
            $table->json('beispiele')->nullable();
            $table->boolean('aktiv');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['rule_id', 'version']);
        });

        Schema::table('foodalchemist_conformance_findings', function (Blueprint $table) {
            $table->string('quelle', 8)->default('ki')->after('confidence');
            $table->unsignedBigInteger('rule_id')->nullable()->after('quelle');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_conformance_findings', function (Blueprint $table) {
            $table->dropColumn(['quelle', 'rule_id']);
        });
        Schema::dropIfExists('foodalchemist_rule_versions');
        Schema::dropIfExists('foodalchemist_rules');
    }
};
