<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 60 · P4: Bedarf-Deckung — partner | zubereitung | beides ({@see \Platform\FoodAlchemist\Enums\BedarfDeckung}).
 * Default partner = bisheriges Verhalten, keine Datenänderung.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_anchor_bedarfe') || Schema::hasColumn('foodalchemist_anchor_bedarfe', 'deckung')) {
            return;
        }
        Schema::table('foodalchemist_anchor_bedarfe', function (Blueprint $t) {
            $t->string('deckung', 12)->default('partner')->after('staerke')
                ->comment('partner | zubereitung | beides — wie der Bedarf gedeckt wird');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_anchor_bedarfe', 'deckung')) {
            Schema::table('foodalchemist_anchor_bedarfe', fn (Blueprint $t) => $t->dropColumn('deckung'));
        }
    }
};
