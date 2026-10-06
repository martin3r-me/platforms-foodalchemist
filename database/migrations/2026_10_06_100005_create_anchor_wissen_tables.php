<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 60 · P4: Anker-Wissen (aus Zutaten-Dossiers) + Beziehungs-Graph + Varianten.
 *
 * Anker-Wissen ist Rohstoff, kein Urteil: jede Zeile trägt Herkunft (knowledge_document_id,
 * quelle_hash = Dossier-Inhalt bei der Auslese) und Status (entwurf → geprueft | verworfen).
 * Ändert sich das Dossier, ist die Zeile veraltet (Hash-Vergleich), nie still weiterbenutzt.
 *
 * Beziehungen sind gerichtet: a hat den Bedarf bzw. das Wissen über b.
 *   kontrast    abgeleitet (Bedarf × Eigenschaft), nie von Hand
 *   kombination „Verträgt" aus dem Dossier, aufgelöst auf einen Anker
 *   konflikt    „Zerstört" (nur art=zutat) aus dem Dossier
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_vocab_pairing_anchors', function (Blueprint $t) {
            $t->unsignedBigInteger('grund_anchor_id')->nullable()->after('inspire_ix');
            $t->string('grundname', 150)->nullable()->after('grund_anchor_id')->comment('Teil vor dem Komma; verbindet Varianten auch ohne Grund-Anker');
            $t->string('verfahren', 24)->nullable()->after('grundname')->comment('Enum Verfahren');
            $t->decimal('aroma_intensitaet', 4, 2)->nullable()->after('verfahren')->comment('Aromakraft je Gramm, Faktor');
            $t->index('grund_anchor_id', 'fa_vpa_grund_idx');
            $t->index('grundname', 'fa_vpa_grundname_idx');
        });

        $herkunft = function (Blueprint $t): void {
            $t->unsignedBigInteger('knowledge_document_id')->nullable();
            $t->char('quelle_hash', 64)->nullable();
            $t->string('beleg', 400)->nullable();
            $t->string('status', 12)->default('entwurf')->comment('entwurf | geprueft | verworfen');
            $t->timestamps();
        };

        Schema::create('foodalchemist_anchor_bedarfe', function (Blueprint $t) use ($herkunft) {
            $t->id();
            $t->foreignId('anchor_id')->constrained('foodalchemist_vocab_pairing_anchors', 'id', 'fa_abed_anchor_fk')->cascadeOnDelete();
            $t->string('achse', 24);
            $t->string('staerke', 8)->comment('muss | soll');
            $herkunft($t);
            $t->unique(['anchor_id', 'achse'], 'fa_abed_unique');
        });

        Schema::create('foodalchemist_anchor_eigenschaften', function (Blueprint $t) use ($herkunft) {
            $t->id();
            $t->foreignId('anchor_id')->constrained('foodalchemist_vocab_pairing_anchors', 'id', 'fa_aeig_anchor_fk')->cascadeOnDelete();
            $t->string('achse', 24);
            $t->unsignedTinyInteger('stufe')->comment('0 = bringt es ausdrücklich nicht mit … 3 = dominant');
            $t->string('quelle', 12)->comment('dossier | naehrwert');
            $herkunft($t);
            $t->unique(['anchor_id', 'achse', 'quelle'], 'fa_aeig_unique');
        });

        Schema::create('foodalchemist_anchor_komponenten', function (Blueprint $t) use ($herkunft) {
            $t->id();
            $t->foreignId('anchor_id')->constrained('foodalchemist_vocab_pairing_anchors', 'id', 'fa_akomp_anchor_fk')->cascadeOnDelete();
            $t->string('name', 200);
            $t->string('technik', 400)->nullable();
            $t->json('liefert')->nullable()->comment('Liste von Achsen');
            $herkunft($t);
            $t->index('anchor_id', 'fa_akomp_anchor_idx');
        });

        Schema::create('foodalchemist_anchor_wissen_offen', function (Blueprint $t) use ($herkunft) {
            $t->id();
            $t->foreignId('anchor_id')->constrained('foodalchemist_vocab_pairing_anchors', 'id', 'fa_awo_anchor_fk')->cascadeOnDelete();
            $t->string('art', 12)->comment('kombination | konflikt');
            $t->string('name', 200)->comment('Rohname aus dem Dossier');
            $t->string('kontext', 60)->nullable();
            $t->string('grund', 300)->nullable();
            $herkunft($t);
            $t->index(['anchor_id', 'art'], 'fa_awo_anchor_art_idx');
        });

        Schema::create('foodalchemist_anchor_beziehungen', function (Blueprint $t) {
            $t->id();
            $t->foreignId('anchor_a_id')->constrained('foodalchemist_vocab_pairing_anchors', 'id', 'fa_abez_a_fk')->cascadeOnDelete();
            $t->foreignId('anchor_b_id')->constrained('foodalchemist_vocab_pairing_anchors', 'id', 'fa_abez_b_fk')->cascadeOnDelete();
            $t->string('art', 12)->comment('kontrast | kombination | konflikt');
            $t->string('achse', 24)->default('')->comment('bei kontrast die gedeckte Achse, sonst leer (eindeutig, kein NULL im Unique)');
            $t->unsignedTinyInteger('rang')->default(0);
            $t->string('grundlage', 24)->comment('bedarf_x_eigenschaft | dossier');
            $t->string('status', 12)->default('entwurf');
            $t->unsignedBigInteger('knowledge_document_id')->nullable();
            $t->string('beleg', 400)->nullable();
            $t->char('quelle_hash', 64)->nullable();
            $t->timestamps();
            $t->unique(['anchor_a_id', 'anchor_b_id', 'art', 'achse'], 'fa_abez_unique');
            $t->index(['anchor_b_id', 'art'], 'fa_abez_b_art_idx');
        });
    }

    public function down(): void
    {
        foreach (['anchor_beziehungen', 'anchor_wissen_offen', 'anchor_komponenten', 'anchor_eigenschaften', 'anchor_bedarfe'] as $t) {
            Schema::dropIfExists('foodalchemist_'.$t);
        }
        Schema::table('foodalchemist_vocab_pairing_anchors', function (Blueprint $t) {
            $t->dropIndex('fa_vpa_grund_idx');
            $t->dropIndex('fa_vpa_grundname_idx');
            $t->dropColumn(['grund_anchor_id', 'grundname', 'verfahren', 'aroma_intensitaet']);
        });
    }
};
