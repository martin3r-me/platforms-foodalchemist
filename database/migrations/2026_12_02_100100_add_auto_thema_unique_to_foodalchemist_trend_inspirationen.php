<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 93 B · Ein automatisches Thema je Team und Schlüssel, auch wenn zwei Erschließungs-Jobs gleichzeitig bündeln
 * (Befund io 02.11.2026: „Trendprognose 2027“ doppelt als id 9 + 10). MySQL kennt keinen partiellen Index, darum eine
 * generierte Spalte auto_schluessel = thema_schluessel nur für lebende automatische Themen, sonst NULL (manuelle
 * Inspirationen und gelöschte Themen dürfen gleich heißen). Vorher werden Bestands-Dubletten auf das älteste Thema
 * zusammengeführt (Belege + Eingang umhängen, Dublette weich löschen wie TrendThemenService::leeresThemaEntfernen).
 */
return new class extends Migration
{
    private const TABELLE = 'foodalchemist_trend_inspirationen';

    private const INDEX = 'fa_trend_insp_auto_thema_uq';

    public function up(): void
    {
        if (Schema::hasColumn(self::TABELLE, 'auto_schluessel')) {
            return;
        }
        $this->dublettenZusammenfuehren();

        Schema::table(self::TABELLE, function (Blueprint $table) {
            $table->string('auto_schluessel', 190)->nullable()
                ->virtualAs("CASE WHEN herkunft = 'automatisch' AND deleted_at IS NULL THEN thema_schluessel END");
            $table->unique(['team_id', 'auto_schluessel'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABELLE, 'auto_schluessel')) {
            return;
        }
        Schema::table(self::TABELLE, function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
            $table->dropColumn('auto_schluessel');
        });
    }

    private function dublettenZusammenfuehren(): void
    {
        $gruppen = DB::table(self::TABELLE)->where('herkunft', 'automatisch')->whereNull('deleted_at')->whereNotNull('thema_schluessel')
            ->groupBy('team_id', 'thema_schluessel')->havingRaw('COUNT(*) > 1')
            ->selectRaw('team_id, thema_schluessel, MIN(id) AS lead_id')->get();

        foreach ($gruppen as $g) {
            DB::transaction(function () use ($g) {
                $lead = DB::table(self::TABELLE)->where('id', $g->lead_id)->first();
                $dups = DB::table(self::TABELLE)->where('team_id', $g->team_id)->where('herkunft', 'automatisch')->whereNull('deleted_at')
                    ->where('thema_schluessel', $g->thema_schluessel)->where('id', '!=', $g->lead_id)->pluck('id');

                DB::table('foodalchemist_trend_belege')->whereIn('inspiration_id', $dups)
                    ->update(['inspiration_id' => $lead->id, 'trend_id' => $lead->trend_id]);
                DB::table('foodalchemist_trend_eingang')->whereIn('thema_inspiration_id', $dups)->update(['thema_inspiration_id' => $lead->id]);
                DB::table('foodalchemist_trend_eingang')->whereIn('inspiration_id', $dups)->update(['inspiration_id' => $lead->id]);
                DB::table(self::TABELLE)->whereIn('id', $dups)->update(['deleted_at' => now()]);
                DB::table(self::TABELLE)->where('id', $lead->id)->update(['updated_at' => now()]);
            });
        }
    }
};
