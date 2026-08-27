<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schicht 3 (2026-08-27) — die artefakt-AGNOSTISCHE Ablage der Konformitäts-Befunde.
 *
 * Anders als `foodalchemist_recipe_findings` (recipe-FK, Zutaten-Copilot) trägt sie
 * `artifact_type` + `artifact_id`, damit DERSELBE Konformitäts-Critic Rezepte, VK,
 * GP und LA ablegen kann — der Preis für „ein Pass für alle Artefakte".
 *
 * Ein Befund = EIN Regelverstoß an EINEM Artefakt, dedupliziert über `fingerprint`
 * (artefakt + § + betroffenes Feld — WERTFREI, wie bei den Recipe-Findings: ein neu
 * formulierter Grund erzeugt keine zweite Zeile). Zwei Läufe über dasselbe unveränderte
 * Artefakt erhöhen `seen_count` statt eine Dublette anzulegen.
 *
 * `status`: offen (Hinweis-Kandidat) · verworfen (bewusst liegengelassen, ein Folgelauf
 * öffnet ihn NICHT wieder) · verschwunden (im letzten Pass — meist nach der Selbstheil-
 * Runde — nicht mehr gemeldet). Team-Scoping strikt (kein visibleToTeam-Erben): der
 * Befund gehört dem messenden Team.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_conformance_findings')) {
            return;
        }

        Schema::create('foodalchemist_conformance_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();

            $table->string('artifact_type', 24);                      // recipe | gp | la (…)
            $table->unsignedBigInteger('artifact_id');

            $table->string('paragraph', 48)->nullable();              // §-Referenz aus dem Regelwerk
            $table->string('schweregrad', 8)->default('weich');       // hart | weich
            $table->string('feld', 191)->nullable();                  // betroffenes Artefakt-Element
            $table->text('reason');                                   // Begründung des Verstoßes
            $table->text('vorschlag')->nullable();                    // konforme Fassung, falls ableitbar
            $table->decimal('confidence', 4, 3)->default(0);

            $table->string('status', 16)->default('offen');           // offen | verworfen | verschwunden
            $table->string('fingerprint', 64);                        // Dedup-Schlüssel (sha1, wertfrei)
            $table->unsignedInteger('seen_count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('decided_at')->nullable();              // verworfen gestempelt
            $table->unsignedBigInteger('run_id')->nullable();         // Bookkeeping, bewusst ohne FK
            $table->timestamps();
            $table->softDeletes();

            // Ein Befund je Artefakt+Fingerprint (Dedup-Garantie auf DB-Ebene).
            $table->unique(['team_id', 'artifact_type', 'artifact_id', 'fingerprint'], 'fa_conf_finding_unique');
            // Leserichtung Leitstelle: „offene Befunde an DIESEM Artefakt".
            $table->index(['team_id', 'artifact_type', 'artifact_id', 'status'], 'fa_conf_finding_artifact_idx');
            // Leserichtung Cockpit: „offene Befunde über Schwelle" je Team.
            $table->index(['team_id', 'status', 'confidence'], 'fa_conf_finding_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_conformance_findings');
    }
};
