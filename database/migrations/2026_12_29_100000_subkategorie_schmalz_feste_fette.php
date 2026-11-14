<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Verwaltete Unterkategorie „11.4 Schmalz & feste Fette“ in WG 11 (GP-Regelwerk 830 Teil 2, Georg/Dominique 14.11.2026): Schmalz, Bratfett, Frittierfett.
 * Globaler Eintrag (team_id NULL), Muster wie 2026_12_28_200000_subkategorien_regelwerk_v310. Idempotent: vorhandener Eintrag bleibt, ein soft
 * gelöschter wird reaktiviert. GP-Werte (`gps.sub_category`) hängt der GP-Lauf um (foodalchemist:gp-felder), nicht diese Migration.
 */
return new class extends Migration
{
    private const TABELLE = 'foodalchemist_commodity_group_subcategories';

    private const WG = '11';

    private const NAME = '11.4 Schmalz & feste Fette';

    private const POSITION = 4;

    public function up(): void
    {
        if (! Schema::hasTable(self::TABELLE)) {
            return;
        }
        $jetzt = now();
        $gibtEs = DB::table(self::TABELLE)->whereNull('team_id')->where('commodity_group_code', self::WG)->where('name', self::NAME)->first();
        if ($gibtEs === null) {
            DB::table(self::TABELLE)->insert(['uuid' => (string) Str::uuid(), 'team_id' => null, 'commodity_group_code' => self::WG, 'name' => self::NAME,
                'position' => self::POSITION, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
        } elseif ($gibtEs->deleted_at !== null) {
            DB::table(self::TABELLE)->where('id', $gibtEs->id)->update(['deleted_at' => null, 'position' => self::POSITION, 'updated_at' => $jetzt]);
        }
    }

    public function down(): void
    {
        // Bewusst leer: der Stand ist das Regelwerk (830 Teil 2). Entfernen über die Einstellungen.
    }
};
