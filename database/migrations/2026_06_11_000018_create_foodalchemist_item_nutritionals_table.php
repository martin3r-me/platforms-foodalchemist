<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2-17 / GL-08-QUELLE: LA-Nährwerte (BLS, Quelle wawi `nutritional`, 127.644 Zeilen).
 * Werte je 100 g Rohmasse (GL-08: Basis Rohmasse, nicht Yield; Salz = sodium × 0.0025).
 * Spaltennamen 1:1 Quelle; Aminosäuren/Zucker-Detail lossless, Rest in raw_json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_item_nutritionals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->index()->comment('= Quell-supplier_item_id');
            $table->foreignId('supplier_item_id')->unique()->constrained('foodalchemist_supplier_items')->cascadeOnDelete();
            $table->string('bls_key', 16)->nullable()->index();

            foreach ([
                'energy_kcal', 'energy_kj', 'water', 'protein', 'fat', 'carbs_absorbable',
                'roughage', 'minerals_crude_ash', 'organic_acids', 'alcohol',
                'sodium', 'potassium', 'calcium', 'magnesium', 'phosphorus', 'sulfur',
                'chlorine', 'iron', 'zinc', 'copper', 'manganese', 'fluorine', 'iodine',
                'fructose', 'glucose', 'galactose', 'sucrose', 'maltose', 'lactose',
                'starch', 'cellulose',
                'isoleucine', 'leucine', 'lysine', 'methionine', 'cysteine',
                'phenylalanine', 'tyrosine', 'threonine', 'tryptophan', 'valine',
                'arginine', 'histidine',
            ] as $feld) {
                $table->decimal($feld, 12, 4)->nullable();
            }
            $table->json('raw_json')->nullable()->comment('restliche Quell-Felder (Backup)');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_item_nutritionals');
    }
};
