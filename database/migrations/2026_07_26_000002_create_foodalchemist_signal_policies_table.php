<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 21 · E2 — Rausch-Guard. Eine Policy je Signal-Typ und Team: ab wann ist ein
 * Befund kein Einzel-Alarm mehr, sondern ein bekannter *Zustand*?
 *
 * Der Anlass steht in der Spec (§6): 3.138 „im Review" bzw. 788 teil-unbepreiste
 * Basisrezepte sind kein Signal, sondern eine Lage. Was chronisch vierstellig zählt,
 * stumpft das Cockpit ab — mit Tranche A+C+D kämen ~900 offene Signale statt 252.
 *
 * Die drei Regler sind bewusst getrennt, weil sie drei verschiedene Aussagen sind:
 *  - `threshold`      — „ab n Treffern zeig mir EINE Zustands-Zeile statt n Zeilen"
 *                       (nur Darstellung; die Einzel-Signale bleiben vollständig da
 *                       und sind über den Typ-Filter jederzeit aufklappbar).
 *  - `accepted_until` — „ich kenne die Lage und akzeptiere sie bis TT.MM." (nimmt der
 *                       Zustands-Zeile den Alarm-Charakter, nicht die Sichtbarkeit;
 *                       nach Fristablauf wird sie wieder zum Alarm).
 *  - `muted`          — „interessiert mich hier gar nicht" (einziger Regler, der auch
 *                       das Drift-Meta-Signal E3 unterdrückt).
 *
 * Nicht unterdrückt wird die *Veränderung*: neu hinzukommende Fälle schlagen weiter
 * als `qualitaet_drift` durch (Delta-Alarm statt Absolut-Alarm, Spec §6 E2/E3) —
 * sonst könnte man eine wachsende Lücke wegkonfigurieren.
 *
 * Gesetzt wird ausschließlich menschlich (UI-Regler bzw. MCP `signal_policy.PUT`),
 * nie von einem Detektor — ein System, das seine eigenen Alarme stummschaltet, ist
 * kein Guard mehr.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_signal_policies')) {
            return;
        }
        Schema::create('foodalchemist_signal_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('type', 64);                          // SignalTyp-Wert
            $table->unsignedInteger('threshold')->nullable();     // ab hier aggregierte Zustands-Zeile
            $table->date('accepted_until')->nullable();           // bewusst akzeptiert bis …
            $table->text('note')->nullable();                     // Begründung (steht in der Zustands-Zeile)
            $table->boolean('muted')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // Eine Policy je Typ und Team; Kind-Teams erben die Eltern-Policy lesend
            // (BelongsToTeamHierarchy) und können sie mit einer eigenen Zeile überstimmen.
            $table->unique(['team_id', 'type'], 'fa_signal_policy_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_signal_policies');
    }
};
