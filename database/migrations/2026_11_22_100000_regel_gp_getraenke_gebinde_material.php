<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * GP-Regelwerk v3.8.2 §7.1a (Entscheid Dominique 2026-10-29): Bei Getränken steht die Gebinde-ART mit Material im Namen, weil Glas/PET/Tetra fachlich
 * zählt — Glasflasche, PET-Flasche, Tetra Pak, Dose, Fass, Bag-in-Box; Glas und PET sind zwei GPs.
 *  1. `gp.7.1a.sammelgebinde` sperrt die neuen Wörter nicht (ganze Wörter: „Glasflasche“ ≠ „Glas“) — sie bekommt die neuen Formen als richtige Beispiele
 *     und zusätzlich das Muster „6 x 0,33 l“ (Sammelgebinde als Stückzahl × Füllmenge, bisher nur über Wörter wie Kasten/Tray gesperrt).
 *  2. Neue Regel `gp.7.1a.flasche_material` (warnen, nur Warengruppe 15): „Flasche“ ohne Material ist ein Befund. Muster am Rohtext, damit
 *     „PET-Flasche“ und „Glasflasche“ nicht anschlagen.
 */
return new class extends Migration
{
    private const SAMMEL = 'gp.7.1a.sammelgebinde';

    private const NEU = 'gp.7.1a.flasche_material';

    public function up(): void
    {
        $svc = app(RegelService::class);
        $sammel = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SAMMEL)->first();
        if ($sammel !== null) {
            $beispiele = (array) ($sammel->beispiele ?? []);
            $richtig = (array) ($beispiele['richtig'] ?? []);
            $neu = array_filter(['Apfelsaft: Tetra Pak 1 l / (Base Culinar)', 'Gerolsteiner Medium: PET-Flasche 0,5 l', 'Pils: 4,9 vol-%, Glasflasche 0,33 l'],
                fn ($t) => ! array_filter($richtig, fn ($r) => ($r['text'] ?? $r) === $t));
            $params = (array) $sammel->params;
            $stueckMuster = '/(?<![\p{L}\d])(\d+\s*x\s*\d+(?:[.,]\d+)?\s*(?:l|ml|cl))(?![\p{L}])/iu';
            $musterNeu = ! in_array($stueckMuster, (array) ($params['muster'] ?? []), true);
            if ($neu !== [] || $musterNeu) {
                $params['muster'] = [...(array) ($params['muster'] ?? []), ...($musterNeu ? [$stueckMuster] : [])];
                $beispiele['richtig'] = [...$richtig, ...array_map(fn ($t) => ['text' => $t, 'kontext' => ['warengruppe' => '15']], array_values($neu))];
                $beispiele['falsch'] = [...(array) ($beispiele['falsch'] ?? []), ['text' => 'Cola: 6 x 0,33 l', 'kontext' => ['warengruppe' => '15']]];
                $svc->speichere([...$sammel->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'dossier_slug']),
                    'params' => $params, 'beispiele' => $beispiele,
                    'notiz' => trim(((string) $sammel->notiz) . ' v3.8.2: Gebinde-Art mit Material (Glasflasche, PET-Flasche, Tetra Pak …) erlaubt — Entscheid Dominique 2026-10-29.')],
                    null, (bool) $sammel->aktiv, 'migration');
            }
        }

        if (FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::NEU)->exists()) {
            return;
        }
        $svc->speichere([
            'schluessel' => self::NEU, 'regelwerk' => 'gp', 'paragraph' => '§7.1a', 'titel' => 'Getränke: Flasche ohne Material',
            'art' => 'verbot', 'ziel' => 'gp.name', 'wirkung' => 'warnen',
            'params' => [
                'muster' => ['/(?<![\p{L}-])(Flasche)(?![\p{L}])/u'],
                'bedingung' => ['warengruppe' => ['15']],
                'grund' => 'Bei Getränken gehört das Material zur Gebinde-Art: Glasflasche oder PET-Flasche (Glas und PET sind zwei GPs, §7.1a v3.8.2).',
            ],
            'beispiele' => [
                'richtig' => [['text' => 'Pils: 4,9 vol-%, Glasflasche 0,33 l', 'kontext' => ['warengruppe' => '15']],
                    ['text' => 'Gerolsteiner Medium: PET-Flasche 0,5 l', 'kontext' => ['warengruppe' => '15']]],
                'falsch' => [['text' => 'Pils: 4,9 vol-%, Flasche 0,33 l', 'kontext' => ['warengruppe' => '15']]],
            ],
            'notiz' => 'Spiegel zu Regelwerk Grundprodukte v3.8.2 §7.1a (Entscheid Dominique 2026-10-29). Warnt nur, blockiert nicht: ohne Beleg im Artikel bleibt „Flasche“ stehen.',
        ], null, true, 'migration');
    }

    public function down(): void
    {
        // Nimmt nur die neue Regel zurück. Die Ergänzung an gp.7.1a.sammelgebinde (Muster „6 x 0,33 l“, Beispiele) bleibt stehen — Rücknahme per
        // Regel-Pflegeseite (Versionen in foodalchemist_rule_versions), wie bei 2026_11_08 (Kurator-Review 29.10.).
        $neu = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::NEU)->first();
        if ($neu !== null) {
            DB::table('foodalchemist_rule_versions')->where('rule_id', $neu->id)->delete();
            $neu->forceDelete();
        }
    }
};
