<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Hausstandard Jus/Fond/Brühe (Dominique 10.10.) umgedreht: statt einer Verbotsliste der Verkaufs-Cuts gilt für jede
 * Zeile mit einem GP aus WG 04 (Fleisch, Geflügel & Wild, Regelwerk GP §3): erlaubt nur mit einem Ausnahme-Wortteil.
 * demo Lauf 91: „Rinderhueften: frisch, pariert" (1,2 kg im Grundjus) stand auf keiner Liste. Aktiv-Status bleibt.
 */
return new class extends Migration
{
    private const SCHLUESSEL = 'basisrezept.hausstandard.fond_knochen';

    public function up(): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SCHLUESSEL)->first();
        if ($r === null || ! empty(($r->params ?? [])['nur_mit'])) {
            return;
        }
        $typen = (array) (($r->params ?? [])['bedingung']['typ'] ?? ['Jus', 'Fond', 'Brühe', 'Essenz', 'Demi-Glace', 'Glace', 'Consommé']);
        $kontext = ['typ' => 'Jus', 'warengruppe' => '04'];
        app(RegelService::class)->speichere([...$r->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung',
            'dossier_slug']),
            'params' => [
                // Kuratorin/Berater: knochen … gräte. Ergänzt (zur Bestätigung Dominique): schwanz (Ochsenschwanz), fuß (Kalbsfuß).
                'nur_mit' => ['knochen', 'karkasse', 'abschnitt', 'parüre', 'sehne', 'hals', 'flügel', 'gräte', 'schwanz', 'fuß', 'füße'],
                'bedingung' => ['typ' => $typen, 'warengruppe' => ['04']],
                'grund' => 'Hausstandard: Jus, Fond und Brühe aus Knochen und Abschnitten — ein Verkaufs-Cut nur, wenn der Auftrag es ausdrücklich verlangt',
            ],
            'beispiele' => [
                'falsch' => array_map(fn ($t) => ['text' => $t, 'kontext' => $kontext],
                    ['Rinderhueften: frisch, pariert', 'Rinderbeinscheiben: frisch', 'Rinderfilet: frisch']),
                'richtig' => array_map(fn ($t) => ['text' => $t, 'kontext' => $kontext],
                    ['Rinderknochen: frisch', 'Kalb: frisch, Parüren', 'Geflügel: frisch, Karkasse', 'Hühnerflügel: frisch']),
            ],
            'notiz' => trim(((string) $r->notiz) . ' 2026-10-12: nur_mit + WG 04 statt Verbotsliste (Lauf 91).'),
        ], null, null, 'migration');
    }

    public function down(): void
    {
    }
};
