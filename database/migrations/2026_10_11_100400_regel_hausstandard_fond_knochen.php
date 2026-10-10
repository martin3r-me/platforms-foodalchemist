<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Hausstandard Dominique 10.10.: Jus, Fond und Brühe werden aus Knochen und Abschnitten gekocht — ein Fleischstück
 * (Verkaufs-Cut) nur, wenn der Kontext es verlangt. Darum Wirkung WARNEN, kein Verbot. Anlass demo Lauf 87/88:
 * 1,8 kg Kalb-Beinscheiben im Jus, Rinderbeinscheiben im Grundjus.
 */
return new class extends Migration
{
    private const SCHLUESSEL = 'basisrezept.hausstandard.fond_knochen';

    public function up(): void
    {
        if (FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SCHLUESSEL)->exists()) {
            return;
        }
        app(RegelService::class)->speichere([
            'schluessel' => self::SCHLUESSEL, 'regelwerk' => 'basisrezept', 'paragraph' => 'Hausstandard',
            'titel' => 'Jus, Fond, Brühe aus Knochen und Abschnitten', 'art' => 'verbot', 'ziel' => 'rezeptzeile.typ',
            'wirkung' => 'warnen',
            'params' => [
                'teile' => ['beinscheib', 'ossobu', 'filet', 'steak', 'kotelett', 'braten', 'keule', 'brust'],
                'ausnahmen' => ['knochen', 'karkasse', 'abschnitt', 'parüre', 'paruere', 'sehne'],
                'bedingung' => ['typ' => ['Jus', 'Fond', 'Brühe', 'Essenz', 'Demi-Glace', 'Glace', 'Consommé']],
                'grund' => 'Hausstandard: aus Knochen und Abschnitten kochen — ein Fleischstück nur, wenn der Auftrag es ausdrücklich verlangt',
            ],
            'notiz' => 'Hausstandard Dominique 2026-10-10. Standard, kein starres Verbot (je nach Kontext).',
        ], null, true, 'migration');
    }

    public function down(): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SCHLUESSEL)->first();
        if ($r !== null) {
            \Illuminate\Support\Facades\DB::table('foodalchemist_rule_versions')->where('rule_id', $r->id)->delete();
            $r->forceDelete();
        }
    }
};
