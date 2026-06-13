<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;

/**
 * M14 / Doc 15 §M14: Speiseplan — Belegung von Zeit-Slots (Woche × Wochentag ×
 * Mahlzeit) mit Concept/Paket/Gericht (D-PLAN-1: beides). Wiederholungs-Check
 * (Mindestabstand) + Kosten pro Tag/Woche (HK1/HK2-Brücke). Scope-Härte + Owner-Guard.
 */
class SpeiseplanService
{
    public function __construct(private ConceptService $concepts)
    {
    }

    public const MAHLZEITEN = ['fruehstueck' => 'Frühstück', 'mittag' => 'Mittag', 'abend' => 'Abend', 'snack' => 'Snack'];

    public const WOCHENTAGE = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistSpeiseplan::visibleToTeam($team)
            ->withCount('eintraege')
            ->when(($filters['search'] ?? '') !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($filters['search']) . '%']))
            ->orderBy('name')->paginate($perPage);
    }

    public function detail(Team $team, int $id): ?FoodAlchemistSpeiseplan
    {
        return FoodAlchemistSpeiseplan::visibleToTeam($team)
            ->with(['eintraege.concept:id,name,preis_pro_person_cache',
                'eintraege.paket:id,name,preis_pro_person,ek_pro_person',
                'eintraege.gericht:id,name,vk_netto,ek_total_eur'])
            ->find($id);
    }

    private const FELDER = ['name', 'start_datum', 'zyklus_wochen', 'min_abstand_tage', 'status', 'beschreibung', 'note'];

    public function create(Team $team, array $in): FoodAlchemistSpeiseplan
    {
        return FoodAlchemistSpeiseplan::create([
            'team_id' => $team->id,
            'name' => trim((string) ($in['name'] ?? 'Neuer Speiseplan')) ?: 'Neuer Speiseplan',
            'zyklus_wochen' => max(1, (int) ($in['zyklus_wochen'] ?? 1)),
            'min_abstand_tage' => max(0, (int) ($in['min_abstand_tage'] ?? 0)),
            'status' => $in['status'] ?? 'draft',
        ]);
    }

    public function update(Team $team, int $id, array $in): FoodAlchemistSpeiseplan
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($id);
        $this->guard($plan, $team);
        $update = array_intersect_key($in, array_flip(self::FELDER));
        foreach (['zyklus_wochen' => 1, 'min_abstand_tage' => 0] as $f => $min) {
            if (array_key_exists($f, $update)) {
                $update[$f] = max($min, (int) $update[$f]);
            }
        }
        $plan->update($update);

        return $plan->refresh();
    }

    public function delete(Team $team, int $id): void
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($id);
        $this->guard($plan, $team);
        $plan->delete();
    }

    /** Eintrag in eine Zelle (woche×wochentag×mahlzeit) — genau EIN Inhalt. */
    public function addEintrag(Team $team, int $planId, array $in): FoodAlchemistSpeiseplanEintrag
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);
        $woche = max(1, min((int) $plan->zyklus_wochen, (int) ($in['woche'] ?? 1)));
        $wochentag = max(1, min(7, (int) ($in['wochentag'] ?? 1)));
        $mahlzeit = in_array($in['mahlzeit'] ?? '', array_keys(self::MAHLZEITEN), true) ? $in['mahlzeit'] : 'mittag';

        return $plan->eintraege()->create([
            'team_id' => $plan->team_id, 'woche' => $woche, 'wochentag' => $wochentag, 'mahlzeit' => $mahlzeit,
            'concept_id' => $in['concept_id'] ?? null,
            'paket_id' => empty($in['concept_id']) ? ($in['paket_id'] ?? null) : null,
            'vk_recipe_id' => empty($in['concept_id']) && empty($in['paket_id']) ? ($in['vk_recipe_id'] ?? null) : null,
            'position' => (int) $plan->eintraege()
                ->where('woche', $woche)->where('wochentag', $wochentag)->where('mahlzeit', $mahlzeit)->max('position') + 1,
        ]);
    }

    public function removeEintrag(Team $team, int $id): void
    {
        $e = FoodAlchemistSpeiseplanEintrag::visibleToTeam($team)->with('speiseplan')->findOrFail($id);
        $this->guard($e->speiseplan, $team);
        $e->delete();
    }

    /**
     * Raster: [woche][wochentag][mahlzeit] => Collection<Eintrag>.
     *
     * @return array<int, array<int, array<string, \Illuminate\Support\Collection>>>
     */
    public function raster(FoodAlchemistSpeiseplan $plan): array
    {
        $grid = [];
        foreach ($plan->eintraege as $e) {
            $grid[$e->woche][$e->wochentag][$e->mahlzeit][] = $e;
        }

        return $grid;
    }

    /** Per-Person-Preis eines Eintrags (Concept/Paket/Gericht). @return array{vk: float, ek: float} */
    public function eintragPreis(FoodAlchemistSpeiseplanEintrag $e): array
    {
        if ($e->concept_id !== null && $e->concept) {
            $c = $this->concepts->preisCockpit($e->concept);

            return ['vk' => (float) $c['preis_pro_person'], 'ek' => (float) $c['ek_pro_person']];
        }
        if ($e->paket_id !== null && $e->paket) {
            return ['vk' => (float) ($e->paket->preis_pro_person ?? 0), 'ek' => (float) ($e->paket->ek_pro_person ?? 0)];
        }
        if ($e->vk_recipe_id !== null && $e->gericht) {
            return ['vk' => (float) ($e->gericht->vk_netto ?? 0), 'ek' => (float) ($e->gericht->ek_total_eur ?? 0)];
        }

        return ['vk' => 0.0, 'ek' => 0.0];
    }

    /**
     * Kosten pro Person: je Tag (woche×wochentag), je Woche und gesamt (Σ Einträge).
     *
     * @return array{pro_tag: array<int,array<int,array{vk:float,ek:float}>>, pro_woche: array<int,array{vk:float,ek:float}>, gesamt: array{vk:float,ek:float}}
     */
    public function kosten(FoodAlchemistSpeiseplan $plan): array
    {
        $proTag = [];
        $proWoche = [];
        $gVk = 0.0;
        $gEk = 0.0;
        foreach ($plan->eintraege as $e) {
            $p = $this->eintragPreis($e);
            $proTag[$e->woche][$e->wochentag]['vk'] = ($proTag[$e->woche][$e->wochentag]['vk'] ?? 0) + $p['vk'];
            $proTag[$e->woche][$e->wochentag]['ek'] = ($proTag[$e->woche][$e->wochentag]['ek'] ?? 0) + $p['ek'];
            $proWoche[$e->woche]['vk'] = ($proWoche[$e->woche]['vk'] ?? 0) + $p['vk'];
            $proWoche[$e->woche]['ek'] = ($proWoche[$e->woche]['ek'] ?? 0) + $p['ek'];
            $gVk += $p['vk'];
            $gEk += $p['ek'];
        }

        return ['pro_tag' => $proTag, 'pro_woche' => $proWoche, 'gesamt' => ['vk' => round($gVk, 2), 'ek' => round($gEk, 2)]];
    }

    /**
     * Wiederholungs-Check: gleicher Inhalt in zu engem Abstand (< min_abstand_tage).
     * Tag-Index = (woche−1)×7 + wochentag (linear über den Zyklus).
     *
     * @return list<array{key:string, name:string, vorkommen:int, min_abstand:int, konflikt:bool}>
     */
    public function wiederholungen(FoodAlchemistSpeiseplan $plan): array
    {
        $minRegel = (int) $plan->min_abstand_tage;
        $proInhalt = [];
        foreach ($plan->eintraege as $e) {
            $key = $e->inhaltKey();
            if ($key === null) {
                continue;
            }
            $proInhalt[$key]['name'] ??= $e->inhaltName();
            $proInhalt[$key]['tage'][] = ($e->woche - 1) * 7 + $e->wochentag;
        }

        $out = [];
        foreach ($proInhalt as $key => $d) {
            $tage = $d['tage'];
            sort($tage);
            $minGap = PHP_INT_MAX;
            for ($i = 1; $i < count($tage); $i++) {
                $minGap = min($minGap, $tage[$i] - $tage[$i - 1]);
            }
            if (count($tage) < 2) {
                continue;
            }
            $out[] = [
                'key' => $key, 'name' => $d['name'], 'vorkommen' => count($tage),
                'min_abstand' => $minGap,
                'konflikt' => $minRegel > 0 && $minGap < $minRegel,
            ];
        }

        return $out;
    }

    private function guard(FoodAlchemistSpeiseplan $plan, Team $team): void
    {
        if (! $plan->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Speiseplan — Pflege nur durchs Besitzer-Team (D1).');
        }
    }
}
