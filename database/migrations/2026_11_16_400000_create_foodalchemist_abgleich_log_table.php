<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abgleich-Dienst (Entscheid Dominique 25.10.2026): Protokoll jeder Entscheidung „Lieferantenartikel → GP“ (verknüpfen | neu | unsicher),
 * damit Automatik und Vorschläge nachvollziehbar und zurücknehmbar sind. Kein Schattenbetrieb — die Automatik läuft direkt, das Log trägt die Rücknahme.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_abgleich_log')) {
            return;
        }
        Schema::create('foodalchemist_abgleich_log', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('supplier_item_id')->index();
            $table->string('modus', 16)->default('vorschlag')->comment('vorschlag | auto | mcp | eval');
            $table->string('aktion', 24)->comment('verknuepfen | neu | unsicher | blockiert | bereits_verknuepft');
            $table->unsignedBigInteger('gp_id')->nullable()->comment('Entscheidung: Ziel-GP (verknuepfen)');
            $table->decimal('sicherheit', 4, 3)->default(0);
            $table->string('stufe', 12)->nullable()->comment('S0 exakt | S2 ohne Kandidat | S6 KI | nonfood | verknuepft');
            $table->boolean('automatisch')->default(false)->comment('Schwelle erreicht (exakt ODER Sicherheit ≥ 0,90 + Rang 1 + Diff + Guard + approved)');
            $table->boolean('ausgefuehrt')->default(false);
            $table->string('ausgefuehrt_aktion', 16)->nullable()->comment('verknuepft | neu_angelegt');
            $table->unsignedBigInteger('ausgefuehrt_gp_id')->nullable()->index();
            $table->text('begruendung')->nullable();
            $table->json('abweichungen')->nullable();
            $table->json('kandidaten')->nullable();
            $table->json('felder')->nullable();
            $table->unsignedSmallInteger('ki_calls')->default(0);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('dauer_ms')->default(0);
            $table->timestamp('rueckgenommen_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'supplier_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_abgleich_log');
    }
};
