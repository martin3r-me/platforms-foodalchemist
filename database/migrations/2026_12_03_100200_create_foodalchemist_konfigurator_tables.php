<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94 · Konfigurator: All-in-Preise je Concept als Kundenlink.
 * Konfigurator (Kopf, Einsatzzeiten, Regeln, Präsentation, Branding), Konzepte (Haupt mit Lunch-/Dinner-Concept, Add-ons),
 * Leistungen (Kopie aus Vorlagen), Pax-Pakete und die Mengen-Matrix Paket × Leistung. Rechnen nur im KonfiguratorPricingService.
 * Rein additiv: fünf neue Tabellen, down() droppt nur sie.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_konfiguratoren')) {
            Schema::create('foodalchemist_konfiguratoren', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->string('name');
                $table->string('kunden_titel')->nullable();
                $table->string('claim')->nullable();
                $table->text('intro')->nullable();
                $table->unsignedBigInteger('crm_company_id')->nullable()->index();
                $table->string('ort')->nullable();
                $table->string('status', 12)->default('entwurf')->index();   // entwurf | live | archiv
                $table->unsignedBigInteger('verantwortlich_user_id')->nullable();
                $table->unsignedInteger('pax_vorgabe')->default(100);
                $table->decimal('stunden_lunch', 5, 2)->default(6);
                $table->decimal('stunden_dinner', 5, 2)->default(7);
                $table->decimal('stunden_day_night', 5, 2)->default(14);
                $table->boolean('tageszeit_aktiv')->default(true);
                $table->decimal('kombi_rabatt_pct', 5, 2)->default(0);
                $table->decimal('paket_toleranz_pct', 5, 2)->default(10);
                $table->decimal('rundung', 4, 2)->default(0.01);
                $table->date('gueltig_bis')->nullable();
                // ── Präsentation (wie foodalchemist_offers, Trait HasPresentation) ──
                $table->boolean('presentation_enabled')->default(false);
                $table->string('presentation_token', 64)->nullable()->unique();
                $table->string('presentation_slug')->nullable()->unique('foodalchemist_konfiguratoren_pres_slug_unique');
                $table->string('presentation_design', 48)->default('editorial');
                $table->timestamp('presentation_published_at')->nullable();
                $table->unsignedBigInteger('presentation_published_by')->nullable();
                $table->timestamp('presentation_expires_at')->nullable();
                $table->json('presentation_snapshot_json')->nullable();
                $table->json('presentation_settings_json')->nullable();
                // ── Branding (wie foodalchemist_offers) ──
                $table->string('brand_color')->default('#6d28d9');
                $table->string('band_color')->nullable();
                $table->string('logo_path')->nullable();
                $table->string('cover_image_path')->nullable();
                $table->string('footer_text')->nullable();
                $table->unsignedBigInteger('logo_context_file_id')->nullable();
                $table->unsignedBigInteger('cover_context_file_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('foodalchemist_konfigurator_konzepte')) {
            Schema::create('foodalchemist_konfigurator_konzepte', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('konfigurator_id')->index();
                $table->string('rolle', 8)->default('haupt');                   // haupt | addon
                $table->unsignedBigInteger('lunch_concept_id')->nullable()->index();
                $table->unsignedBigInteger('dinner_concept_id')->nullable()->index();
                $table->string('abschnitt')->nullable();
                $table->string('addon_tageszeit', 8)->nullable();               // lunch | dinner | beide
                $table->unsignedBigInteger('gebunden_an_id')->nullable();
                $table->boolean('indikativ_lunch')->default(false);
                $table->boolean('indikativ_dinner')->default(false);
                $table->string('kunden_titel')->nullable();
                $table->string('claim')->nullable();
                $table->unsignedBigInteger('bild_context_file_id')->nullable();
                $table->string('bild_path')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('foodalchemist_konfigurator_leistungen')) {
            Schema::create('foodalchemist_konfigurator_leistungen', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('konfigurator_id')->index();
                $table->unsignedBigInteger('konfigurator_konzept_id')->nullable()->index();   // null = Grundausstattung
                $table->string('gilt_fuer', 8)->nullable();                     // lunch | dinner | beide
                $table->unsignedBigInteger('ersetzt_leistung_id')->nullable();
                $table->unsignedBigInteger('vorlage_id')->nullable()->index();
                $table->timestamp('vorlage_stand_at')->nullable();
                // gleiche Rechen- und Preisfelder wie die Vorlage (Kopie beim Übernehmen)
                $table->string('kategorie', 16);
                $table->string('bezeichnung');
                $table->text('notiz')->nullable();
                $table->string('mengen_regel', 16)->default('fest');
                $table->decimal('anzahl', 10, 2)->nullable();
                $table->string('bezug', 16)->nullable();
                $table->unsignedBigInteger('bezug_leistung_id')->nullable();
                $table->decimal('schluessel_n', 10, 2)->nullable();
                $table->decimal('min', 10, 2)->nullable();
                $table->decimal('max', 10, 2)->nullable();
                $table->unsignedInteger('ab_pax')->nullable();
                $table->unsignedInteger('bis_pax')->nullable();
                $table->json('stufen_json')->nullable();
                $table->decimal('reserve_pct', 5, 2)->nullable();
                $table->string('preis_je', 16)->default('stueck');
                $table->string('stunden_quelle', 16)->nullable();
                $table->decimal('stunden', 6, 2)->nullable();
                $table->decimal('stunden_zuschlag', 5, 2)->nullable();
                $table->string('haeufigkeit', 16)->default('je_event');
                $table->decimal('ek_satz', 10, 2)->default(0);
                $table->unsignedBigInteger('markup_class_id')->nullable();
                $table->decimal('vk_satz', 10, 2)->nullable();
                $table->string('vk_modus', 8)->default('auto');
                $table->string('vk_begruendung')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('foodalchemist_konfigurator_pakete')) {
            Schema::create('foodalchemist_konfigurator_pakete', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('konfigurator_id')->index();
                $table->string('bezeichnung')->nullable();
                $table->unsignedInteger('von_pax');
                $table->unsignedInteger('bis_pax');
                $table->unsignedInteger('referenz_pax');
                $table->boolean('referenz_manuell')->default(false);
                $table->timestamp('geschnuert_at')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('foodalchemist_konfigurator_paket_mengen')) {
            Schema::create('foodalchemist_konfigurator_paket_mengen', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('paket_id')->index();
                $table->unsignedBigInteger('leistung_id')->index();
                $table->unsignedBigInteger('konfigurator_konzept_id')->nullable();   // null = alle Hauptkonzepte
                $table->decimal('anzahl', 10, 2)->default(0);
                $table->boolean('manuell')->default(false);
                $table->decimal('regel_anzahl', 10, 2)->default(0);
                $table->string('notiz')->nullable();
                $table->timestamps();

                $table->unique(['paket_id', 'leistung_id', 'konfigurator_konzept_id'], 'fa_konfig_paket_menge_uq');
            });
        }
    }

    public function down(): void
    {
        foreach (['foodalchemist_konfigurator_paket_mengen', 'foodalchemist_konfigurator_pakete', 'foodalchemist_konfigurator_leistungen',
            'foodalchemist_konfigurator_konzepte', 'foodalchemist_konfiguratoren'] as $tabelle) {
            Schema::dropIfExists($tabelle);
        }
    }
};
