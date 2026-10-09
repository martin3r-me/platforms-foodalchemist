<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Spec 79 · Inspiration = ein Thema mit beliebig vielen Quellen (Dominique 2026-10-09: „drei Karten zum selben
 * Thema machen keinen Sinn, das müsste eins sein"). Die bisherigen Fundstücke (trend_belege.fundstueck = true)
 * werden Quellen einer Inspiration. Datenübernahme: jedes bestehende Fundstück wird eine eigene Inspiration
 * (Titel, Schlagworte, Team, Trend-Zuordnung, Zeitpunkte übernommen).
 *
 * down(): trennt die Quellen wieder von den Inspirationen und entfernt die Tabelle — die Fundstücke selbst
 * bleiben unverändert erhalten (kein Datenverlust, nur die Gruppierung fällt weg).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_trend_inspirationen')) {
            Schema::create('foodalchemist_trend_inspirationen', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->string('titel', 255);
                $table->json('schlagworte')->nullable();
                $table->unsignedBigInteger('trend_id')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
        Schema::table('foodalchemist_trend_belege', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_trend_belege', 'inspiration_id')) {
                $table->unsignedBigInteger('inspiration_id')->nullable()->index();
            }
        });

        // Datenübernahme: jedes Fundstück ohne Inspiration bekommt eine eigene
        $funde = DB::table('foodalchemist_trend_belege')->where('fundstueck', true)->whereNull('inspiration_id')
            ->whereNull('deleted_at')->orderBy('id')->get();
        foreach ($funde as $f) {
            $titel = trim((string) $f->titel);
            if ($titel === '') {
                $titel = $f->url ? (string) (parse_url((string) $f->url, PHP_URL_HOST) ?: $f->url) : 'Fundstück vom '.substr((string) $f->created_at, 0, 10);
            }
            $id = DB::table('foodalchemist_trend_inspirationen')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'team_id' => $f->team_id,
                'titel' => mb_substr($titel, 0, 255),
                'schlagworte' => $f->schlagworte,
                'trend_id' => $f->trend_id,
                'created_by' => $f->created_by,
                'created_at' => $f->created_at,
                'updated_at' => $f->updated_at,
            ]);
            DB::table('foodalchemist_trend_belege')->where('id', $f->id)->update(['inspiration_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('foodalchemist_trend_belege', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_trend_belege', 'inspiration_id')) {
                $table->dropIndex(['inspiration_id']);
                $table->dropColumn('inspiration_id');
            }
        });
        Schema::dropIfExists('foodalchemist_trend_inspirationen');
    }
};
