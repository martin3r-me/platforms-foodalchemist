<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Support\ObservatoriumInhalt;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_trend_eingang', function (Blueprint $table) {
            $table->json('observatorium')->nullable();
            $table->string('inhalt_hash', 64)->nullable()->index();
            $table->string('herausgeber', 255)->nullable();
            $table->string('herausgeber_url', 2048)->nullable();
        });
        Schema::create('foodalchemist_observatorium_merker', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('eingang_id')->constrained('foodalchemist_trend_eingang')->cascadeOnDelete();
            $table->boolean('gemerkt')->default(false);
            $table->boolean('trend_pruefen')->default(false);
            $table->timestamps();
            $table->unique(['team_id', 'user_id', 'eingang_id'], 'fa_obs_merker_eindeutig');
        });

        // Kein KI-Backfill, keine Jobs, keine Änderung der bisherigen Trend-Status oder Freigaben.
        DB::table('foodalchemist_trend_eingang')->orderBy('id')->chunkById(200, function ($artikel) {
            foreach ($artikel as $a) {
                $daten = (array) $a;
                $daten['sparten'] = json_decode($a->sparten ?? '[]', true) ?: [];
                DB::table('foodalchemist_trend_eingang')->where('id', $a->id)->update([
                    'observatorium' => json_encode(ObservatoriumInhalt::einordnen($daten), JSON_UNESCAPED_UNICODE),
                    'inhalt_hash' => ObservatoriumInhalt::inhaltHash($a->titel, $a->url),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_observatorium_merker');
        Schema::table('foodalchemist_trend_eingang', fn (Blueprint $table) => $table->dropIndex(['inhalt_hash']));
        Schema::table('foodalchemist_trend_eingang', fn (Blueprint $table) => $table->dropColumn(['observatorium', 'inhalt_hash', 'herausgeber', 'herausgeber_url']));
    }
};
