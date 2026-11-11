<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Entscheide Dominique 11.11.2026 (Neuabgleich Pilot 09.11., Probelauf Excel 10.11.):
 * - „Speisestärke“ ohne weitere Angabe ist Maisstärke; „Mondamin“ (Marke) ebenso — der Pool band es an „Roux: trocken, dunkel / (Mondamin)“.
 * - „Heller Essig“ ist Balsamico-Essig hell.
 * - „Schwarze Oliven“ sind entsteint (vorher: gefüllt mit Frischkäse bzw. Ringe).
 *
 * Fügt jeden §5-Eintrag nur hinzu, wenn es den Begriff noch nicht gibt. Idempotent.
 */
return new class extends Migration
{
    private const EINTRAEGE = [
        ['begriff' => 'speisestärke', 'aliase' => ['mondamin', 'stärke'], 'ziel_typ' => 'gp', 'ziel_name' => 'Maisstärke: trocken'],
        ['begriff' => 'heller essig', 'aliase' => ['weißer essig'], 'ziel_typ' => 'gp', 'ziel_name' => 'Balsamico Essig: hell'],
        ['begriff' => 'schwarze oliven', 'aliase' => ['schwarze olive', 'oliven schwarz', 'olive schwarz'], 'ziel_typ' => 'gp', 'ziel_name' => 'Olive schwarz: entsteint, konserviert'],
    ];

    public function up(): void
    {
        $regel = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'basisrezept.5.default_gp')->first();
        if ($regel === null) {
            return;
        }
        $p = (array) $regel->params;
        $eintraege = (array) ($p['eintraege'] ?? []);
        $vorhanden = array_map(fn ($e) => mb_strtolower((string) ($e['begriff'] ?? '')), $eintraege);
        $neu = array_values(array_filter(self::EINTRAEGE, fn ($e) => ! in_array($e['begriff'], $vorhanden, true)));
        if ($neu === []) {
            return;
        }
        $eintraege = [...$eintraege, ...$neu];
        $felder = ['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'beispiele', 'dossier_slug'];
        app(RegelService::class)->speichere([...$regel->only($felder), 'params' => [...$p, 'eintraege' => $eintraege],
            'notiz' => trim(((string) $regel->notiz) . ' Speisestärke/Mondamin = Maisstärke: Entscheid Dominique 2026-11-11.')],
            null, null, 'migration');
    }

    public function down(): void
    {
    }
};
