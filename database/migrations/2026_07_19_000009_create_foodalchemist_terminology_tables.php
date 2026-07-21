<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #507 Weg-2 · E7-b: Promotion der Terminologie-Schicht von kuratierten PHP-Konstanten
 * ({@see \Platform\FoodAlchemist\Services\TerminologyService}) auf runtime-pflegbare
 * DB-Tabellen. Die Konstanten bleiben Baseline-Seed im Code; DIESE Tabellen sind
 * ADDITIV — Lernschleife (E7-c, ReviewQueue) + MCP (terminology.POST) schreiben hier
 * hinein, damit neue Namen/Verwechslungen OHNE Deploy sofort wirken.
 *
 * Governance (Dominique 2026-07-19): FA ist Master der Terminologie. team_id NULL =
 * globaler Master-Bestand (kuratiert); team-eigene Zeilen (später) bleiben additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_terminology_aliases')) {
            Schema::create('foodalchemist_terminology_aliases', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = globaler Master');
                $table->json('members')->comment('Satz bedeutungsgleicher Phrasen (lowercase)');
                $table->string('note')->nullable();
                $table->string('source')->nullable()->comment('Provenienz, z. B. Substitutionen.md / reviewqueue:123');
                $table->string('created_via', 32)->default('manual');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('foodalchemist_terminology_anti_markers')) {
            Schema::create('foodalchemist_terminology_anti_markers', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = globaler Master');
                $table->string('trigger_token')->comment('taucht dieses Token in der Query auf …');
                $table->string('forbid_token')->comment('… unterdrücke Kandidaten mit diesem Token');
                $table->string('unless_token')->nullable()->comment('Guard: legitimer Treffer trägt dieses Token');
                $table->string('note')->nullable();
                $table->string('source')->nullable()->comment('Provenienz, z. B. Anti_Marker.md / reviewqueue:123');
                $table->string('created_via', 32)->default('manual');
                $table->timestamps();
                $table->softDeletes();
                // Expliziter kurzer Name: der Auto-Name (71 Z.) sprengt MySQLs 64-Zeichen-Limit → fresh migrate crasht.
                $table->index(['trigger_token', 'forbid_token'], 'fa_term_anti_trigger_forbid_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_terminology_anti_markers');
        Schema::dropIfExists('foodalchemist_terminology_aliases');
    }
};
