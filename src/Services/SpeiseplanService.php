<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie;

/**
 * M14 / Speiseplan v2 — Kantinen-/Kita-Logik: Menü-Linien × ECHTE Wochentage ×
 * Mahlzeit, belegt mit Concept/Paket/Gericht (D-PLAN-1). Wochen-Matrix + Monats-
 * Kalender, Kosten je Tag/Woche, Wiederholungs-Check in echten Tagen, Veggie-
 * Tagescheck, Zyklus-Vorlage ausrollen. Scope-Härte + Owner-Guard.
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
            ->with(['linien',
                'eintraege.concept:id,name,preis_pro_person_cache',
                'eintraege.paket:id,name,preis_pro_person,ek_pro_person',
                'eintraege.gericht:id,name,vk_netto,ek_total_eur',
                'eintraege.linie:id,name,farbe,ist_vegetarisch'])
            ->find($id);
    }

    private const FELDER = ['name', 'start_datum', 'zyklus_wochen', 'min_abstand_tage', 'status', 'beschreibung', 'note'];

    public function create(Team $team, array $in): FoodAlchemistSpeiseplan
    {
        $plan = FoodAlchemistSpeiseplan::create([
            'team_id' => $team->id,
            'name' => trim((string) ($in['name'] ?? 'Neuer Speiseplan')) ?: 'Neuer Speiseplan',
            'start_datum' => $in['start_datum'] ?? Carbon::now()->startOfWeek()->format('Y-m-d'),
            'zyklus_wochen' => max(1, (int) ($in['zyklus_wochen'] ?? 4)),
            'min_abstand_tage' => max(0, (int) ($in['min_abstand_tage'] ?? 0)),
            'status' => $in['status'] ?? 'draft',
        ]);

        // Starter-Linien (Kantinen-Standard) — pro Plan frei änderbar
        foreach ([['Menü 1', '#D85A30', false], ['Vegetarisch', '#639922', true], ['Dessert', '#EF9F27', false]] as $i => [$n, $f, $v]) {
            $plan->linien()->create(['team_id' => $team->id, 'name' => $n, 'farbe' => $f, 'ist_vegetarisch' => $v, 'sort_order' => $i + 1]);
        }

        return $plan;
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

    // ── Menü-Linien (pro Speiseplan frei) ────────────────────────────────

    public function addLinie(Team $team, int $planId, array $in): FoodAlchemistSpeiseplanLinie
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);

        return $plan->linien()->create([
            'team_id' => $plan->team_id,
            'name' => trim((string) ($in['name'] ?? 'Neue Linie')) ?: 'Neue Linie',
            'farbe' => $in['farbe'] ?? null,
            'ist_vegetarisch' => (bool) ($in['ist_vegetarisch'] ?? false),
            'sort_order' => (int) $plan->linien()->max('sort_order') + 1,
        ]);
    }

    public function updateLinie(Team $team, int $linieId, array $in): FoodAlchemistSpeiseplanLinie
    {
        $linie = FoodAlchemistSpeiseplanLinie::visibleToTeam($team)->with('speiseplan')->findOrFail($linieId);
        $this->guard($linie->speiseplan, $team);
        $upd = array_intersect_key($in, array_flip(['name', 'farbe', 'ist_vegetarisch']));
        if (isset($upd['name'])) {
            $upd['name'] = trim((string) $upd['name']) ?: $linie->name;
        }
        if (array_key_exists('ist_vegetarisch', $upd)) {
            $upd['ist_vegetarisch'] = (bool) $upd['ist_vegetarisch'];
        }
        $linie->update($upd);

        return $linie->refresh();
    }

    public function removeLinie(Team $team, int $linieId): void
    {
        $linie = FoodAlchemistSpeiseplanLinie::visibleToTeam($team)->with('speiseplan')->findOrFail($linieId);
        $this->guard($linie->speiseplan, $team);
        // FK app-seitig: Einträge der Linie entkoppeln statt löschen
        FoodAlchemistSpeiseplanEintrag::where('linie_id', $linie->id)->update(['linie_id' => null]);
        $linie->delete();
    }

    /** Linie um eine Position verschieben ($richtung < 0 = hoch, sonst runter). */
    public function reorderLinie(Team $team, int $linieId, int $richtung): void
    {
        $linie = FoodAlchemistSpeiseplanLinie::visibleToTeam($team)->with('speiseplan')->findOrFail($linieId);
        $this->guard($linie->speiseplan, $team);
        $nachbar = FoodAlchemistSpeiseplanLinie::where('speiseplan_id', $linie->speiseplan_id)->whereNull('deleted_at')
            ->when($richtung < 0,
                fn ($q) => $q->where('sort_order', '<', $linie->sort_order)->orderByDesc('sort_order'),
                fn ($q) => $q->where('sort_order', '>', $linie->sort_order)->orderBy('sort_order'))
            ->first();
        if ($nachbar === null) {
            return;
        }
        [$a, $b] = [$linie->sort_order, $nachbar->sort_order];
        $linie->update(['sort_order' => $b]);
        $nachbar->update(['sort_order' => $a]);
    }

    // ── Einträge (echtes Datum × Linie × Mahlzeit) ───────────────────────

    public function addEintrag(Team $team, int $planId, array $in): FoodAlchemistSpeiseplanEintrag
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);
        $datum = Carbon::parse($in['datum'])->startOfDay();
        $mahlzeit = in_array($in['mahlzeit'] ?? '', array_keys(self::MAHLZEITEN), true) ? $in['mahlzeit'] : 'mittag';
        $linieId = $in['linie_id'] ?? null;
        if ($linieId !== null && ! $plan->linien->contains('id', (int) $linieId)) {
            $linieId = null;
        }
        $tag = $datum->format('Y-m-d');

        return $plan->eintraege()->create([
            'team_id' => $plan->team_id,
            'datum' => $tag,
            'woche' => 1, 'wochentag' => (int) $datum->isoWeekday(),   // Back-Compat-Spalten
            'mahlzeit' => $mahlzeit,
            'linie_id' => $linieId,
            'concept_id' => $in['concept_id'] ?? null,
            'paket_id' => empty($in['concept_id']) ? ($in['paket_id'] ?? null) : null,
            'vk_recipe_id' => empty($in['concept_id']) && empty($in['paket_id']) ? ($in['vk_recipe_id'] ?? null) : null,
            'position' => (int) $plan->eintraege()
                ->where('datum', $tag)->where('mahlzeit', $mahlzeit)
                ->when($linieId !== null, fn ($q) => $q->where('linie_id', $linieId))->max('position') + 1,
        ]);
    }

    public function removeEintrag(Team $team, int $id): void
    {
        $e = FoodAlchemistSpeiseplanEintrag::visibleToTeam($team)->with('speiseplan')->findOrFail($id);
        $this->guard($e->speiseplan, $team);
        $e->delete();
    }

    // ── Wochen-Matrix + Monats-Kalender ──────────────────────────────────

    /**
     * Wochen-Matrix einer Mahlzeit: [linie_id][Y-m-d] => list<Eintrag> (Mo..So ab $montag).
     * Einträge ohne Linie laufen unter Key 0 (»Ohne Linie«).
     *
     * @return array<int, array<string, list<FoodAlchemistSpeiseplanEintrag>>>
     */
    public function wochenRaster(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag): array
    {
        $start = $montag->copy()->startOfDay();
        $ende = $start->copy()->addDays(6);
        $grid = [];
        foreach ($plan->eintraege as $e) {
            if ($e->datum === null || $e->mahlzeit !== $mahlzeit || ! $e->datum->between($start, $ende)) {
                continue;
            }
            $grid[(int) $e->linie_id][$e->datum->format('Y-m-d')][] = $e;
        }

        return $grid;
    }

    /**
     * Monats-Belegung: [Y-m-d] => {count, vk} (optional auf eine Mahlzeit gefiltert).
     *
     * @return array<string, array{count:int, vk:float}>
     */
    public function monatsRaster(FoodAlchemistSpeiseplan $plan, int $jahr, int $monat, ?string $mahlzeit = null): array
    {
        $out = [];
        foreach ($plan->eintraege as $e) {
            if ($e->datum === null || (int) $e->datum->year !== $jahr || (int) $e->datum->month !== $monat) {
                continue;
            }
            if ($mahlzeit !== null && $e->mahlzeit !== $mahlzeit) {
                continue;
            }
            $key = $e->datum->format('Y-m-d');
            $p = $this->eintragPreis($e);
            $out[$key]['count'] = ($out[$key]['count'] ?? 0) + 1;
            $out[$key]['vk'] = round(($out[$key]['vk'] ?? 0) + $p['vk'], 2);
        }

        return $out;
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
     * Kosten/Person der sichtbaren Woche+Mahlzeit: je Tag und Wochensumme.
     *
     * @return array{pro_tag: array<string,array{vk:float,ek:float}>, woche: array{vk:float,ek:float}}
     */
    public function wochenKosten(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag): array
    {
        $start = $montag->copy()->startOfDay();
        $ende = $start->copy()->addDays(6);
        $proTag = [];
        $wVk = 0.0;
        $wEk = 0.0;
        foreach ($plan->eintraege as $e) {
            if ($e->datum === null || $e->mahlzeit !== $mahlzeit || ! $e->datum->between($start, $ende)) {
                continue;
            }
            $p = $this->eintragPreis($e);
            $k = $e->datum->format('Y-m-d');
            $proTag[$k]['vk'] = round(($proTag[$k]['vk'] ?? 0) + $p['vk'], 2);
            $proTag[$k]['ek'] = round(($proTag[$k]['ek'] ?? 0) + $p['ek'], 2);
            $wVk += $p['vk'];
            $wEk += $p['ek'];
        }

        return ['pro_tag' => $proTag, 'woche' => ['vk' => round($wVk, 2), 'ek' => round($wEk, 2)]];
    }

    /**
     * Veggie-Tagescheck: hat jeder der ersten $tage Werktage (ab Montag) in der
     * gewählten Mahlzeit mindestens einen Eintrag auf einer vegetarischen Linie?
     *
     * @return array{aktiv:bool, erfuellt:bool, fehltage:list<string>}
     */
    public function veggieCheck(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, int $tage = 5): array
    {
        $veggie = $plan->linien->where('ist_vegetarisch', true)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if ($veggie === []) {
            return ['aktiv' => false, 'erfuellt' => false, 'fehltage' => []];
        }
        $fehl = [];
        for ($i = 0; $i < $tage; $i++) {
            $tag = $montag->copy()->addDays($i)->startOfDay();
            $hat = $plan->eintraege->first(fn ($e) => $e->datum !== null && $e->mahlzeit === $mahlzeit
                && in_array((int) $e->linie_id, $veggie, true) && $e->datum->isSameDay($tag));
            if ($hat === null) {
                $fehl[] = $tag->format('Y-m-d');
            }
        }

        return ['aktiv' => true, 'erfuellt' => $fehl === [], 'fehltage' => $fehl];
    }

    /**
     * Wiederholungs-Check über ECHTE Tages-Abstände: gleicher Inhalt zu eng beieinander.
     *
     * @return list<array{key:string, name:string, vorkommen:int, min_abstand:int, konflikt:bool}>
     */
    public function wiederholungen(FoodAlchemistSpeiseplan $plan): array
    {
        $minRegel = (int) $plan->min_abstand_tage;
        $proInhalt = [];
        foreach ($plan->eintraege as $e) {
            if ($e->datum === null) {
                continue;
            }
            $key = $e->inhaltKey();
            if ($key === null) {
                continue;
            }
            $proInhalt[$key]['name'] ??= $e->inhaltName();
            $proInhalt[$key]['tage'][] = $e->datum->copy()->startOfDay()->getTimestamp();
        }

        $out = [];
        foreach ($proInhalt as $key => $d) {
            $tage = $d['tage'];
            sort($tage);
            if (count($tage) < 2) {
                continue;
            }
            $minGap = PHP_INT_MAX;
            for ($i = 1; $i < count($tage); $i++) {
                $minGap = min($minGap, (int) round(($tage[$i] - $tage[$i - 1]) / 86400));
            }
            $out[] = [
                'key' => $key, 'name' => $d['name'], 'vorkommen' => count($tage),
                'min_abstand' => $minGap,
                'konflikt' => $minRegel > 0 && $minGap < $minRegel,
            ];
        }

        return $out;
    }

    /**
     * Zyklus-Vorlage ausrollen: den Block [start_datum, +zyklus_wochen Wochen) auf alle
     * folgenden Zyklen bis $bisDatum kopieren. Dedupe je (Datum|Mahlzeit|Linie|Inhalt).
     *
     * @return int Anzahl neu erzeugter Einträge
     */
    public function vorlageAusrollen(Team $team, int $planId, string $bisDatum): int
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);
        if ($plan->start_datum === null) {
            return 0;
        }
        $start = $plan->start_datum->copy()->startOfDay();
        $bis = Carbon::parse($bisDatum)->startOfDay();
        $blockTage = max(1, (int) $plan->zyklus_wochen) * 7;
        $blockEnde = $start->copy()->addDays($blockTage - 1);

        $basis = $plan->eintraege->filter(fn ($e) => $e->datum !== null && $e->datum->between($start, $blockEnde));
        if ($basis->isEmpty()) {
            return 0;
        }

        $vorhanden = [];
        foreach ($plan->eintraege as $e) {
            if ($e->datum !== null) {
                $vorhanden[$e->datum->format('Y-m-d') . '|' . $e->mahlzeit . '|' . (int) $e->linie_id . '|' . $e->inhaltKey()] = true;
            }
        }

        $neu = 0;
        for ($k = 1; $k <= 520; $k++) {           // Sicherheitsdeckel ~10 Jahre
            $offset = $k * $blockTage;
            if ($start->copy()->addDays($offset)->gt($bis)) {
                break;
            }
            foreach ($basis as $e) {
                $ziel = $e->datum->copy()->addDays($offset);
                if ($ziel->gt($bis)) {
                    continue;
                }
                $sig = $ziel->format('Y-m-d') . '|' . $e->mahlzeit . '|' . (int) $e->linie_id . '|' . $e->inhaltKey();
                if (isset($vorhanden[$sig])) {
                    continue;
                }
                $plan->eintraege()->create([
                    'team_id' => $plan->team_id, 'datum' => $ziel->format('Y-m-d'),
                    'woche' => 1, 'wochentag' => (int) $ziel->isoWeekday(), 'mahlzeit' => $e->mahlzeit,
                    'linie_id' => $e->linie_id, 'concept_id' => $e->concept_id, 'paket_id' => $e->paket_id,
                    'vk_recipe_id' => $e->vk_recipe_id, 'position' => $e->position,
                ]);
                $vorhanden[$sig] = true;
                $neu++;
            }
        }

        return $neu;
    }

    private function guard(FoodAlchemistSpeiseplan $plan, Team $team): void
    {
        if (! $plan->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Speiseplan — Pflege nur durchs Besitzer-Team (D1).');
        }
    }
}
