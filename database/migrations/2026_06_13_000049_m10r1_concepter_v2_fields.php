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
 * (schreibstil_id) → Foodbook überschreibt je Kunde (foodbooks.schreibstil_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Klasse-Vokabular (frei/wählbar, wie vocab_rollen) — §10.3 ──────────
        if (! Schema::hasTable('foodalchemist_vocab_klassen')) {
            Schema::create('foodalchemist_vocab_klassen', function (Blueprint $table) {
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
        if (! Schema::hasTable('foodalchemist_concept_sektor_eignung')) {
            Schema::create('foodalchemist_concept_sektor_eignung', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->foreignId('concept_id')->constrained('foodalchemist_concepts')->cascadeOnDelete();
                $table->string('sektor_slug')->index();
                $table->string('quelle', 16)->default('manual')->comment('manual|ai_inferred (GL-07)');
                $table->decimal('ai_confidence', 4, 3)->nullable();
                $table->text('ai_begruendung')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['concept_id', 'sektor_slug'], 'fa_concept_sektor_concept_sektor_unique');
            });
        }

        // ── Concepts: VK-Parität-Metadaten + Konsumenten-Felder + KI-Brief ────
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            // §10.3 Klasse (frei/wählbar, Pendant zu rolle)
            if (! Schema::hasColumn('foodalchemist_concepts', 'klasse')) {
                $table->string('klasse')->nullable()->after('niveau')->index();
            }
            // §10.8 Wording: Schreibstil am Concept (effektiver Stil = Foodbook-Override ?? hier)
            if (! Schema::hasColumn('foodalchemist_concepts', 'schreibstil_id')) {
                $table->foreignId('schreibstil_id')->nullable()->after('klasse')
                    ->constrained('foodalchemist_writing_styles')->nullOnDelete();
            }
            // §10.8 Geschmack (wie VK-Rezept)
            if (! Schema::hasColumn('foodalchemist_concepts', 'geschmacksrichtung')) {
                $table->string('geschmacksrichtung', 16)->nullable()->after('schreibstil_id');
            }
            // §10.4 Konsumenten-Felder (Menü-Karte / Foodbook-Brücke)
            if (! Schema::hasColumn('foodalchemist_concepts', 'konsumenten_name')) {
                $table->string('konsumenten_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'zusatztext')) {
                $table->text('zusatztext')->nullable()->after('beschreibung');
            }
            // §10.10 KI-First: Brief/Vorgaben (die KI-Eingabe)
            if (! Schema::hasColumn('foodalchemist_concepts', 'brief')) {
                $table->text('brief')->nullable()->after('zusatztext');
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
            if (! Schema::hasColumn('foodalchemist_concepts', 'saison')) {
                $table->string('saison', 32)->nullable()->after('struktur_vorgabe');
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'zielgruppe')) {
                $table->string('zielgruppe')->nullable()->after('saison');
            }
            // §10.10 GL-07-Lineage auf der Komposition (manual|ki)
            if (! Schema::hasColumn('foodalchemist_concepts', 'komposition_quelle')) {
                $table->string('komposition_quelle', 16)->default('manual')->after('status');
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'ai_confidence')) {
                $table->decimal('ai_confidence', 4, 3)->nullable()->after('komposition_quelle');
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'ai_begruendung')) {
                $table->text('ai_begruendung')->nullable()->after('ai_confidence');
            }
            // §10.5/§10.9 Voll-Aggregat-Caches (Nährwerte/Person, Arbeitszeit, EK)
            if (! Schema::hasColumn('foodalchemist_concepts', 'naehrwerte_cache')) {
                $table->json('naehrwerte_cache')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'arbeitszeit_min_cache')) {
                $table->unsignedInteger('arbeitszeit_min_cache')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'ek_pro_person_cache')) {
                $table->decimal('ek_pro_person_cache', 10, 4)->nullable();
            }
        });

        // ── Pakete: Klasse + Konsumenten-Name + Aggregat-Caches ───────────────
        Schema::table('foodalchemist_pakete', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_pakete', 'klasse')) {
                $table->string('klasse')->nullable()->after('rolle')->index();
            }
            if (! Schema::hasColumn('foodalchemist_pakete', 'konsumenten_name')) {
                $table->string('konsumenten_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('foodalchemist_pakete', 'arbeitszeit_min_cache')) {
                $table->unsignedInteger('arbeitszeit_min_cache')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_pakete', 'naehrwerte_cache')) {
                $table->json('naehrwerte_cache')->nullable();
            }
        });

        // ── Foodbook: Schreibstil-Override je Kunde/Foodbook (§10.8) ──────────
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'schreibstil_id')) {
                $table->foreignId('schreibstil_id')->nullable()->after('kunde')
                    ->constrained('foodalchemist_writing_styles')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_foodbooks', 'schreibstil_id')) {
                $table->dropConstrainedForeignId('schreibstil_id');
            }
        });

        Schema::table('foodalchemist_pakete', function (Blueprint $table) {
            foreach (['klasse', 'konsumenten_name', 'arbeitszeit_min_cache', 'naehrwerte_cache'] as $col) {
                if (Schema::hasColumn('foodalchemist_pakete', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_concepts', 'schreibstil_id')) {
                $table->dropConstrainedForeignId('schreibstil_id');
            }
            foreach ([
                'klasse', 'geschmacksrichtung', 'konsumenten_name', 'zusatztext', 'brief',
                'zielpreis_pro_person', 'diaet_vorgabe', 'struktur_vorgabe', 'saison', 'zielgruppe',
                'komposition_quelle', 'ai_confidence', 'ai_begruendung',
                'naehrwerte_cache', 'arbeitszeit_min_cache', 'ek_pro_person_cache',
            ] as $col) {
                if (Schema::hasColumn('foodalchemist_concepts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('foodalchemist_concept_sektor_eignung');
        Schema::dropIfExists('foodalchemist_vocab_klassen');
    }
};
