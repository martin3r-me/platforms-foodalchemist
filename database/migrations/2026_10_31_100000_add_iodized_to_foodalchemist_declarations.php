<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jod am Lieferantenartikel (Entscheid Dominique 15.10.2026): Necta liefert `declarationIodhTlig`, der Alt-Import hatte es nie
 * übernommen. Spalte im Stil der übrigen Stoffe, gleiche Roh-Domäne {0,1,3,NULL}, aber freiwillige Angabe, kein Pflicht-Stoff
 * ({@see \Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::FREIWILLIG}). Keine Daten: Bestand füllt
 * `foodalchemist:necta-deklaration-nachfuellen --feld=iodized`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_item_declarations', 'iodized')) {
            Schema::table('foodalchemist_item_declarations', function (Blueprint $table) {
                $table->unsignedTinyInteger('iodized')->nullable()->after('with_type_sugar_sweetener')
                    ->comment('jodiert, freiwillig: 0=k.A. 1=nein 3=ja');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_item_declarations', 'iodized')) {
            Schema::table('foodalchemist_item_declarations', fn (Blueprint $table) => $table->dropColumn('iodized'));
        }
    }
};
