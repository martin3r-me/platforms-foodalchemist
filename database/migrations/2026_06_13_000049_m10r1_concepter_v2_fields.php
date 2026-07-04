<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M10R-1 / Doc 15 §10 (Concepter-Redesign „Menü-Planungs-Modul"): rein ADDITIVE
 * Schema-Erweiterung, damit der Concepter VK-Parität erreicht — kein Umbau des
 * bestehenden Modells (Concept · concept_slots=Positionen · pakete · paket_gerichte
 * bleiben), nur neue Felder + zwei neue Tabellen.
 *
 * Quellen: Doc 15 §10.8 (VK-Parität-Metadaten: Niveau/Anlass/Sektor/Geschmack),
 * §10.9 (Schema-Deltas), §10.10 (KI-First: Brief-Felder + GL-07-Lineage).
 *
 * Leitlinien (07 §7): keine CHECK-Constraints (Enums im PHP-Layer), Index-Namen
 * automatisch, engine-agnostisch (SQLite/Postgres/MySQL).
 *
 * Wording-Kaskade (§10.8, ENTSCHIEDEN): Gericht NEUTRAL → Stil am Concept
 * (writing_style_id) → Foodbook überschreibt je Kunde (foodbooks.writing_style_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Klasse-Vokabular (frei/wählbar, wie vocab_rollen) — §10.3 ──────────
        if (! Schema::hasTable('foodalchemist_vocab_classes')) {
            Schema::create('foodalchemist_vocab_classes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->string('slug');
                $table->string('name');
                $table->string('gruppe')->nullable();
                $table->integer('sort_order')->default(100);
                $table->boolean('is_inactive')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['team_id', 'slug']);
            });
        }

        // ── Sektor-Eignung am Concept (mehrwertig, wie recipe_sektor_eignung) ──
        if (! Schema::hasTable('foodalchemist_concept_sector_suitability')) {
            Schema::create('foodalchemist_concept_sector_suitability', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->foreignId('concept_id')->constrained('foodalchemist_concepts')->cascadeOnDelete();
                $table->string('sector_slug')->index();
                $table->string('source', 16)->default('manual')->comment('manual|ai_inferred (GL-07)');
                $table->decimal('ai_confidence', 4, 3)->nullable();
                $table->text('ai_reasoning')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['concept_id', 'sector_slug'], 'fa_concept_sektor_concept_sektor_unique');
            });
        }

        // ── Concepts: VK-Parität-Metadaten + Konsumenten-Felder + KI-Brief ────
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            // §10.3 Klasse (frei/wählbar, Pendant zu role)
            if (! Schema::hasColumn('foodalchemist_concepts', 'klasse')) {
                $table->string('klasse')->nullable()->after('niveau')->index();
            }
            // §10.8 Wording: Schreibstil am Concept (effektiver Stil = Foodbook-Override ?? hier)
            if (! Schema::hasColumn('foodalchemist_concepts', 'writing_style_id')) {
                $table->foreignId('writing_style_id')->nullable()->after('klasse')
                    ->constrained('foodalchemist_writing_styles')->nullOnDelete();
            }
            // §10.8 Geschmack (wie VK-Rezept)
            if (! Schema::hasColumn('foodalchemist_concepts', 'taste_direction')) {
                $table->string('taste_direction', 16)->nullable()->after('writing_style_id');
            }
            // §10.4 Konsumenten-Felder (Menü-Karte / Foodbook-Brücke)
            if (! Schema::hasColumn('foodalchemist_concepts', 'consumer_name')) {
                $table->string('consumer_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'additional_text')) {
                $table->text('additional_text')->nullable()->after('description');
            }
            // §10.10 KI-First: Brief/Vorgaben (die KI-Eingabe)
            if (! Schema::hasColumn('foodalchemist_concepts', 'brief')) {
                $table->text('brief')->nullable()->after('additional_text');
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'zielpreis_pro_person')) {
                $table->decimal('zielpreis_pro_person', 10, 2)->nullable()->after('preis_pro_person_cache');
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'diaet_vorgabe')) {
                $table->string('diaet_vorgabe')->nullable()->after('zielpreis_pro_person');
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'struktur_vorgabe')) {
                $table->string('struktur_vorgabe')->nullable()->after('diaet_vorgabe');
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'season')) {
                $table->string('season', 32)->nullable()->after('struktur_vorgabe');
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'target_group')) {
                $table->string('target_group')->nullable()->after('season');
            }
            // §10.10 GL-07-Lineage auf der Komposition (manual|ki)
            if (! Schema::hasColumn('foodalchemist_concepts', 'composition_source')) {
                $table->string('composition_source', 16)->default('manual')->after('status');
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'ai_confidence')) {
                $table->decimal('ai_confidence', 4, 3)->nullable()->after('composition_source');
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'ai_reasoning')) {
                $table->text('ai_reasoning')->nullable()->after('ai_confidence');
            }
            // §10.5/§10.9 Voll-Aggregat-Caches (Nährwerte/Person, Arbeitszeit, EK)
            if (! Schema::hasColumn('foodalchemist_concepts', 'naehrwerte_cache')) {
                $table->json('naehrwerte_cache')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'work_time_min_cache')) {
                $table->unsignedInteger('work_time_min_cache')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'ek_pro_person_cache')) {
                $table->decimal('ek_pro_person_cache', 10, 4)->nullable();
            }
        });

        // ── Pakete: Klasse + Konsumenten-Name + Aggregat-Caches ───────────────
        Schema::table('foodalchemist_packages', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_packages', 'klasse')) {
                $table->string('klasse')->nullable()->after('role')->index();
            }
            if (! Schema::hasColumn('foodalchemist_packages', 'consumer_name')) {
                $table->string('consumer_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('foodalchemist_packages', 'work_time_min_cache')) {
                $table->unsignedInteger('work_time_min_cache')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_packages', 'naehrwerte_cache')) {
                $table->json('naehrwerte_cache')->nullable();
            }
        });

        // ── Foodbook: Schreibstil-Override je Kunde/Foodbook (§10.8) ──────────
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'writing_style_id')) {
                $table->foreignId('writing_style_id')->nullable()->after('kunde')
                    ->constrained('foodalchemist_writing_styles')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_foodbooks', 'writing_style_id')) {
                $table->dropConstrainedForeignId('writing_style_id');
            }
        });

        Schema::table('foodalchemist_packages', function (Blueprint $table) {
            foreach (['klasse', 'consumer_name', 'work_time_min_cache', 'naehrwerte_cache'] as $col) {
                if (Schema::hasColumn('foodalchemist_packages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_concepts', 'writing_style_id')) {
                $table->dropConstrainedForeignId('writing_style_id');
            }
            foreach ([
                'klasse', 'taste_direction', 'consumer_name', 'additional_text', 'brief',
                'zielpreis_pro_person', 'diaet_vorgabe', 'struktur_vorgabe', 'season', 'target_group',
                'composition_source', 'ai_confidence', 'ai_reasoning',
                'naehrwerte_cache', 'work_time_min_cache', 'ek_pro_person_cache',
            ] as $col) {
                if (Schema::hasColumn('foodalchemist_concepts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('foodalchemist_concept_sector_suitability');
        Schema::dropIfExists('foodalchemist_vocab_classes');
    }
};
