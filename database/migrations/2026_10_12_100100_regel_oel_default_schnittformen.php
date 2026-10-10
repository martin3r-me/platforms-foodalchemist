<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Hausentscheidungen Dominique 2026-10-10 (über die Kuratorin, demo Lauf 91/92):
 *  - §5 Default-GP: das Gattungswort „Öl" ist Rapsöl (demo Lauf 91: „Öl" wurde per Mint „Lauchoel Groenn"). Ziel = das
 *    freigegebene „Rapsoel / Pflanzenoel: trocken, raffiniert" (demo 12833).
 *  - §2 Schnittform: „geachtelt", „geviertelt", „halbiert" fehlten — GpKorrektur tauschte solche Formen nie zur Rohform
 *    (demo Lauf 92, Brühe: Gemüse).
 * Aktiv-Status beider Regeln bleibt, wie er ist.
 */
return new class extends Migration
{
    private const OEL_ZIEL = 'Rapsoel / Pflanzenoel: trocken, raffiniert';

    public function up(): void
    {
        $svc = app(RegelService::class);
        $felder = ['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'beispiele', 'dossier_slug'];

        $default = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'basisrezept.5.default_gp')->first();
        if ($default !== null) {
            $p = (array) $default->params;
            $eintraege = (array) ($p['eintraege'] ?? []);
            if (! array_filter($eintraege, fn ($e) => mb_strtolower((string) ($e['begriff'] ?? '')) === 'öl')) {
                $eintraege[] = ['begriff' => 'öl', 'aliase' => ['speiseöl', 'pflanzenöl', 'neutrales öl'], 'ziel_typ' => 'gp',
                    'ziel_name' => self::OEL_ZIEL];
                $svc->speichere([...$default->only($felder), 'params' => [...$p, 'eintraege' => $eintraege],
                    'notiz' => trim(((string) $default->notiz) . ' Öl → Rapsöl: Hausentscheidung Dominique 2026-10-10.')],
                    null, null, 'migration');
            }
        }

        $schnitt = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'basisrezept.2.schnittform')->first();
        if ($schnitt !== null) {
            $p = (array) $schnitt->params;
            $tokens = (array) ($p['tokens'] ?? []);
            $neu = array_values(array_diff(['geachtelt', 'geviertelt', 'halbiert'], $tokens));
            if ($neu !== []) {
                $svc->speichere([...$schnitt->only($felder), 'params' => [...$p, 'tokens' => [...$tokens, ...$neu]],
                    'notiz' => trim(((string) $schnitt->notiz) . ' geachtelt/geviertelt/halbiert: Kuratorin 2026-10-10 (Lauf 92).')],
                    null, null, 'migration');
            }
        }
    }

    public function down(): void
    {
    }
};
