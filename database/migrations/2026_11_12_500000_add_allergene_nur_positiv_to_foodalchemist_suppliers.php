<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 85 (Dominique 19.10.): Lieferanten, die in Necta nur POSITIVE Allergenwerte liefern (enthalten/spuren, nie nicht_enthalten).
 * Bei ihnen gilt beim Lesen: leer = angenommen nicht enthalten ({@see \Platform\FoodAlchemist\Support\AllergenAnnahme}).
 * Globales Lieferanten-Merkmal mit Grund, Datum und wer es gesetzt hat; gepflegt nur per `foodalchemist:allergene-nur-positiv`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_suppliers', 'allergene_nur_positiv')) {
            return;
        }
        Schema::table('foodalchemist_suppliers', function (Blueprint $t) {
            $t->boolean('allergene_nur_positiv')->default(false);
            $t->string('allergene_nur_positiv_grund')->nullable();
            $t->date('allergene_nur_positiv_seit')->nullable();
            $t->unsignedBigInteger('allergene_nur_positiv_von')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_suppliers', fn (Blueprint $t) => $t->dropColumn(['allergene_nur_positiv', 'allergene_nur_positiv_grund',
            'allergene_nur_positiv_seit', 'allergene_nur_positiv_von']));
    }
};
