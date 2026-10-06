<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\Achse;
use Platform\FoodAlchemist\Enums\AussageTyp;
use Platform\FoodAlchemist\Enums\Grundlage;
use Platform\FoodAlchemist\Enums\Verfahren;
use Platform\FoodAlchemist\Enums\WissensStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * Spec 60 · P6: Kombinationslogik eines Gerichts — klare Aussagen und Vorschläge.
 *
 * Ein Gericht besteht aus Bestandteilen: Basisrezepten (mit Aromenprofil) und einzeln
 * eingesetzten Grundprodukten (Einzelprofil). Die Inspire-Anker liegen im Hintergrund.
 *
 * Aussagen (je mit Grundlage):
 *   harmoniert / passt / neutral   je Bestandteil-Paar ({@see RezeptGraph::harmonie})
 *   spannung                       ein Bedarf wird von einem anderen Bestandteil gedeckt
 *   bedarf_offen                   ein Bedarf, den kein Bestandteil deckt
 *   konflikt, kombination          aus dem Anker-Wissen (Dossier)
 *   unbekannt                      Bestandteil ohne Profil
 *
 * Vorschlag „Was fehlt dem Teller": je offenem Bedarf zuerst eine andere Zubereitung bzw. Form
 * eines vorhandenen Bestandteils, dann Basisrezepte aus dem Bestand, die die Achse liefern, mit
 * mindestens einem Bestandteil harmonieren oder kombinieren, keinen Konflikt auslösen, zur
 * Ernährungsform und zur Geschmacksrichtung (süß/herzhaft) passen.
 *
 * Dieselbe Logik prüft ein fertiges Gericht und liefert dem Generator den Kombinationsplan.
 */
final class Kombinationslogik
{
    /** Ab diesem Harmonie-Wert (Anteil harmonierender Aromamasse) „harmonieren" zwei Bestandteile. Kalibriert 2026-10-06: obere 20 % der Paare. */
    public const HARMONIERT_AB = 0.10;

    /** Ab diesem Anteil Stufe-2-Masse „passen" sie (Hinweis, zählt nicht). */
    public const PASST_AB = 0.10;

    /**
     * Funktionen von Basisrezepten, die nicht auf den Teller gehen (Vorbereitung, Getränk) —
     * nie als Komponente vorschlagen. Freitext-Funktionen werden zusätzlich über Wortteile erkannt.
     */
    public const NICHT_TELLER = ['Basis', 'Marinade', 'Einlege-Sud', 'Lack', 'Mise en Place', 'Paste', 'Füllung',
        'Getränk', 'Cocktail', 'Aperitif'];

    private const NICHT_TELLER_WORTE = ['beize', 'marinade', 'lake', 'sud', 'fond', 'mise en place'];

    private const ROESTAROMA_VERFAHREN = [Verfahren::Geroestet, Verfahren::Gebraten, Verfahren::Gegrillt, Verfahren::Gebacken];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $basisKatalog = null;

    public function __construct(
        private readonly RezeptProfil $profil,
        private readonly RezeptGraph $graph,
        private readonly PairingService $pairing,
    ) {}

    /**
     * Bestandteile eines Gerichts mit ihrem Profil.
     *
     * @return list<array{schluessel: string, label: string, recipe_id: ?int, rolle: ?string, profil: ?array}>
     */
    public function bestandteile(FoodAlchemistRecipe $gericht): array
    {
        $zeilen = FoodAlchemistRecipeIngredient::query()->with(['gp', 'referencedRecipe'])
            ->where('recipe_id', $gericht->id)->where('is_optional', false)->orderBy('position')->orderBy('id')->get();
        $gpKerne = $this->pairing->gpKernAnker($zeilen->pluck('gp_id')->filter()->all());
        $out = [];
        $gesehen = [];
        foreach ($zeilen as $z) {
            if ($z->referenced_recipe_id !== null) {
                // Dasselbe Basisrezept zweimal im Gericht ist ein Bestandteil, kein Paar mit sich selbst.
                if (isset($gesehen[$z->referenced_recipe_id])) {
                    continue;
                }
                $gesehen[$z->referenced_recipe_id] = true;
                $p = $this->profil->fuer((int) $z->referenced_recipe_id);
                $out[] = ['schluessel' => 'r'.$z->referenced_recipe_id, 'label' => (string) ($z->referencedRecipe?->name ?? $z->raw_text),
                    'recipe_id' => (int) $z->referenced_recipe_id, 'rolle' => $z->role, 'profil' => $p['anker'] !== [] ? $p : null];

                continue;
            }
            $label = (string) ($z->display_name ?: $z->gp?->name ?: $z->raw_text);
            $anker = $z->gp_id !== null ? ($gpKerne[$z->gp_id] ?? $this->pairing->ankerIdExakt($z->gp?->name)) : $this->pairing->ankerIdExakt($z->raw_text);
            $anker = $anker !== null && (int) $anker !== $this->pairing->neutralAnker() ? (int) $anker : null;
            $verfahren = Verfahren::ausText((string) ($z->display_name ?: $z->raw_text));
            $out[] = ['schluessel' => 'z'.$z->id, 'label' => $label, 'recipe_id' => null, 'rolle' => $z->role,
                'profil' => $anker !== null ? $this->profil->einzel($anker, $verfahren) : null];
        }

        return $out;
    }

    /**
     * @return array{bestandteile: list<array>, aussagen: list<Aussage>, offene_bedarfe: list<array>, zusammenfassung: array<string, int>}
     */
    public function analysiere(FoodAlchemistRecipe $gericht): array
    {
        return $this->analysiereBestandteile($this->bestandteile($gericht));
    }

    /**
     * Bestandteile aus einzelnen Ankern (Composer: Auswahl ohne Rezept) — je Anker ein Einzelprofil.
     *
     * @param  list<int>  $ankerIds
     * @return list<array{schluessel: string, label: string, recipe_id: null, rolle: null, profil: ?array}>
     */
    public function bestandteileAusAnkern(array $ankerIds): array
    {
        $namen = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $ankerIds)->pluck('display_de', 'id');
        $out = [];
        foreach (array_values(array_unique(array_map('intval', $ankerIds))) as $id) {
            if (! isset($namen[$id])) {
                continue;
            }
            $out[] = ['schluessel' => 'a'.$id, 'label' => (string) $namen[$id], 'recipe_id' => null, 'rolle' => null,
                'profil' => $this->profil->einzel($id)];
        }

        return $out;
    }

    /**
     * Kern der Logik: Aussagen über eine beliebige Menge von Bestandteilen (Gericht, Basisrezept,
     * Composer-Auswahl). Jeder Bestandteil trägt ein Profil oder ist unbekannt.
     *
     * @param  list<array{schluessel: string, label: string, recipe_id: ?int, rolle: ?string, profil: ?array}>  $teile
     * @return array{bestandteile: list<array>, aussagen: list<Aussage>, offene_bedarfe: list<array>, zusammenfassung: array<string, int>}
     */
    public function analysiereBestandteile(array $teile): array
    {
        $aussagen = [];
        $mit = array_values(array_filter($teile, fn ($t) => $t['profil'] !== null));
        foreach ($teile as $t) {
            if ($t['profil'] === null) {
                $aussagen[] = new Aussage(AussageTyp::Unbekannt, Grundlage::Keine,
                    "{$t['label']}: noch keinem Aroma zugeordnet", [$t['schluessel']]);
            }
        }

        $gedeckt = [];   // schluessel|achse => true
        for ($i = 0; $i < count($mit); $i++) {
            for ($j = $i + 1; $j < count($mit); $j++) {
                [$a, $b] = [$mit[$i], $mit[$j]];
                $aussagen[] = $this->harmonieAussage($a, $b);
                foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
                    foreach ($this->graph->spannung($x['profil'], $y['profil']) as $s) {
                        $gedeckt[$x['schluessel'].'|'.$s['achse']] = true;
                        $achse = Achse::from($s['achse'])->label();
                        $aussagen[] = new Aussage(AussageTyp::Spannung, Grundlage::aus($s['quelle']),
                            "Spannung: {$achse} von {$y['label']} für {$x['label']}", [$x['schluessel'], $y['schluessel']], $s['achse'], $s['stufe']);
                    }
                }
                foreach ($this->graph->konflikte($a['profil'], $b['profil']) as $k) {
                    $aussagen[] = new Aussage(AussageTyp::Konflikt, $this->wissensGrundlage($k->status),
                        "Konflikt: {$a['label']} und {$b['label']} stören sich", [$a['schluessel'], $b['schluessel']]);
                }
                foreach ($this->graph->kombinationen($a['profil'], $b['profil']) as $k) {
                    $aussagen[] = new Aussage(AussageTyp::Kombination, $this->wissensGrundlage($k->status),
                        "Klassiker: {$a['label']} mit {$b['label']}", [$a['schluessel'], $b['schluessel']]);
                }
            }
        }

        // Offene Bedarfe des Tellers: je Achse einmal, „muss" vor „soll".
        $offen = [];
        foreach ($mit as $t) {
            foreach ($t['profil']['offene_bedarfe'] as $b) {
                if (isset($gedeckt[$t['schluessel'].'|'.$b['achse']])) {
                    continue;
                }
                $alt = $offen[$b['achse']] ?? null;
                if ($alt === null || ($b['staerke'] === 'muss' && $alt['staerke'] !== 'muss')) {
                    $offen[$b['achse']] = $b + ['bestandteil' => $t['schluessel'], 'label' => $t['label']];
                }
            }
        }
        foreach ($offen as $b) {
            $achse = Achse::from($b['achse'])->label();
            $aussagen[] = new Aussage(AussageTyp::BedarfOffen, $this->bedarfGrundlage((int) $b['von'], $b['achse']),
                ($b['staerke'] === 'muss' ? 'Es fehlt ' : 'Es könnte fehlen: ').$achse." (für {$b['label']})", [$b['bestandteil']], $b['achse']);
        }

        $zusammen = [];
        foreach ($aussagen as $x) {
            $zusammen[$x->typ->value] = ($zusammen[$x->typ->value] ?? 0) + 1;
        }

        return ['bestandteile' => $teile, 'aussagen' => $aussagen, 'offene_bedarfe' => array_values($offen), 'zusammenfassung' => $zusammen];
    }

    /**
     * Vorschläge für die offenen Bedarfe eines Gerichts.
     *
     * @return list<array{achse: string, staerke: string, formwechsel: list<string>, basisrezepte: list<array{recipe_id: int, name: string, stufe: float, harmonie: float, mit: ?string, grundlage: string}>}>
     */
    public function vorschlaege(FoodAlchemistRecipe $gericht, int $jeBedarf = 5): array
    {
        return $this->vorschlaegeFuer(
            $this->analysiere($gericht),
            $gericht->spec_is_vegan === true ? 'vegan' : ($gericht->spec_is_vegetarian === true ? 'vegetarisch' : null),
            $gericht->taste_direction,
            $gericht->team_id !== null ? (int) $gericht->team_id : null,
            $jeBedarf,
        );
    }

    /**
     * Vorschläge zu einer fertigen Analyse — auch ohne Rezept (Composer, Generator-Plan).
     *
     * @param  array{bestandteile: list<array>, offene_bedarfe: list<array>}  $analyse
     * @return list<array>
     */
    public function vorschlaegeFuer(array $analyse, ?string $diaet, ?string $geschmacksrichtung, ?int $teamId, int $jeBedarf = 5): array
    {
        $teile = array_values(array_filter($analyse['bestandteile'], fn ($t) => $t['profil'] !== null));
        $vorhanden = array_filter(array_map(fn ($t) => $t['recipe_id'], $analyse['bestandteile']));
        // Süß und herzhaft mischen sich nicht: ein Vorschlag hat die Richtung des Gerichts oder ist neutral.
        $richtung = in_array($geschmacksrichtung, ['herzhaft', 'suess'], true) ? $geschmacksrichtung : null;

        $out = [];
        foreach ($analyse['offene_bedarfe'] as $b) {
            $kandidaten = [];
            foreach ($this->basisKatalog($teamId) as $rid => $k) {
                if (in_array($rid, $vorhanden, true) || ! $k['teller'] || ! $this->passtZurDiaet($k, $diaet)
                    || ($richtung !== null && $k['richtung'] !== null && $k['richtung'] !== $richtung && $k['richtung'] !== 'neutral')) {
                    continue;
                }
                $e = $k['profil']['eigenschaften'][$b['achse']] ?? null;
                if ($e === null || (float) $e['stufe'] < 2) {
                    continue;
                }
                $besteHarmonie = 0.0;
                $mit = null;
                $konflikt = false;
                $klassiker = false;
                foreach ($teile as $t) {
                    if ($this->graph->konflikte($k['profil'], $t['profil']) !== []) {
                        $konflikt = true;
                        break;
                    }
                    $h = $this->graph->harmonie($k['profil'], $t['profil'])['wert'];
                    if ($h > $besteHarmonie) {
                        [$besteHarmonie, $mit] = [$h, $t['label']];
                    }
                    $klassiker = $klassiker || array_filter($this->graph->kombinationen($k['profil'], $t['profil']),
                        fn ($x) => $x->status === WissensStatus::Geprueft->value) !== [];
                }
                if ($konflikt || ($besteHarmonie < self::HARMONIERT_AB && ! $klassiker)) {
                    continue;
                }
                $kandidaten[] = ['recipe_id' => $rid, 'name' => $k['name'], 'stufe' => (float) $e['stufe'],
                    'harmonie' => round($besteHarmonie, 3), 'mit' => $mit, 'grundlage' => Grundlage::aus((string) $e['quelle'])->value,
                    'sort' => (float) $e['stufe'] / 3 + $besteHarmonie + ($klassiker ? 0.5 : 0)];
            }
            usort($kandidaten, fn ($x, $y) => [$y['sort'], $x['recipe_id']] <=> [$x['sort'], $y['recipe_id']]);
            $out[] = [
                'achse' => $b['achse'], 'staerke' => $b['staerke'],
                'formwechsel' => $this->formwechsel($teile, $b['achse']),
                'basisrezepte' => array_map(function ($k) {
                    unset($k['sort']);

                    return $k;
                }, array_slice($kandidaten, 0, $jeBedarf)),
            ];
        }

        return $out;
    }

    /**
     * Einheitliche Ausgabe für Oberfläche und MCP (keine zweite Formulierung).
     *   Gericht      Bestandteile = seine Basisrezepte/GPs, mit Vorschlägen
     *   Basisrezept  Bestandteile = seine Zutaten, dazu das eigene Aromenprofil, ohne Vorschläge
     *
     * @return array<string, mixed>
     */
    public function daten(FoodAlchemistRecipe $rezept, bool $mitVorschlaegen = true): array
    {
        $istGericht = (bool) $rezept->is_sales_recipe;
        $analyse = $this->analysiere($rezept);
        $out = $this->ausgabe($analyse, $istGericht ? 'gericht' : 'basisrezept');
        if (! $istGericht) {
            $p = $this->profil->fuer((int) $rezept->id);
            $out['profil'] = [
                'anker' => $this->ankerNamen($p['anker']),
                'abdeckung' => $p['abdeckung'],
                'eigenschaften' => $this->eigenschaftenLesbar($p['eigenschaften']),
            ];
        }
        $out['vorschlaege'] = $istGericht && $mitVorschlaegen
            ? $this->vorschlaegeFuer($analyse,
                $rezept->spec_is_vegan === true ? 'vegan' : ($rezept->spec_is_vegetarian === true ? 'vegetarisch' : null),
                $rezept->taste_direction, $rezept->team_id !== null ? (int) $rezept->team_id : null)
            : [];

        return $out;
    }

    /**
     * Dieselbe Ausgabe für eine Anker-Auswahl ohne Rezept (Composer).
     *
     * @param  list<int>  $ankerIds
     * @return array<string, mixed>
     */
    public function datenAusAnkern(array $ankerIds, ?string $diaet = null, ?string $richtung = null, ?int $teamId = null): array
    {
        $analyse = $this->analysiereBestandteile($this->bestandteileAusAnkern($ankerIds));
        $out = $this->ausgabe($analyse, 'auswahl');
        $out['vorschlaege'] = $this->vorschlaegeFuer($analyse, $diaet, $richtung, $teamId);

        return $out;
    }

    /** @return array<string, mixed> */
    private function ausgabe(array $analyse, string $art): array
    {
        $gruppen = [];
        foreach ($analyse['aussagen'] as $a) {
            $gruppen[$a->typ->value][] = $a->toArray();
        }
        $teile = array_map(fn ($t) => [
            'schluessel' => $t['schluessel'], 'label' => $t['label'], 'recipe_id' => $t['recipe_id'], 'rolle' => $t['rolle'],
            'kern' => $t['profil'] !== null ? array_slice($this->ankerNamen($t['profil']['anker']), 0, 3) : [],
            'abdeckung' => $t['profil']['abdeckung'] ?? null,
        ], $analyse['bestandteile']);
        $z = $analyse['zusammenfassung'];
        $fehlt = array_map(fn ($b) => Achse::from($b['achse'])->label(), $analyse['offene_bedarfe']);
        $satz = count($teile).' Bestandteile · '
            .($z['harmoniert'] ?? 0).' harmonieren · '.($z['spannung'] ?? 0).' Spannung'
            .($fehlt !== [] ? ' · fehlt: '.implode(', ', $fehlt) : '')
            .(($z['konflikt'] ?? 0) > 0 ? ' · '.$z['konflikt'].' Konflikt' : '')
            .(($z['unbekannt'] ?? 0) > 0 ? ' · '.$z['unbekannt'].' ohne Aroma' : '');

        return ['art' => $art, 'bestandteile' => $teile, 'aussagen' => $gruppen,
            'offene_bedarfe' => $analyse['offene_bedarfe'], 'kennzahlen' => $z, 'zusammenfassung' => $satz];
    }

    /** @return list<array{anchor_id: int, name: string, anteil: float, verfahren: ?string}> */
    private function ankerNamen(array $anker): array
    {
        $namen = DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', array_column($anker, 'anchor_id'))->pluck('display_de', 'id');

        return array_map(fn ($a) => ['anchor_id' => (int) $a['anchor_id'], 'name' => (string) ($namen[$a['anchor_id']] ?? '?'),
            'anteil' => (float) $a['anteil'], 'verfahren' => $a['verfahren'] ?? null], $anker);
    }

    /** @return list<array{achse: string, label: string, stufe: float, grundlage: string}> */
    private function eigenschaftenLesbar(array $eigenschaften): array
    {
        $out = [];
        foreach ($eigenschaften as $achse => $e) {
            if ((float) $e['stufe'] < 1 || Achse::tryFrom($achse) === null) {
                continue;
            }
            $out[] = ['achse' => $achse, 'label' => Achse::from($achse)->label(), 'stufe' => (float) $e['stufe'],
                'grundlage' => Grundlage::aus((string) $e['quelle'])->label()];
        }
        usort($out, fn ($a, $b) => $b['stufe'] <=> $a['stufe']);

        return $out;
    }

    /**
     * Andere Zubereitung bzw. Form eines vorhandenen Bestandteils, die den Bedarf deckt — kommt
     * vor jeder neuen Zutat (küchennäher, kostet keine zusätzliche Ware).
     *
     * @param  list<array>  $teile
     * @return list<string>
     */
    private function formwechsel(array $teile, string $achse): array
    {
        $hinweise = [];
        foreach ($teile as $t) {
            $kern = $t['profil']['anker'][0] ?? null;
            if ($kern === null || $kern['anteil'] < RezeptGraph::KERN) {
                continue;
            }
            $anker = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $kern['anchor_id'])->first(['id', 'display_de', 'grundname']);
            if ($anker === null) {
                continue;
            }
            if ($achse === Achse::Roestaroma->value && $anker->grundname !== null) {
                $variante = DB::table('foodalchemist_vocab_pairing_anchors')->where('grundname', $anker->grundname)
                    ->whereIn('verfahren', array_map(fn ($v) => $v->value, self::ROESTAROMA_VERFAHREN))->orderBy('id')->value('display_de');
                if ($variante !== null) {
                    $hinweise[] = "{$t['label']}: als „{$variante}\" zubereiten";
                }
            }
            foreach (DB::table('foodalchemist_anchor_komponenten')->where('anchor_id', $anker->id)
                ->where('status', '!=', WissensStatus::Verworfen->value)->get(['name', 'liefert']) as $k) {
                if (in_array($achse, (array) json_decode((string) $k->liefert, true), true)) {
                    $hinweise[] = "{$t['label']}: als {$k->name}";
                }
            }
        }

        return array_values(array_unique(array_slice($hinweise, 0, 5)));
    }

    /** @return array<int, array{name: string, profil: array, vegan: ?bool, vegetarisch: ?bool}> */
    private function basisKatalog(?int $teamId): array
    {
        if ($this->basisKatalog !== null) {
            return $this->basisKatalog;
        }
        // Nur Basisrezepte, die das Team des Gerichts sehen darf.
        $team = $teamId !== null ? \Platform\Core\Models\Team::find($teamId) : null;
        $ids = ($team !== null ? FoodAlchemistRecipe::visibleToTeam($team) : FoodAlchemistRecipe::query())
            ->where('is_sales_recipe', false)->pluck('id')->all();
        $profile = DB::table('foodalchemist_recipe_profile')->whereIn('recipe_id', $ids)->get(['recipe_id', 'abdeckung', 'eigenschaften', 'offene_bedarfe'])->keyBy('recipe_id');
        $anker = DB::table('foodalchemist_recipe_profile_anker')->whereIn('recipe_id', $profile->keys())->get(['recipe_id', 'anchor_id', 'anteil'])->groupBy('recipe_id');
        $meta = DB::table('foodalchemist_recipes')->whereIn('id', $profile->keys())
            ->get(['id', 'name', 'function', 'spec_is_vegan', 'spec_is_vegetarian', 'taste_direction'])->keyBy('id');
        $out = [];
        foreach ($profile as $rid => $p) {
            if (! isset($anker[$rid])) {
                continue;
            }
            $out[(int) $rid] = [
                'name' => (string) $meta[$rid]->name,
                'richtung' => $meta[$rid]->taste_direction,
                'teller' => $this->tellerfaehig($meta[$rid]->function, (string) $meta[$rid]->name),
                'vegan' => $meta[$rid]->spec_is_vegan !== null ? (bool) $meta[$rid]->spec_is_vegan : null,
                'vegetarisch' => $meta[$rid]->spec_is_vegetarian !== null ? (bool) $meta[$rid]->spec_is_vegetarian : null,
                'profil' => [
                    'anker' => $anker[$rid]->map(fn ($a) => ['anchor_id' => (int) $a->anchor_id, 'anteil' => (float) $a->anteil])->all(),
                    'eigenschaften' => (array) json_decode((string) $p->eigenschaften, true),
                    'offene_bedarfe' => (array) json_decode((string) $p->offene_bedarfe, true),
                ],
            ];
        }

        return $this->basisKatalog = $out;
    }

    /**
     * Geht ein Basisrezept auf den Teller? Geprüft werden die Funktion und die Klasse im Namen
     * („Nass-Marinade: …", Regelwerk Basisrezepte §1) — Funktionen sind nicht überall gepflegt.
     * Unbekannt: ja (nicht ausblenden).
     */
    private function tellerfaehig(?string $funktion, string $name): bool
    {
        $f = trim((string) $funktion);
        if (in_array($f, self::NICHT_TELLER, true)) {
            return false;
        }
        $klasse = str_contains($name, ':') ? (string) strstr($name, ':', true) : '';
        foreach ([mb_strtolower($f), mb_strtolower($klasse)] as $text) {
            foreach (self::NICHT_TELLER_WORTE as $wort) {
                if ($text !== '' && str_contains($text, $wort)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Unbekannt ist nicht vegan: für vegane/vegetarische Gerichte nur ausdrücklich passende Basisrezepte. */
    private function passtZurDiaet(array $k, ?string $diaet): bool
    {
        if ($diaet === null) {
            return true;
        }
        if ($diaet === 'vegan' && $k['vegan'] !== true) {
            return false;
        }
        if ($diaet === 'vegetarisch' && $k['vegetarisch'] !== true && $k['vegan'] !== true) {
            return false;
        }
        // zweiter Riegel: kein Kern-Anker, der der Ernährungsform widerspricht
        $ids = array_map(fn ($a) => $a['anchor_id'], $k['profil']['anker']);
        foreach (DB::table('foodalchemist_vocab_pairing_anchors')->whereIn('id', $ids)->get(['slug', 'category', 'subcategory']) as $a) {
            if (! $this->pairing->passtZurDiaet($a, $diaet)) {
                return false;
            }
        }

        return true;
    }

    private function harmonieAussage(array $a, array $b): Aussage
    {
        $h = $this->graph->harmonie($a['profil'], $b['profil']);
        $paar = [$a['schluessel'], $b['schluessel']];
        if ($h['wert'] >= self::HARMONIERT_AB) {
            return new Aussage(AussageTyp::Harmoniert, Grundlage::InspireGemessen,
                "{$a['label']} und {$b['label']}: harmonieren", $paar, null, $h['wert']);
        }
        if ($h['passt'] >= self::PASST_AB) {
            return new Aussage(AussageTyp::Passt, Grundlage::InspireGemessen,
                "{$a['label']} und {$b['label']}: passen", $paar, null, $h['passt']);
        }

        return new Aussage(AussageTyp::Neutral, Grundlage::InspireGemessen,
            "{$a['label']} und {$b['label']}: kein nennenswerter aromatischer Bezug", $paar, null, $h['wert']);
    }

    private function wissensGrundlage(string $status): Grundlage
    {
        return $status === WissensStatus::Geprueft->value ? Grundlage::DossierGeprueft : Grundlage::DossierEntwurf;
    }

    private function bedarfGrundlage(int $anker, string $achse): Grundlage
    {
        $status = DB::table('foodalchemist_anchor_bedarfe')->where('anchor_id', $anker)->where('achse', $achse)->value('status');

        return $status === WissensStatus::Geprueft->value ? Grundlage::DossierGeprueft : Grundlage::DossierEntwurf;
    }
}
