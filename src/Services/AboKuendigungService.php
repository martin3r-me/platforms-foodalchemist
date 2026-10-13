<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Jobs\RecomputeTeamRecipesJob;
use Platform\FoodAlchemist\Models\FoodAlchemistAbo;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 §12 D · Durchstich Schritt 5: Kündigung und Zurückziehen als Ablauf.
 *
 * 1. „Genutzt" = in einer AKTIVEN Ausgabe des Mandanten bzw. seiner Unter-Teams (Foodbook, Speisekarte, Speiseplan nicht
 *    archiviert; Angebot nicht angenommen/abgelehnt; Konzept nicht archiviert) oder als Zutat in einem eigenen Rezept.
 *    Entwürfe zählen. Archivierte Ausgaben und historische Angebote bekommen KEINE Kopie, sie behalten die Fassung.
 * 2. Tiefe Kopie ohne Doppel: die ganze benötigte Kette, jedes (Basis-)Rezept genau einmal, alle Verweise auf diese Kopie,
 *    Ersatz-Beziehungen kommen mit. Kopie = normale Arbeitszeile des Mandanten (kopie_von_id = Familie, Stand = jetzt),
 *    danach mit SEINEN Preisen neu gerechnet.
 * 3. Zweites Abo: Was über ein anderes, weiter aktives Abo sichtbar bleibt, wird nicht kopiert und verschwindet nicht.
 * 4. Vorschau vor der Ausführung (bestaetigt = false).
 *
 * Fassungs-Zeilen werden nie gelöscht: eingefrorene Stände (Snapshots, archivierte Ausgaben) bleiben auflösbar.
 */
class AboKuendigungService
{
    /** Ausgabe-Positionen → Pfad zum Eltern-Objekt und dessen „aktiv"-Regel. */
    private const AUSGABEN = [
        'foodbook' => ['foodalchemist_foodbook_blocks AS x', 'foodalchemist_foodbook_chapters AS k', 'x.chapter_id', 'k.foodbook_id', 'foodalchemist_foodbooks AS p'],
        'speisekarte' => ['foodalchemist_menu_card_items AS x', 'foodalchemist_menu_card_sections AS k', 'x.section_id', 'k.menu_card_id', 'foodalchemist_menu_cards AS p'],
        'angebot' => ['foodalchemist_offer_blocks AS x', 'foodalchemist_offer_chapters AS k', 'x.chapter_id', 'k.offer_id', 'foodalchemist_offers AS p'],
    ];

    public function __construct(
        private FaRechte $rechte,
        private AboService $abos,
        private PlattformProtokoll $protokoll,
    ) {}

    /**
     * Abo kündigen. Darf: der Master mit Rolle „Freigeben" oder ein Admin des Mandanten (sein Oberteam). Ohne
     * bestaetigt = nur die Vorschau.
     *
     * @return array{vorschau: array<string,mixed>, ausgefuehrt: bool, kopien?: array<int,int>, umgehaengt?: int}
     */
    public function kuendigen(Team $handelnd, int $aboId, ?User $actor, bool $bestaetigt, bool $systemLauf = false): array
    {
        $abo = FoodAlchemistAbo::findOrFail($aboId);
        if (! $systemLauf) {
            $this->pruefeRecht($handelnd, $abo, $actor);
        }
        if ($abo->status !== FoodAlchemistAbo::AKTIV) {
            throw new \RuntimeException('Dieses Abo ist nicht mehr aktiv.');
        }
        $mandant = Team::findOrFail($abo->mandant_team_id);
        $vorher = $this->abos->huelle((int) $mandant->id)['recipe'] ?? [];
        $nachher = $this->abos->huelle((int) $mandant->id, ohneAboId: (int) $abo->id)['recipe'] ?? [];
        $vorschau = $this->vorschau($mandant, array_values(array_diff($vorher, $nachher)));
        if (! $bestaetigt) {
            return ['vorschau' => $vorschau, 'ausgefuehrt' => false];
        }

        $ergebnis = DB::transaction(function () use ($abo, $mandant, $vorschau, $actor) {
            [$kopien, $umgehaengt] = $this->kopierenUndUmhaengen($mandant, $vorschau['genutzt_ids']);
            $abo->forceFill(['status' => FoodAlchemistAbo::GEKUENDIGT, 'beendet_at' => now(), 'beendet_von' => $actor?->id])->save();

            return [$kopien, $umgehaengt];
        });
        $this->abos->neuBerechnen((int) $mandant->id);
        $this->nachrechnen($ergebnis[0]);
        $this->protokoll->schreibe('abo_gekuendigt', ['mandant_team_id' => $mandant->id, 'abo_id' => $abo->id,
            'kopiert' => count($ergebnis[0]), 'umgehaengt' => $ergebnis[1], 'verschwindet' => count($vorschau['verschwindet_ids'])], $actor, $abo);

        return ['vorschau' => $vorschau, 'ausgefuehrt' => true, 'kopien' => $ergebnis[0], 'umgehaengt' => $ergebnis[1]];
    }

    /**
     * Master zieht ein Rezept aus einer abonnierten Sammlung zurück (§8: Löschen eines abonnierten Rezepts ist gesperrt,
     * nur Zurückziehen). Jeder betroffene Mandant läuft denselben Ablauf wie bei einer Kündigung.
     *
     * @return array{mandanten: list<array{mandant_team_id:int, vorschau: array<string,mixed>, kopien?: array<int,int>}>, ausgefuehrt: bool}
     */
    public function zurueckziehen(Team $master, int $sammlungId, int $recipeId, ?User $actor, bool $bestaetigt): array
    {
        if (! MasterTeam::istMaster($master)) {
            throw new \RuntimeException('Zurückziehen gibt es nur im Master-Team.');
        }
        $this->rechte->pruefe($actor, $master, FaRolle::Freigeben, 'Abo-Rezept zurückziehen');
        if (! DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $sammlungId)->where('typ', 'recipe')->where('objekt_id', $recipeId)->exists()) {
            throw new \RuntimeException('Das Rezept steht nicht in dieser Sammlung.');
        }

        $mandanten = [];
        $mandantIds = DB::table('foodalchemist_abos')->where('sammlung_id', $sammlungId)->where('status', FoodAlchemistAbo::AKTIV)
            ->distinct()->pluck('mandant_team_id')->map(fn ($v) => (int) $v)->all();
        foreach ($mandantIds as $mid) {
            $vorher = $this->abos->huelle($mid)['recipe'] ?? [];
            $nachher = $this->abos->huelle($mid, ohneSammlungsObjekt: ['sammlung_id' => $sammlungId, 'objekt_id' => $recipeId])['recipe'] ?? [];
            $mandanten[$mid] = ['mandant_team_id' => $mid, 'vorschau' => $this->vorschau(Team::findOrFail($mid), array_values(array_diff($vorher, $nachher)))];
        }
        if (! $bestaetigt) {
            return ['mandanten' => array_values($mandanten), 'ausgefuehrt' => false];
        }

        $alleKopien = [];
        DB::transaction(function () use ($sammlungId, $recipeId, &$mandanten, &$alleKopien) {
            foreach ($mandanten as $mid => $m) {
                [$kopien, $umgehaengt] = $this->kopierenUndUmhaengen(Team::findOrFail($mid), $m['vorschau']['genutzt_ids']);
                $mandanten[$mid]['kopien'] = $kopien;
                $mandanten[$mid]['umgehaengt'] = $umgehaengt;
                $alleKopien += $kopien;
            }
            DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $sammlungId)->where('typ', 'recipe')->where('objekt_id', $recipeId)->delete();
        });
        $this->abos->neuBerechnenAlle();
        $this->nachrechnen($alleKopien);
        $this->protokoll->schreibe('abo_rezept_zurueckgezogen', ['sammlung_id' => $sammlungId, 'recipe_id' => $recipeId,
            'mandanten' => array_keys($mandanten)], $actor, FoodAlchemistRecipe::find($recipeId));

        return ['mandanten' => array_values($mandanten), 'ausgefuehrt' => true];
    }

    /**
     * Was eine Kündigung/ein Zurückziehen beim Mandanten bewirkt: aus den wegfallenden Fassungen die genutzten (bekommen
     * eine tiefe Kopie) und die ungenutzten (verschwinden).
     *
     * @param  list<int>  $wegfallend  Fassungs-IDs, die danach nicht mehr in der Hülle stehen
     * @return array{genutzt_ids: list<int>, verschwindet_ids: list<int>, genutzt: list<array{id:int,name:string,wo:list<string>}>, verschwindet: list<array{id:int,name:string}>}
     */
    public function vorschau(Team $mandant, array $wegfallend): array
    {
        $teams = RecomputeTeamRecipesJob::teamUndNachfahren((int) $mandant->id);
        $nutzung = $wegfallend === [] ? [] : $this->nutzung($wegfallend, $teams);
        $namen = $wegfallend === [] ? collect() : DB::table('foodalchemist_recipes')->whereIn('id', $wegfallend)->pluck('name', 'id');
        $genutzt = array_values(array_filter($wegfallend, fn ($id) => isset($nutzung[$id])));
        // Unter-Fassungen genutzter Rezepte gehen in der tiefen Kopie mit — sie „verschwinden" nicht
        $mit = array_values(array_diff(array_intersect($this->kette($genutzt), $wegfallend), $genutzt));
        $verschwindet = array_values(array_diff($wegfallend, $genutzt, $mit));
        sort($genutzt);
        sort($mit);
        sort($verschwindet);

        return [
            'genutzt_ids' => $genutzt,
            'mitkopiert_ids' => $mit,
            'verschwindet_ids' => $verschwindet,
            'genutzt' => array_map(fn ($id) => ['id' => $id, 'name' => (string) ($namen[$id] ?? '?'), 'wo' => $nutzung[$id]], $genutzt),
            'mitkopiert' => array_map(fn ($id) => ['id' => $id, 'name' => (string) ($namen[$id] ?? '?')], $mit),
            'verschwindet' => array_map(fn ($id) => ['id' => $id, 'name' => (string) ($namen[$id] ?? '?')], $verschwindet),
        ];
    }

    /**
     * @param  list<int>  $ids  Fassungs-IDs
     * @param  list<int>  $teams  Mandant + Unter-Teams
     * @return array<int, list<string>>  Fassungs-ID => Fundstellen
     */
    private function nutzung(array $ids, array $teams): array
    {
        $treffer = [];
        $merke = function (iterable $rows, string $wo) use (&$treffer) {
            foreach ($rows as $r) {
                $treffer[(int) $r->rid][] = $wo;
            }
        };
        foreach (self::AUSGABEN as $art => [$tab, $kap, $kapFk, $parentFk, $parent]) {
            $q = DB::table($tab)->join($kap, 'k.id', '=', $kapFk)->join($parent, 'p.id', '=', $parentFk)
                ->whereIn('x.sales_recipe_id', $ids)->whereIn('x.team_id', $teams)
                ->whereNull('x.deleted_at')->whereNull('p.deleted_at');
            $art === 'angebot'
                ? $q->whereNotIn('p.status', ['angenommen', 'abgelehnt'])        // D1: historische Angebote behalten die Fassung
                : $q->where(fn ($w) => $w->whereNull('p.status')->orWhere('p.status', '!=', 'archiviert'));
            $merke($q->select('x.sales_recipe_id AS rid')->distinct()->get(), $art);
        }
        $merke(DB::table('foodalchemist_menu_plan_entries AS x')->join('foodalchemist_menu_plans AS p', 'p.id', '=', 'x.menu_plan_id')
            ->whereIn('x.sales_recipe_id', $ids)->whereIn('x.team_id', $teams)->whereNull('x.deleted_at')->whereNull('p.deleted_at')
            ->where(fn ($w) => $w->whereNull('p.status')->orWhere('p.status', '!=', 'archiviert'))
            ->select('x.sales_recipe_id AS rid')->distinct()->get(), 'speiseplan');
        $merke(DB::table('foodalchemist_concept_slots AS x')->join('foodalchemist_concepts AS p', 'p.id', '=', 'x.concept_id')
            ->whereIn('x.sales_recipe_id', $ids)->whereIn('x.team_id', $teams)->whereNull('x.deleted_at')->whereNull('p.deleted_at')
            ->where(fn ($w) => $w->whereNull('p.status')->orWhere('p.status', '!=', 'archiviert'))
            ->select('x.sales_recipe_id AS rid')->distinct()->get(), 'konzept');
        // als Zutat in einem EIGENEN Rezept (Arbeitszeile) des Mandanten bzw. seiner Unter-Teams
        $merke(DB::table('foodalchemist_recipe_ingredients AS z')->join('foodalchemist_recipes AS r', 'r.id', '=', 'z.recipe_id')
            ->whereIn('z.referenced_recipe_id', $ids)->whereIn('r.team_id', $teams)->where('r.ist_fassung', false)
            ->whereNull('z.deleted_at')->whereNull('r.deleted_at')
            ->select('z.referenced_recipe_id AS rid')->distinct()->get(), 'zutat');

        return array_map(fn ($w) => array_values(array_unique($w)), $treffer);
    }

    /**
     * Tiefe Kopie der genutzten Fassungen samt Unter-Fassungen (jede genau einmal) und Umhängen der lebenden Verweise.
     *
     * @param  list<int>  $wurzeln
     * @return array{0: array<int,int>, 1: int}  [Fassungs-ID => Kopie-ID, Zahl umgehängter Verweise]
     */
    private function kopierenUndUmhaengen(Team $mandant, array $wurzeln): array
    {
        if ($wurzeln === []) {
            return [[], 0];
        }
        $kette = $this->kette($wurzeln);

        $map = [];
        foreach ($kette as $fid) {            // erst alle Köpfe anlegen, dann Zutaten (Verweise zeigen auf die Kopien)
            $map[$fid] = $this->kopiereKopf(FoodAlchemistRecipe::mitFassungen()->findOrFail($fid), $mandant);
        }
        foreach ($kette as $fid) {
            $this->kopiereInhalt(FoodAlchemistRecipe::mitFassungen()->findOrFail($fid), $map[$fid], $map, $mandant);
        }
        $this->kopiereErsatz($kette, $map, $mandant);

        $teams = RecomputeTeamRecipesJob::teamUndNachfahren((int) $mandant->id);
        $umgehaengt = 0;
        foreach ($map as $alt => $neu) {
            $umgehaengt += $this->haengeUm($alt, $neu, $teams);
        }

        return [$map, $umgehaengt];
    }

    /**
     * Ganze benötigte Kette: die Wurzeln und alle Unter-FASSUNGEN (Nicht-Fassungen = global/Stamm bleiben Verweis).
     *
     * @param  list<int>  $wurzeln
     * @return list<int>
     */
    private function kette(array $wurzeln): array
    {
        $kette = [];
        $offen = $wurzeln;
        while ($offen !== []) {
            $offen = array_values(array_diff(array_unique($offen), $kette));
            $kette = array_merge($kette, $offen);
            $offen = $offen === [] ? [] : DB::table('foodalchemist_recipe_ingredients AS z')
                ->join('foodalchemist_recipes AS r', 'r.id', '=', 'z.referenced_recipe_id')
                ->whereIn('z.recipe_id', $offen)->whereNull('z.deleted_at')->where('r.ist_fassung', true)
                ->pluck('z.referenced_recipe_id')->map(fn ($v) => (int) $v)->all();
        }

        return $kette;
    }

    private function kopiereKopf(FoodAlchemistRecipe $fassung, Team $mandant): int
    {
        $kopie = $fassung->replicate(['uuid', 'legacy_id', 'recipe_key', 'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash',
            'veroeffentlicht_at', 'veroeffentlicht_von', 'aenderungsnotiz', 'fassung_allergen_geaendert', 'kopie_von_id', 'kopie_stand_at']);
        $kopie->forceFill([
            'team_id' => $mandant->id, 'ist_fassung' => false, 'recipe_key' => $this->freierKey($mandant, (string) $fassung->recipe_key),
            'kopie_von_id' => $fassung->fassung_von_id, 'kopie_stand_at' => now(),   // originalGeaendert() vergleicht gegen die Familie
        ])->save();

        return (int) $kopie->id;
    }

    /** @param array<int,int> $map */
    private function kopiereInhalt(FoodAlchemistRecipe $fassung, int $kopieId, array $map, Team $mandant): void
    {
        $zutatMap = [];
        foreach ($fassung->ingredients()->get() as $z) {
            $neu = $z->replicate(['uuid', 'legacy_id']);
            $neu->forceFill(['recipe_id' => $kopieId, 'team_id' => $mandant->id,
                'referenced_recipe_id' => $z->referenced_recipe_id !== null ? ($map[(int) $z->referenced_recipe_id] ?? $z->referenced_recipe_id) : null])->save();
            $zutatMap[(int) $z->id] = (int) $neu->id;
        }
        foreach (FoodAlchemistRecipeStep::where('recipe_id', $fassung->id)->get() as $s) {
            $s->replicate(['uuid'])->forceFill(['recipe_id' => $kopieId, 'team_id' => $mandant->id])->save();
        }
        foreach (DB::table('foodalchemist_recipe_equipment')->where('recipe_id', $fassung->id)->get() as $e) {
            $zeile = (array) $e;
            unset($zeile['id']);
            DB::table('foodalchemist_recipe_equipment')->insert([...$zeile, 'recipe_id' => $kopieId]);
        }
        foreach (FoodAlchemistRecipeDarreichung::where('recipe_id', $fassung->id)->get() as $d) {
            $dk = $d->replicate(['uuid', 'legacy_id']);
            $dk->forceFill(['recipe_id' => $kopieId, 'team_id' => $mandant->id])->save();
            foreach ($d->deltas()->get() as $delta) {
                if (isset($zutatMap[(int) $delta->recipe_ingredient_id])) {
                    $delta->replicate(['uuid', 'legacy_id'])->forceFill(['presentation_id' => $dk->id, 'team_id' => $mandant->id,
                        'recipe_ingredient_id' => $zutatMap[(int) $delta->recipe_ingredient_id]])->save();
                }
            }
        }
    }

    /**
     * Ersatz-Beziehungen der Kette kommen mit (§12 D2): Kanten des Masters zwischen Rezepten der Kette (über Fassung ODER
     * Familie adressiert) bzw. von einem Kettenrezept zu einem GP. Kanten zu fremden, nicht kopierten Master-Rezepten nicht.
     *
     * @param  list<int>  $kette
     * @param  array<int,int>  $map
     */
    private function kopiereErsatz(array $kette, array $map, Team $mandant): void
    {
        $ziel = $map;
        foreach (DB::table('foodalchemist_recipes')->whereIn('id', $kette)->pluck('fassung_von_id', 'id') as $fid => $familie) {
            if ($familie !== null) {
                $ziel[(int) $familie] = $map[(int) $fid];
            }
        }
        $ids = array_keys($ziel);
        $kanten = FoodAlchemistComponentEquivalent::query()
            ->where(fn ($w) => $w->where(fn ($q) => $q->where('source_kind', 'recipe')->whereIn('source_id', $ids))
                ->orWhere(fn ($q) => $q->where('alt_kind', 'recipe')->whereIn('alt_id', $ids)))
            ->get();
        foreach ($kanten as $k) {
            $quelle = $k->source_kind === 'recipe' ? ($ziel[(int) $k->source_id] ?? null) : (int) $k->source_id;
            $alt = $k->alt_kind === 'recipe' ? ($ziel[(int) $k->alt_id] ?? null) : (int) $k->alt_id;
            if ($quelle === null || $alt === null) {
                continue;
            }
            FoodAlchemistComponentEquivalent::firstOrCreate(
                ['team_id' => $mandant->id, 'source_kind' => $k->source_kind, 'source_id' => $quelle, 'alt_kind' => $k->alt_kind, 'alt_id' => $alt],
                ['umrechnungsfaktor' => $k->umrechnungsfaktor, 'standard_seite' => $k->standard_seite, 'match_confidence' => $k->match_confidence, 'notes' => $k->notes],
            );
        }
    }

    /** Lebende Verweise (aktive Ausgaben, eigene Rezepte) von der Fassung auf die Kopie. Historie bleibt. @param list<int> $teams */
    private function haengeUm(int $alt, int $neu, array $teams): int
    {
        $n = 0;
        $treffer = $this->nutzung([$alt], $teams);
        if (! isset($treffer[$alt])) {
            return 0;
        }
        foreach (self::AUSGABEN as $art => [$tab, $kap, $kapFk, $parentFk, $parent]) {
            $q = DB::table($tab)->join($kap, 'k.id', '=', $kapFk)->join($parent, 'p.id', '=', $parentFk)
                ->where('x.sales_recipe_id', $alt)->whereIn('x.team_id', $teams)->whereNull('x.deleted_at')->whereNull('p.deleted_at');
            $art === 'angebot'
                ? $q->whereNotIn('p.status', ['angenommen', 'abgelehnt'])
                : $q->where(fn ($w) => $w->whereNull('p.status')->orWhere('p.status', '!=', 'archiviert'));
            $ids = $q->pluck('x.id')->all();
            $n += DB::table(explode(' AS ', $tab)[0])->whereIn('id', $ids ?: [0])->update(['sales_recipe_id' => $neu]);
        }
        foreach (['foodalchemist_menu_plan_entries' => ['foodalchemist_menu_plans', 'menu_plan_id'], 'foodalchemist_concept_slots' => ['foodalchemist_concepts', 'concept_id']] as $tab => [$parent, $fk]) {
            $ids = DB::table("{$tab} AS x")->join("{$parent} AS p", 'p.id', '=', "x.{$fk}")
                ->where('x.sales_recipe_id', $alt)->whereIn('x.team_id', $teams)->whereNull('x.deleted_at')->whereNull('p.deleted_at')
                ->where(fn ($w) => $w->whereNull('p.status')->orWhere('p.status', '!=', 'archiviert'))->pluck('x.id')->all();
            $n += DB::table($tab)->whereIn('id', $ids ?: [0])->update(['sales_recipe_id' => $neu]);
        }
        $n += DB::table('foodalchemist_recipe_ingredients AS z')
            ->whereIn('z.recipe_id', DB::table('foodalchemist_recipes')->whereIn('team_id', $teams)->where('ist_fassung', false)->whereNull('deleted_at')->select('id'))
            ->where('z.referenced_recipe_id', $alt)->whereNull('z.deleted_at')->update(['referenced_recipe_id' => $neu]);

        return $n;
    }

    /** Kopien mit den Preisen des Mandanten neu rechnen (Kinder vor Eltern), Darreichungen danach. @param array<int,int> $map */
    private function nachrechnen(array $map): void
    {
        if ($map === []) {
            return;
        }
        app(RecipeRecomputeService::class)->recomputeMany(array_values($map));
        foreach (FoodAlchemistRecipeDarreichung::whereIn('recipe_id', array_values($map))->get() as $d) {
            app(DarreichungService::class)->recomputePreise($d);
        }
    }

    private function freierKey(Team $mandant, string $fassungKey): string
    {
        $basis = preg_replace('/@f\d+$/', '', $fassungKey) ?: 'rezept';
        $key = $basis;
        for ($n = 2; FoodAlchemistRecipe::mitFassungen()->withTrashed()->where('team_id', $mandant->id)->where('recipe_key', $key)->exists(); $n++) {
            $key = "{$basis}_{$n}";
        }

        return $key;
    }

    private function pruefeRecht(Team $handelnd, FoodAlchemistAbo $abo, ?User $actor): void
    {
        if ((int) $handelnd->id === (int) $abo->master_team_id) {
            $this->rechte->pruefe($actor, $handelnd, FaRolle::Freigeben, 'Abo kündigen');

            return;
        }
        if ((int) $this->rechte->kundenHauptTeam($handelnd)->id === (int) $abo->mandant_team_id && (int) $handelnd->id === (int) $abo->mandant_team_id) {
            $this->rechte->pruefe($actor, $handelnd, FaRolle::Admin, 'Abo kündigen');

            return;
        }
        throw new \RuntimeException('Kündigen darf nur das Master-Team oder das Oberteam des Mandanten.');
    }
}
