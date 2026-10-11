<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Services\Regeln\RegelText;

/**
 * Alias-Bereinigung Teller-Rollen (demo 11.10.): protein trug noch den Alias „hauptkomponente“ — die Normalisierung mappte den
 * richtigen KI-Wert auf protein zurück (Calls 2848–2850 richtig, DB falsch). Entfernt jeden Alias, der einem anderen Wert
 * gleicht; idempotent (auf demo schon per Hand bereinigt, Regel v6). Der Riegel in Vokabular::validiere verhindert es künftig.
 */
return new class extends Migration
{
    public function up(): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'gericht.teller_rolle')->first();
        if ($r === null) {
            return;
        }
        $params = (array) $r->params;
        $werte = array_map(static fn ($w) => RegelText::norm((string) ($w['wert'] ?? '')), (array) ($params['werte'] ?? []));
        $geaendert = false;
        foreach ((array) ($params['werte'] ?? []) as $i => $w) {
            $eigener = RegelText::norm((string) ($w['wert'] ?? ''));
            $vorher = (array) ($w['aliase'] ?? []);
            $nachher = array_values(array_filter($vorher, static fn ($a) => ($n = RegelText::norm((string) $a)) === $eigener || ! in_array($n, $werte, true)));
            if ($nachher !== $vorher) {
                $params['werte'][$i]['aliase'] = $nachher;
                $geaendert = true;
            }
        }
        if ($geaendert) {
            app(RegelService::class)->speichere([...$r->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung',
                'beispiele', 'dossier_slug', 'notiz']), 'params' => $params], null, null, 'migration');
        }
    }

    public function down(): void
    {
    }
};
