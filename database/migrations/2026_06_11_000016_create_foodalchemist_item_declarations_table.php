<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2-15 / GL-09-BLOCKER: LA-Deklarationen (Quelle wawi `declarations`, 112.605 Zeilen).
 *
 * 18 LMIV-Stoffe als ROHE Necta-Integer {0,1,3,NULL} (GL-09 §4.1 A1: 3=ja, 1=nein,
 * 0/NULL=keine Angabe) — die GL-09-MAX-Aggregation (M3) rechnet direkt auf dieser
 * Domäne, Übersetzung passiert nur in der UI. Spaltennamen 1:1 Quelle (GL-09 §4.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_item_declarations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->index()->comment('= Quell-supplier_item_id');
            $table->foreignId('supplier_item_id')->unique()->constrained('foodalchemist_supplier_items')->cascadeOnDelete();

            foreach ([
                'with_dye', 'with_preservative', 'with_antioxidant', 'with_flavour_enhancer',
                'sulphurated', 'blackened', 'waxed', 'with_phosphate', 'with_sweetener',
                'contains_phenylalanine', 'excessive_consumption_laxative', 'packaged_modified_atmosphere',
                'caffeinated', 'contains_milk_protein', 'contains_quinine', 'taurine_containing',
                'can_impair_attention_children', 'with_type_sugar_sweetener',
            ] as $stoff) {
                $table->unsignedTinyInteger($stoff)->nullable()->comment('0=k.A. 1=nein 3=ja (GL-09 A1)');
            }
            $table->json('details')->nullable()->comment('table_sweetener_basis / basis_tafe_sweetness');
            $table->string('quelle', 16)->nullable()->comment('NULL=Import | manual (GL-07-Lineage)');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_item_declarations');
    }
};
