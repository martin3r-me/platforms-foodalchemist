<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 33 · P2 — beide Zuordnungsachsen an allen drei Ausgabeformen.
 *
 * Jede Form hatte bisher nur eine halbe Zuordnung: das Foodbook einen Kunden, die Speisekarte
 * ein Outlet, der Speiseplan gar nichts (nur `team_id` — zwei Kantinen im selben Team waren
 * nicht unterscheidbar).
 *
 * Das war eine falsche Vereinfachung. Ein Foodbook kann sehr wohl an einem Standort hängen,
 * und eine Karte oder ein Plan kann für einen Kunden gemacht sein — Betreibermodell: der
 * Betrieb führt die Kantine eines Kunden. Deshalb bekommen **alle drei beide Achsen**, und
 * beide bleiben **optional**: eine freistehende Karte muss weiter anlegbar sein.
 *
 * Zwei Muster werden gespiegelt, nicht neu erfunden:
 * - `outlet_id` wie an der Speisekarte: nullable FK auf `foodalchemist_outlets`, `nullOnDelete`.
 * - Die CRM-Felder wie am Foodbook (`2026_06_16_000112`): nullable + index, **kein FK** —
 *   CRM ist ein eigenständiges Modul, Cross-Modul läuft über Resolver, nicht über
 *   Fremdschlüssel (engine-agnostisch).
 *
 * **Abgrenzung:** `foodalchemist_foodbook_chapters.outlet_id` bleibt, was seine Migration sagt —
 * ein optionaler Tag je Kapitel, keine Planungsebene. Die neue Kopf-Spalte ist die Zuordnung
 * „dieses Foodbook gehört zu diesem Betrieb"; in der Portfolio-Übersicht zählt der Kopf.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Outlet: Foodbook-Kopf + Speiseplan ───────────────────────────────
        foreach (['foodalchemist_foodbooks', 'foodalchemist_menu_plans'] as $tabelle) {
            if (! Schema::hasTable($tabelle) || Schema::hasColumn($tabelle, 'outlet_id')) {
                continue;
            }
            Schema::table($tabelle, function (Blueprint $table) {
                $table->foreignId('outlet_id')->nullable()
                    ->constrained('foodalchemist_outlets')->nullOnDelete();
            });
        }

        // ── Kunde: Speisekarte + Speiseplan ──────────────────────────────────
        foreach (['foodalchemist_menu_cards', 'foodalchemist_menu_plans'] as $tabelle) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }
            Schema::table($tabelle, function (Blueprint $table) use ($tabelle) {
                if (! Schema::hasColumn($tabelle, 'customer')) {
                    $table->string('customer')->nullable()
                        ->comment('Spec 33: Kunden-Freitext (Betreibermodell), Gegenstück zu crm_company_id');
                }
                if (! Schema::hasColumn($tabelle, 'crm_company_id')) {
                    $table->unsignedBigInteger('crm_company_id')->nullable()->index();
                }
                if (! Schema::hasColumn($tabelle, 'crm_contact_id')) {
                    $table->unsignedBigInteger('crm_contact_id')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['foodalchemist_foodbooks', 'foodalchemist_menu_plans'] as $tabelle) {
            if (Schema::hasColumn($tabelle, 'outlet_id')) {
                Schema::table($tabelle, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('outlet_id');
                });
            }
        }

        foreach (['foodalchemist_menu_cards', 'foodalchemist_menu_plans'] as $tabelle) {
            Schema::table($tabelle, function (Blueprint $table) use ($tabelle) {
                foreach (['customer', 'crm_company_id', 'crm_contact_id'] as $spalte) {
                    if (Schema::hasColumn($tabelle, $spalte)) {
                        $table->dropColumn($spalte);
                    }
                }
            });
        }
    }
};
