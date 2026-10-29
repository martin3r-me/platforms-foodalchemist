<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Entscheid Dominique 2026-10-29 (Nachmessung Rezeptimport, §5 Default-GP):
 *  - „Petersilie“ ohne Angabe (auch „1 Bund Petersilie“) ist die ganze, frische glatte Petersilie — gehackt nur, wenn der Text es sagt.
 *  - „Schlagsahne“ ist nicht der Sahne-Default (30 %), sondern trifft ihr eigenes GP über den normalen Abgleich: der Alias fällt weg.
 * Nur diese zwei Einträge; alle übrigen Einträge und der Aktiv-Status der Regel bleiben, wie sie sind (auch Pflege-Änderungen auf io).
 */
return new class extends Migration
{
    private const PETERSILIE_ZIEL = 'Petersilie glatt: frisch, ganz';

    public function up(): void
    {
        $regel = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'basisrezept.5.default_gp')->first();
        if ($regel === null) {
            return;
        }
        $p = (array) $regel->params;
        $geaendert = false;
        $eintraege = array_map(function ($e) use (&$geaendert) {
            $begriff = mb_strtolower((string) ($e['begriff'] ?? ''));
            if ($begriff === 'petersilie' && ($e['ziel_name'] ?? null) !== self::PETERSILIE_ZIEL) {
                $e['ziel_name'] = self::PETERSILIE_ZIEL;
                $geaendert = true;
            }
            if ($begriff === 'sahne' && in_array('schlagsahne', array_map('mb_strtolower', (array) ($e['aliase'] ?? [])), true)) {
                $e['aliase'] = array_values(array_filter((array) $e['aliase'], fn ($a) => mb_strtolower((string) $a) !== 'schlagsahne'));
                if ($e['aliase'] === []) {
                    unset($e['aliase']);
                }
                $geaendert = true;
            }

            return $e;
        }, (array) ($p['eintraege'] ?? []));
        if (! $geaendert) {
            return;
        }
        $felder = ['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'beispiele', 'dossier_slug'];
        app(RegelService::class)->speichere([...$regel->only($felder), 'params' => [...$p, 'eintraege' => $eintraege],
            'notiz' => trim(((string) $regel->notiz) . ' Petersilie → ganz, frisch; Schlagsahne nicht mehr Sahne-Default: Entscheid Dominique 2026-10-29.')],
            null, null, 'migration');
    }

    public function down(): void
    {
    }
};
