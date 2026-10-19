<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * GP-Regelwerk v3.5.0 §7.1a / §8.6 (Dominique 19.10.2026): Getränke (Warengruppe 15) tragen das EINZELGEBINDE und die Füllmenge im
 * GP-Namen (`Pils: 4,9 vol-%, Flasche 0,33 l`, `Cola: Dose 0,33 l`) — einzige Ausnahme von §7.1. Sammelgebinde (Kasten, Karton, Tray …) und
 * die übrigen Verpackungswörter bleiben auch bei Getränken verboten.
 *
 * Umsetzung als Regel-DATEN (Spec 81), nicht im Code:
 *  1. `gp.7.1.gebinde` (blockiert »Dose«, »Glas«, »Karton« … im GP-Namen) bekommt `erlaubt_wenn: warengruppe = 15`.
 *  2. Neue Regel `gp.7.1a.sammelgebinde` mit `bedingung: warengruppe = 15` sperrt für Getränke weiter alles außer Flasche, Dose, Fass
 *     und Bag-in-Box (die stehen in keiner Verbotsliste bzw. sind oben freigegeben).
 * Ohne Warengruppe im Kontext (`warengruppe` fehlt) gilt die Ausnahme NICHT — die Regel schweigt nicht, sondern greift wie bisher.
 */
return new class extends Migration
{
    private const ALT = 'gp.7.1.gebinde';

    private const NEU = 'gp.7.1a.sammelgebinde';

    public function up(): void
    {
        $alt = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::ALT)->first();
        if ($alt !== null && empty(($alt->params ?? [])['erlaubt_wenn'])) {
            $params = (array) $alt->params;
            $params['erlaubt_wenn'] = ['warengruppe' => ['15']];
            $beispiele = (array) ($alt->beispiele ?? []);
            $beispiele['richtig'] = [...(array) ($beispiele['richtig'] ?? []),
                ['text' => 'Cola: Dose 0,33 l', 'kontext' => ['warengruppe' => '15']]];
            $beispiele['falsch'] = [...(array) ($beispiele['falsch'] ?? []),
                ['text' => 'Cola: Dose 0,33 l', 'kontext' => ['warengruppe' => '01']]];
            app(RegelService::class)->speichere([...$alt->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'dossier_slug']),
                'wirkung' => $alt->wirkung, 'params' => $params, 'beispiele' => $beispiele,
                'notiz' => trim(((string) $alt->notiz) . ' Getränke (WG 15): Einzelgebinde erlaubt (§7.1a, v3.5.0) — siehe gp.7.1a.sammelgebinde.')],
                null, (bool) $alt->aktiv, 'migration');
        }

        if (FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::NEU)->exists()) {
            return;
        }
        app(RegelService::class)->speichere([
            'schluessel' => self::NEU, 'regelwerk' => 'gp', 'paragraph' => '§7.1a', 'titel' => 'Getränke: nur Einzelgebinde im GP-Namen',
            'art' => 'verbot', 'ziel' => 'gp.name', 'wirkung' => 'blockieren',
            'params' => [
                'tokens' => ['Kiste', 'Kasten', 'Karton', 'Tray', 'Palette', 'Beutel', 'Pkt', 'Btl', 'Geb', 'Tasse', 'Glas', 'Stange', 'Atmospack', 'Vac',
                    'Bund', 'Gebinde', 'Packung', 'Sixpack'],
                'bedingung' => ['warengruppe' => ['15']],
                'grund' => 'Bei Getränken steht nur das Einzelgebinde im GP-Namen (Flasche, Dose, Fass, Bag-in-Box); Sammelgebinde und andere Verpackungswörter nie (§7.1a).',
            ],
            'beispiele' => [
                'richtig' => [['text' => 'Pils: 4,9 vol-%, Flasche 0,33 l', 'kontext' => ['warengruppe' => '15']]],
                'falsch' => [['text' => 'Pils: 4,9 vol-%, Kasten 20 x 0,5 l', 'kontext' => ['warengruppe' => '15']]],
            ],
            'notiz' => 'Spiegel zu Regelwerk Grundprodukte v3.5.0 §7.1a. Gilt nur mit Warengruppe 15 im Kontext.',
        ], null, true, 'migration');
    }

    public function down(): void
    {
        $neu = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::NEU)->first();
        if ($neu !== null) {
            DB::table('foodalchemist_rule_versions')->where('rule_id', $neu->id)->delete();
            $neu->forceDelete();
        }
        // Die Freigabe an gp.7.1.gebinde bleibt stehen (Rücknahme per Regel-Pflegeseite): ohne die Schwesterregel wäre das Getränke-Verbot sonst weg.
    }
};
