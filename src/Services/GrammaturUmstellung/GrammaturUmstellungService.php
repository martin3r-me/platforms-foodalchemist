<?php

namespace Platform\FoodAlchemist\Services\GrammaturUmstellung;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\Scopes\TeamSicht;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\FremdSchreiben;

/**
 * Plan 98 B · Grammatur „immer automatisch“ für den Bestand (Entscheide Dominique 22.11.2026, Modell 23.11.2026: ein Gericht
 * ist EINE Portion — „die sind alle auf eine Portion angelegt“).
 *
 * Kandidat ist jede Darreichung mit freier Grammatur: menge_vom_standard = 0 und keine Deltas.
 * - Standard → automatisch: die Grammatur ist die Summe der Bestandteile (menge_vom_standard, ohne Deltas). Die Zutatenmengen
 *   werden NICHT angefasst; die Portionszahl bleibt bzw. wird 1 (leer gilt als 1, es wird nie ein anderer Wert gesetzt).
 *   Weicht die freie Grammatur von der Summe ab, zeigt der Lauf alte Grammatur, neue Summe und EK je Portion vorher/nachher —
 *   Entscheidungsliste für Dominique (z. B. io Rezept 80: 60 → 64 g).
 * - Standard mit festem Ertrag → „ertrag“ (Dominique 23.11.: „Gegart (Ertrag)“): ebenso automatisch, die angezeigte Grammatur ist
 *   gegart ({@see DarreichungService::garfaktor}); im Report eigens markiert, alte → neue Grammatur.
 * - Jede freie Variante: Weg A ({@see DarreichungService::fixiereKomposition}) — die Komposition wird als Deltas festgehalten,
 *   EK und Produktionsmengen bleiben (bei festem Ertrag gegart angezeigt).
 * - Varianten mit nur teilweisen Deltas beziehen den Rest vom Standard: wird der Standard umgestellt, werden sie vorher
 *   ebenso festgehalten (Weg A), sonst sprängen ihre übrigen Mengen mit dem Standard.
 * - nicht ableitbar (keine Bestandteile, keine Grammatur): bleibt unberührt, nie wird still 0 geschrieben.
 *
 * Ablauf in EINER Transaktion: Phase 0 Normal-Neuberechnung (damit alte Caches nicht als Abweichung der Umstellung erscheinen),
 * messen, Journal, Phase 1 Umbau, Phase 2 Normal-Neuberechnung, messen, prüfen. Trockenlauf und jeder Verstoß → Rollback.
 */
final class GrammaturUmstellungService
{
    public function __construct(
        private DarreichungService $darreichungen,
        private RecipeRecomputeService $recompute,
        private GrammaturMessung $messung,
    ) {}

    /**
     * Was umgestellt würde, ohne zu schreiben.
     *
     * @param  list<int>  $rezeptIds  leer = alle
     * @return list<array<string, mixed>> je Rezept mit Kandidaten
     */
    public function plan(array $rezeptIds = []): array
    {
        $kandidaten = DB::table('foodalchemist_recipe_presentations as p')->whereNull('p.deleted_at')->where('p.menge_vom_standard', false)
            ->whereNotExists(fn ($q) => $q->from('foodalchemist_recipe_presentation_deltas as d')->whereColumn('d.presentation_id', 'p.id')->whereNull('d.deleted_at'))
            ->when($rezeptIds !== [], fn ($q) => $q->whereIn('p.recipe_id', $rezeptIds))
            ->orderBy('p.recipe_id')->orderByDesc('p.is_standard')->orderBy('p.id')->get(['p.id', 'p.recipe_id'])->groupBy('recipe_id');

        $out = [];
        foreach ($kandidaten as $recipeId => $zeilen) {
            $r = $this->rezept((int) $recipeId);
            if ($r === null) {
                continue;   // gelöscht oder Fassung
            }
            $out[] = TeamSicht::lesenIm((int) $r->team_id, function () use ($r, $zeilen) {
                $team = Team::findOrFail($r->team_id);
                $kenn = $this->messung->rezept($r, $team);

                return ['recipe_id' => (int) $r->id, 'team_id' => (int) $r->team_id, 'name' => $r->name, 'kennzahlen' => $kenn,
                    'darreichungen' => $zeilen->map(fn ($z) => $this->aktion($r, $this->darreichung((int) $z->id), $kenn))->all()];
            });
        }

        return $out;
    }

    /**
     * @param  list<int>  $rezeptIds
     * @return array<string, mixed> Report (ok, verstoesse, rezepte, zaehlung, journal bei apply)
     */
    public function lauf(bool $apply, array $rezeptIds = []): array
    {
        $alle = $this->plan($rezeptIds);
        $plan = array_values(array_filter($alle, fn ($r) => collect($r['darreichungen'])->contains(fn ($d) => $d['aktion'] !== 'nicht_ableitbar')));
        $rids = array_column($plan, 'recipe_id');
        $bericht = ['apply' => $apply, 'ok' => false, 'verstoesse' => [], 'rezepte' => [], 'zaehlung' => [], 'journal' => null,
            'nicht_ableitbar' => $this->nurNichtAbleitbar($alle), 'fertigungstiefe_hochgestuft' => 0, 'phase0_fertigungstiefe_hochgestuft' => 0, 'phase0_ek' => [],
            'eltern_geprueft' => ['rezepte' => 0, 'darreichungen' => 0],
            'phasen' => ['0' => 'Aufholen veralteter Caches, unabhängig von der Umstellung', '1' => 'Umbau', '2' => 'Neuberechnung nach Umbau']];
        if ($rids === []) {
            $bericht['ok'] = true;

            return $bericht;
        }

        $wache = app(SchreibWache::class);
        $wache->starten();
        DB::beginTransaction();
        try {
            $grenzen = new NeuberechnungGrenzen($rids);
            $t0 = now()->startOfSecond();
            $wache->phase('0');
            $ekVorPhase0 = $this->ekStand($rids);
            // Fertigungstiefe VOR Phase 0 messen: das Hochstufen (RecipeRecomputeService, Inhalt teilfertig) ist Aufholen wie die
            // Caches — gemessen erst danach stand der Zähler immer auf 0 (Review Kurator 23.11.)
            $tiefe = fn () => DB::table('foodalchemist_recipes')->whereIn('id', $rids)->pluck('production_depth', 'id')->all();
            $geaendert = fn (array $vorher, array $nachher) => count(array_filter($rids, fn ($id) => ($vorher[$id] ?? null) !== ($nachher[$id] ?? null)));
            $tiefeVorPhase0 = $tiefe();
            $this->recompute->recomputeMany($rids, $fehler0);
            $bericht['zaehlung']['0'] = $grenzen->pruefe($t0)['zaehlung'];
            $bericht['phase0_ek'] = GrammaturMessung::ekSpruenge($ekVorPhase0, $this->ekStand($rids));
            $tiefeVorher = $tiefe();
            $bericht['phase0_fertigungstiefe_hochgestuft'] = $geaendert($tiefeVorPhase0, $tiefeVorher);
            $ekVorher = $this->ekJeKg($rids);
            $alt = $this->messen($rids);
            // Master 23.11.: Verwender außerhalb der Auswahl (etwa per --rezept ausgenommene Rezepte mit festem Ertrag) rechnet
            // Phase 2 mit — ihre Darreichungen dürfen sich dabei nicht bewegen. Gemessen nach Phase 0, also ohne das Aufholen.
            $eltern = array_values(array_diff(array_map('intval', app(\Platform\FoodAlchemist\Services\Rezept\RezeptGraphService::class)->vorfahren($rids)), $rids));
            $elternAlt = $this->messen($eltern);
            $journal = $this->journal($rids);

            $wache->phase('1', array_column(array_filter($plan, fn ($r) => collect($r['darreichungen'])
                ->contains(fn ($d) => $d['standard'] && $d['aktion'] !== 'nicht_ableitbar')), 'recipe_id'));
            $bericht['zaehlung']['1'] = FremdSchreiben::erlaubt('grammatur-umstellung', fn () => $this->umbauen($plan));

            $t2 = now()->startOfSecond();
            $wache->phase('2');
            $this->recompute->recomputeMany($rids, $fehler2);
            $pruef = $grenzen->pruefe($t2);
            $bericht['zaehlung']['2'] = $pruef['zaehlung'];
            $wache->phase(null);

            $neu = $this->messen($rids);
            $bericht['rezepte'] = $this->vergleich($plan, $alt, $neu);
            $elternVerstoesse = GrammaturMessung::elternVerstoesse($elternAlt, $this->messen($eltern));
            $bericht['eltern_geprueft'] = ['rezepte' => count($eltern), 'darreichungen' => array_sum(array_map(fn ($e) => count($e['darreichungen']), $elternAlt))];
            $bericht['fertigungstiefe_hochgestuft'] = $geaendert($tiefeVorher, $tiefe());   // durch die Umstellung selbst — erwartet 0

            $v = [...$wache->verstoesse(), ...$grenzen->pruefe($t0)['verstoesse'], ...$pruef['verstoesse'], ...$elternVerstoesse];
            foreach ([...($fehler0 ?? []), ...($fehler2 ?? [])] as $id => $f) {
                $v[] = "Neuberechnung Rezept {$id}: {$f}";
            }
            foreach ($this->ekJeKg($rids) as $id => $ek) {
                if (abs(($ekVorher[$id] ?? 0.0) - $ek) > 1e-6) {
                    $v[] = "Rezept {$id}: ek_per_kg änderte sich ({$ekVorher[$id]} → {$ek}) — die Umstellung darf nur Darreichungen bewegen";
                }
            }
            foreach ($bericht['rezepte'] as $r) {
                foreach ($r['darreichungen'] as $d) {
                    if ($d['kategorie'] === 'unerwartet') {
                        $v[] = "Darreichung {$d['darreichung_id']} (Rezept {$r['recipe_id']}): ".$d['hinweis'];
                    }
                }
            }
            $bericht['verstoesse'] = array_values(array_unique($v));
            $bericht['ok'] = $bericht['verstoesse'] === [];
            if ($apply && $bericht['ok']) {
                DB::commit();
                $bericht['journal'] = $journal;
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $bericht['ok'] = false;
            $bericht['verstoesse'][] = 'Abbruch: '.$e->getMessage();
        } finally {
            $wache->beenden();
        }

        return $bericht;
    }

    /** Stellt den Zustand vor `lauf(apply)` wieder her und rechnet über den Normalweg neu. */
    public function rueckweg(array $journal): int
    {
        if (($journal['version'] ?? null) !== 1) {
            throw new \RuntimeException('Unbekanntes Journal-Format.');
        }
        DB::transaction(function () use ($journal) {
            $presIds = array_column($journal['darreichungen'], 'id');
            DB::table('foodalchemist_recipe_presentation_deltas')->whereIn('presentation_id', $presIds)
                ->whereNotIn('id', array_column($journal['deltas'], 'id'))->delete();
            foreach ($journal['deltas'] as $row) {
                DB::table('foodalchemist_recipe_presentation_deltas')->updateOrInsert(['id' => $row['id']], $row);
            }
            foreach ($journal['darreichungen'] as $row) {
                DB::table('foodalchemist_recipe_presentations')->where('id', $row['id'])->update(array_intersect_key($row,
                    array_flip(['menge_vom_standard', 'komponenten_referenz_g', 'quantity_per_unit_g'])));
            }
            $spalten = array_flip(self::rezeptSpalten());
            foreach ($spalten === [] ? [] : $journal['rezepte'] as $row) {
                $werte = array_intersect_key($row, $spalten);
                if ($werte !== []) {
                    DB::table('foodalchemist_recipes')->where('id', $row['id'])->update($werte);
                }
            }
        });
        $this->recompute->recomputeMany(array_column($journal['rezepte'], 'id'));

        return count($journal['darreichungen']);
    }

    // ── intern ────────────────────────────────────────────────────────────

    private function rezept(int $id): ?FoodAlchemistRecipe
    {
        return FoodAlchemistRecipe::ohneTeamSicht('grammatur-umstellung')->with(['ingredients.unit', 'ingredients.gp', 'ingredients.referencedRecipe'])->find($id);
    }

    private function darreichung(int $id): FoodAlchemistRecipeDarreichung
    {
        return FoodAlchemistRecipeDarreichung::ohneTeamSicht('grammatur-umstellung')->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function aktion(FoodAlchemistRecipe $r, FoodAlchemistRecipeDarreichung $d, array $kenn): array
    {
        $z = ['darreichung_id' => (int) $d->id, 'standard' => (bool) $d->is_standard, 'name' => $d->name, 'aktion' => 'nicht_ableitbar',
            'fester_ertrag' => (bool) $kenn['fester_ertrag'], 'grund' => null];
        $g = $this->darreichungen->grammJeEinheit($d, $r);
        if ($kenn['zutaten'] === 0 || $kenn['b_g'] <= 0) {
            return ['grund' => 'keine Bestandteile mit Menge'] + $z;
        }
        // Trockenlauf io 23.11. (R149): Bestandteile ohne Gramm (Stück ohne Stückgewicht …) gingen im Komponentenmodell verloren —
        // EK, VK und Produktion sanken. Solche Gerichte bleiben unangetastet (Entscheid Dominique 22.11.: nicht ableitbar).
        if (($kenn['ohne_gewicht'] ?? []) !== []) {
            return ['grund' => 'Bestandteile ohne Gewicht: '.implode(', ', $kenn['ohne_gewicht'])] + $z;
        }
        if ($g === null || $g <= 0) {
            return ['grund' => 'keine Grammatur'] + $z;
        }
        // Standard → automatisch: Summe der Bestandteile, bei festem Ertrag gegart (Dominique 23.11.)
        if ($d->is_standard) {
            return ['aktion' => $kenn['fester_ertrag'] ? 'ertrag' : 'summe'] + $z;
        }

        return ['aktion' => 'komposition'] + $z;
    }

    /** Phase 1. @return array<string, array<string, int>> geschriebene Zeilen je Tabelle und team_id */
    private function umbauen(array $plan): array
    {
        $zaehlung = [];
        $zaehle = function (string $t, int $team, int $n = 1) use (&$zaehlung) {
            $zaehlung[$t][(string) $team] = ($zaehlung[$t][(string) $team] ?? 0) + $n;
        };
        foreach ($plan as $p) {
            TeamSicht::lesenIm($p['team_id'], function () use ($p, $zaehle) {
                $team = Team::findOrFail($p['team_id']);
                // Varianten mit teilweisen Deltas hängen am Standard — vor seiner Umstellung festhalten (Weg A)
                if (collect($p['darreichungen'])->contains(fn ($z) => $z['standard'] && in_array($z['aktion'], ['summe', 'ertrag'], true))) {
                    $abhaengig = FoodAlchemistRecipeDarreichung::ohneTeamSicht('grammatur-umstellung')->where('recipe_id', $p['recipe_id'])
                        ->where('is_standard', false)->where('menge_vom_standard', false)->whereHas('deltas')->get();
                    foreach ($abhaengig as $v) {
                        $this->darreichungen->fixiereKomposition($v, $team);
                        $zaehle('foodalchemist_recipe_presentation_deltas', $p['team_id'], $v->deltas()->count());
                    }
                }
                foreach ($p['darreichungen'] as $z) {
                    $r = $this->rezept($p['recipe_id']);
                    $d = $this->darreichung($z['darreichung_id']);
                    if ($z['aktion'] === 'komposition') {
                        $g = $this->darreichungen->grammJeEinheit($d, $r);
                        $this->darreichungen->fixiereKomposition($d, $team);
                        DB::table('foodalchemist_recipe_presentations')->where('id', $d->id)->update(['komponenten_referenz_g' => $g, 'updated_at' => now()]);
                        $zaehle('foodalchemist_recipe_presentation_deltas', $p['team_id'], $d->deltas()->count());
                        $zaehle('foodalchemist_recipe_presentations', $p['team_id']);
                    } elseif (in_array($z['aktion'], ['summe', 'ertrag'], true)) {
                        // Modell 23.11.: Portionszahl 1 (leer gilt als 1) — nur ein Altbestand > 1 wird auf 1 gesetzt, nie ein anderer Wert
                        // 98 · 3.5 6b/6c: die Spalte fällt weg — nur solange es sie gibt, und nie ein anderer Wert als 1
                        if (in_array('sales_unit_count', self::rezeptSpalten(), true) && (int) ($r->getAttributes()['sales_unit_count'] ?? 1) > 1) {
                            DB::table('foodalchemist_recipes')->where('id', $r->id)->update(['sales_unit_count' => 1, 'updated_at' => now()]);
                            $zaehle('foodalchemist_recipes', $p['team_id']);
                        }
                        DB::table('foodalchemist_recipe_presentations')->where('id', $d->id)
                            ->update(['menge_vom_standard' => true, 'komponenten_referenz_g' => null, 'updated_at' => now()]);
                        $zaehle('foodalchemist_recipe_presentations', $p['team_id']);
                    }
                }
                // Der Kopf folgt in Phase 2 über die Kopf-Spiegelung (DarreichungService::kopfSpiegeln) — gegart wie der Standard
            });
        }

        return $zaehlung;
    }

    /** @return array<int, array{rezept: array, darreichungen: array<int, array>}> */
    private function messen(array $rids): array
    {
        $out = [];
        foreach ($rids as $id) {
            $r = $this->rezept($id);
            $out[$id] = TeamSicht::lesenIm((int) $r->team_id, function () use ($r) {
                $team = Team::findOrFail($r->team_id);
                $pres = FoodAlchemistRecipeDarreichung::ohneTeamSicht('grammatur-umstellung')->where('recipe_id', $r->id)->orderBy('id')->get();

                return ['rezept' => $this->messung->rezept($r, $team),
                    'darreichungen' => $pres->mapWithKeys(fn ($d) => [(int) $d->id => $this->messung->darreichung($r, $d, $team)])->all()];
            });
        }

        return $out;
    }

    private function vergleich(array $plan, array $alt, array $neu): array
    {
        $out = [];
        foreach ($plan as $p) {
            $aktionen = collect($p['darreichungen'])->keyBy('darreichung_id');
            $zeilen = [];
            foreach ($neu[$p['recipe_id']]['darreichungen'] as $id => $n) {
                $a = $alt[$p['recipe_id']]['darreichungen'][$id] ?? null;
                $akt = $aktionen->get($id);
                $diff = $a === null ? ['neu'] : GrammaturMessung::unterschiede($a, $n);
                $kat = $diff === [] ? 'gleich' : 'nicht_verlustfrei';
                $hinweis = null;
                if ($diff !== [] && ($akt === null || $akt['aktion'] === 'nicht_ableitbar') && ! $this->folgtStandard($id, $p)) {
                    $kat = 'unerwartet';
                    $hinweis = 'nicht umgestellt, aber verändert ('.implode(', ', $diff).')';
                }
                $zeilen[] = ['darreichung_id' => $id, 'standard' => $n['standard'], 'aktion' => $akt['aktion'] ?? 'folgt_standard',
                    'fester_ertrag' => (bool) $alt[$p['recipe_id']]['rezept']['fester_ertrag'], 'grund' => $akt['grund'] ?? null,
                    'kategorie' => $kat, 'unterschiede' => $diff, 'hinweis' => $hinweis ?? $this->hinweis($diff, $alt[$p['recipe_id']]['rezept']),
                    'gramm_prozent' => GrammaturMessung::prozent($a['gramm'] ?? null, $n['gramm']), 'alt' => $a, 'neu' => $n];
            }
            $out[] = ['recipe_id' => $p['recipe_id'], 'team_id' => $p['team_id'], 'name' => $p['name'],
                'kennzahlen_alt' => $alt[$p['recipe_id']]['rezept'], 'kennzahlen_neu' => $neu[$p['recipe_id']]['rezept'], 'darreichungen' => $zeilen];
        }

        return $out;
    }

    /** Eine Darreichung „wie Standard“ zieht mit, wenn der Standard umgestellt wird. */
    private function folgtStandard(int $presId, array $p): bool
    {
        $standardUmgestellt = collect($p['darreichungen'])->contains(fn ($d) => $d['standard'] && $d['aktion'] !== 'nicht_ableitbar');

        return $standardUmgestellt && (bool) DB::table('foodalchemist_recipe_presentations')->where('id', $presId)->value('menge_vom_standard');
    }

    private function hinweis(array $diff, array $kenn): ?string
    {
        if ($diff === []) {
            return null;
        }
        $teile = [];
        if (in_array('gramm', $diff, true) && $kenn['fester_ertrag']) {
            $teile[] = 'fester Ertrag → gegart: angezeigt wird der Ertrag je Portion';
        }
        if ($kenn['unbepreist'] > 0) {
            $teile[] = $kenn['unbepreist'].' unbepreiste Bestandteile';
        }

        return $teile === [] ? null : implode('; ', $teile);
    }

    /** @return array<int, float> ek_per_kg aller Rezepte im Umkreis (umgestellte + Eltern) */
    private function ekJeKg(array $rids): array
    {
        return DB::table('foodalchemist_recipes')->whereIn('id', app(\Platform\FoodAlchemist\Services\Rezept\RezeptGraphService::class)->vorfahren($rids))
            ->pluck('ek_per_kg_eur', 'id')->map(fn ($v) => (float) $v)->all();
    }

    /**
     * EK-Stand im Umkreis (umgestellte Rezepte + Eltern) für den Phase-0-Report: je Tabelle Zeilen-Id → [team_id, recipe_id, ek].
     *
     * @return array<string, array<int, array{0: mixed, 1: int, 2: ?float}>>
     */
    private function ekStand(array $rids): array
    {
        $umkreis = app(\Platform\FoodAlchemist\Services\Rezept\RezeptGraphService::class)->vorfahren($rids);
        $zeilen = fn ($q, string $ek) => $q->get()->mapWithKeys(fn ($z) => [(int) $z->id => [$z->team_id, (int) $z->recipe_id,
            $z->{$ek} === null ? null : (float) $z->{$ek}]])->all();

        return [
            'foodalchemist_recipes' => $zeilen(DB::table('foodalchemist_recipes')->whereIn('id', $umkreis)
                ->select('id', 'team_id', 'id as recipe_id', 'ek_per_kg_eur'), 'ek_per_kg_eur'),
            'foodalchemist_recipe_presentations' => $zeilen(DB::table('foodalchemist_recipe_presentations')->whereIn('recipe_id', $umkreis)
                ->select('id', 'team_id', 'recipe_id', 'ek_portion'), 'ek_portion'),
        ];
    }

    /**
     * 98 · 3.5 (6b/6c): die Rezept-Spiegelspalten sales_unit_count / sales_quantity_per_unit_g entfallen. Journal, Rückweg und der
     * Portionszahl-Schreibzugriff fassen nur die noch vorhandenen an — die Umstellung läuft vor und nach dem Drop.
     *
     * @return list<string>
     */
    private static function rezeptSpalten(): array
    {
        return array_values(array_filter(['sales_unit_count', 'sales_quantity_per_unit_g'], fn ($s) => Schema::hasColumn('foodalchemist_recipes', $s)));
    }

    /** @return array<string, mixed> Rohzeilen vor Phase 1 */
    private function journal(array $rids): array
    {
        $pres = DB::table('foodalchemist_recipe_presentations')->whereIn('recipe_id', $rids)->get()->map(fn ($r) => (array) $r)->all();

        return ['version' => 1, 'erstellt_at' => now()->toIso8601String(), 'darreichungen' => $pres,
            'deltas' => DB::table('foodalchemist_recipe_presentation_deltas')->whereIn('presentation_id', array_column($pres, 'id'))->get()->map(fn ($r) => (array) $r)->all(),
            'rezepte' => DB::table('foodalchemist_recipes')->whereIn('id', $rids)->get(['id', ...self::rezeptSpalten()])->map(fn ($r) => (array) $r)->all()];
    }

    private function nurNichtAbleitbar(array $plan): array
    {
        $out = [];
        foreach ($plan as $r) {
            foreach ($r['darreichungen'] as $d) {
                if ($d['aktion'] === 'nicht_ableitbar') {
                    $out[] = ['recipe_id' => $r['recipe_id'], 'team_id' => $r['team_id'], 'name' => $r['name'], 'darreichung_id' => $d['darreichung_id'],
                        'standard' => $d['standard'], 'grund' => $d['grund'], 'zutaten' => $r['kennzahlen']['zutaten'], 'b_g' => $r['kennzahlen']['b_g']];
                }
            }
        }

        return $out;
    }
}
