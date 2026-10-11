<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Neue Teller-Rollen (Dominique 11.10., nach Probelauf 2): hauptkomponente, eis, frucht, traeger — und die neue
 * Anrichte-Reihenfolge (Reihenfolge der Werte = Anrichte-Reihenfolge):
 * traeger → basis → protein → hauptkomponente → beilage → frucht → sauce → crunch → eis → saeure → aroma → garnitur.
 * Bedeutung: hauptkomponente = Hauptelement ohne Protein (Mousse, Parfait, Kuchen, Tarte, Hauptelement Fingerfood);
 * eis = Eis, Sorbet, Granita; frucht = Kompott, frische oder marinierte Frucht, Röster; traeger = Bun, Cracker,
 * Tartelette, Löffel-Basis, Brot bei Fingerfood/Street Food. Pairing-Gewichte für die vier neuen: bewusst KEINE (Rückfall
 * 1,0), bis Dominique Werte nennt. Bestehende Labels/Aliase bleiben; Aktiv-Status bleibt.
 */
return new class extends Migration
{
    private const REIHENFOLGE = ['traeger', 'basis', 'protein', 'hauptkomponente', 'beilage', 'frucht', 'sauce', 'crunch', 'eis',
        'saeure', 'aroma', 'garnitur'];

    private const NEU = ['hauptkomponente' => 'Hauptkomponente', 'eis' => 'Eis', 'frucht' => 'Frucht', 'traeger' => 'Träger'];

    public function up(): void
    {
        $svc = app(RegelService::class);
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'gericht.teller_rolle')->first();
        if ($r !== null) {
            $bisher = [];
            foreach ((array) ($r->params['werte'] ?? []) as $w) {
                if (is_array($w) && ($w['wert'] ?? '') !== '') {
                    $bisher[$w['wert']] = $w;
                }
            }
            if (! isset($bisher['traeger'])) {
                $werte = [];
                foreach (self::REIHENFOLGE as $wert) {
                    $werte[] = $bisher[$wert] ?? ['wert' => $wert, 'label' => self::NEU[$wert] ?? ucfirst($wert)];
                    unset($bisher[$wert]);
                }
                $params = (array) $r->params;
                $params['werte'] = [...$werte, ...array_values($bisher)];   // gepflegte Zusatzwerte bleiben, hinten
                $svc->speichere([...$r->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'beispiele',
                    'dossier_slug']), 'params' => $params,
                    'notiz' => trim(((string) $r->notiz) . ' 11.10. (Probelauf 2): + hauptkomponente (Hauptelement ohne Protein: Mousse, '
                        . 'Parfait, Kuchen, Tarte, Fingerfood), eis (Eis, Sorbet, Granita), frucht (Kompott, frische/marinierte Frucht, '
                        . 'Röster), traeger (Bun, Cracker, Tartelette, Löffel-Basis, Brot bei Fingerfood/Street Food); Reihenfolge neu.'),
                ], null, null, 'migration');
            }
        }
        $g = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'pairing.rollen_gewicht')->first();
        if ($g !== null && ! str_contains((string) $g->notiz, 'hauptkomponente')) {
            $svc->speichere([...$g->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'params', 'beispiele',
                'dossier_slug']),
                'notiz' => trim(((string) $g->notiz) . ' 11.10.: hauptkomponente, eis, frucht, traeger bewusst OHNE Gewicht (= 1,0), '
                    . 'bis Dominique Werte nennt — keine erfundenen Zahlen.'),
            ], null, null, 'migration');
        }
    }

    public function down(): void
    {
    }
};
