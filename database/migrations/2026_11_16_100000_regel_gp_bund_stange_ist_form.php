<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * GP-Regelwerk v3.6.2 §7.1 (Dominique 23.10.2026): »Bund« und »Stange« sind Form der Ware (Möhren: frisch, Bund · Zimt: trocken, Stange),
 * kein Verpackungswort. Sie fallen aus der Verbotsliste von `gp.7.1.gebinde`; alle übrigen Verpackungswörter bleiben verboten.
 * Die Getränke-Regel `gp.7.1a.sammelgebinde` (nur WG 15) bleibt unverändert.
 */
return new class extends Migration
{
    private const SCHLUESSEL = 'gp.7.1.gebinde';

    private const FORM_WOERTER = ['Bund', 'Stange'];

    public function up(): void
    {
        $regel = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SCHLUESSEL)->first();
        if ($regel === null) {
            return;
        }
        $params = (array) $regel->params;
        $tokens = array_values(array_filter((array) ($params['tokens'] ?? []), fn ($t) => ! in_array($t, self::FORM_WOERTER, true)));
        if ($tokens === (array) ($params['tokens'] ?? [])) {
            return;
        }
        $params['tokens'] = $tokens;
        $beispiele = (array) ($regel->beispiele ?? []);
        $beispiele['richtig'] = [...(array) ($beispiele['richtig'] ?? []),
            ['text' => 'Möhren: frisch, Bund', 'kontext' => ['warengruppe' => '01']], ['text' => 'Zimt: trocken, Stange', 'kontext' => ['warengruppe' => '10']]];
        app(RegelService::class)->speichere([...$regel->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'dossier_slug']),
            'wirkung' => $regel->wirkung, 'params' => $params, 'beispiele' => $beispiele,
            'notiz' => trim(((string) $regel->notiz) . ' Bund und Stange sind Form, kein Verpackungswort (v3.6.2, 23.10.2026).')],
            null, (bool) $regel->aktiv, 'migration');
    }

    public function down(): void
    {
        $regel = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SCHLUESSEL)->first();
        if ($regel === null) {
            return;
        }
        $params = (array) $regel->params;
        $params['tokens'] = array_values(array_unique([...(array) ($params['tokens'] ?? []), ...self::FORM_WOERTER]));
        app(RegelService::class)->speichere([...$regel->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'dossier_slug']),
            'wirkung' => $regel->wirkung, 'params' => $params, 'beispiele' => $regel->beispiele, 'notiz' => $regel->notiz],
            null, (bool) $regel->aktiv, 'migration');
    }
};
