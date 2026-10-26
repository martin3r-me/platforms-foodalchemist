<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-Regelwerk v3.7.1 §5 Ausnahme 3: Marken-Kürzel, wie sie in Artikelbezeichnungen stehen („BC Bierschinken“, „Kn.Bratenjus“), und die
 * Schreibweise der Marke für die Klammer (Base Culinar, Knorr). `supplier_id` NULL = gilt für alle Lieferanten (Herstellermarken-Kürzel wie
 * Kn. → Knorr), sonst nur für diesen Lieferanten (Händler-Eigenmarken wie BC/SC bei Chefs Culinar). `kuerzel` klein und ohne Punkt.
 * `position`: wo das Kürzel steht — anfang (erste drei Wörter, Regel Klaus) oder ende (letztes Wort, z. B. Handelshof „… HENG.“).
 * `vorrang`: treffen mehrere Kürzel, gewinnt der höhere Wert (Produktlinie vor Dachmarke: „SC HT …“ → Hi Taste vor Star Culinar).
 * Global gepflegt; Eindeutigkeit (auch für NULL, wo UNIQUE nicht greift) sichert der Seeder bzw. die Pflege.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_supplier_marken')) {
            return;
        }
        Schema::create('foodalchemist_supplier_marken', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('supplier_id')->nullable()->constrained('foodalchemist_suppliers')->cascadeOnDelete();
            $t->string('kuerzel', 60)->comment('klein, ohne Punkt: bc, kn, cart');
            $t->string('marke', 80)->comment('Schreibweise in der Klammer: Base Culinar, Knorr');
            $t->string('typ', 16)->default('herstellermarke')->comment('eigenmarke | herstellermarke');
            $t->string('position', 6)->default('anfang')->comment('anfang = erste drei Wörter | ende = letztes Wort');
            $t->smallInteger('vorrang')->default(0)->comment('mehrere Treffer: höherer Vorrang gewinnt (Produktlinie vor Dachmarke)');
            $t->timestamps();
            $t->softDeletes();   // Entfernen bleibt nachvollziehbar (Audit); ein gelöschtes Kürzel wird beim erneuten Setzen wiederhergestellt
            $t->unique(['supplier_id', 'kuerzel']);
            $t->index('kuerzel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_supplier_marken');
    }
};
