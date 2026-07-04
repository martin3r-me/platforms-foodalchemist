<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #380 — Kunden-Modul „Angebote": individuelle Anfrage → maßgeschneidertes
 * Angebot. Zweiter, eigenständiger Ansatz neben Foodbook (Portfolio) — KEIN
 * Kollaps (Dominique-Entscheid 2026-06-16). Gebaut wird im Concepter; dieses
 * Modul ist der Kunden- & Vertriebs-Mantel.
 *
 * FUNDAMENT (fork-unabhängig): der Angebot-Wrapper. Die Positions-/Assembly-
 * Ebene (Blocks/Referenzen auf Concepts/Pakete) folgt beim Composer — die
 * Grenze Foodbook ↔ Angebote wird dort gezogen.
 *
 * Konventionen (07 §7 + Martins 7-Punkte-Liste): engine-agnostisch — KEINE
 * CHECK-Constraints (status = string, Enum AngebotStatus im PHP-Layer), KEINE
 * cross-modul-FKs auf crm_* (Modul-Ladereihenfolge fragil) → crm-Referenzen
 * als schlichte indexierte Spalten, Integrität in den Model-Relationen.
 * team_id nullable + index (BelongsToTeamHierarchy), wie im ganzen Modul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_offers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();

            $table->string('name')->nullable();                       // Angebots-Titel (intern)
            $table->string('offer_number')->nullable()->index();    // fortlaufende Nr. (App-Logik), optional
            $table->string('status', 16)->default('anfrage')->index();// AngebotStatus (PHP-Enum)

            // CRM-Verknüpfung (MVP: nur Kontakt/Firma verlinken, kein Rücksync).
            // Schlichte indexierte Spalten — kein harter cross-modul-FK (Ladereihenfolge).
            $table->unsignedBigInteger('crm_company_id')->nullable()->index();
            $table->unsignedBigInteger('crm_contact_id')->nullable()->index();

            // Anfrage / Briefing (nativ — funktioniert OHNE Canvas; Canvas = Phase 2).
            $table->string('anlass')->nullable();                     // Hochzeit, Firmenfeier …
            $table->unsignedInteger('personen')->nullable();          // Pax
            $table->decimal('budget', 10, 2)->nullable();             // Kunden-Budget (Richtwert)
            $table->date('event_date')->nullable();                  // Veranstaltungsdatum
            $table->string('location')->nullable();
            $table->string('diaet_vorgabe')->nullable();              // Diät/Allergie-Vorgabe (frei)
            $table->text('brief')->nullable();                        // Freitext-Briefing / Hintergrund

            // Kommerzielle Schicht
            $table->decimal('gesamtpreis', 10, 2)->nullable();        // Angebots-Gesamtpreis (Cache/manuell)
            $table->date('valid_until')->nullable();                  // Angebots-Gültigkeit

            $table->text('description')->nullable();
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_offers');
    }
};
