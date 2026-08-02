<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
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

    /**
     * Spec 31 (GV-Ausbau) — Kostformen, deren Tages-Abdeckung geprüft wird. Alle aus den
     * vorhandenen Diät-Spec-Flags am Rezept ableitbar (keine neuen Daten). „Passiert/püriert"
     * bewusst NICHT dabei — dafür gibt es (noch) kein Datenfeld, also nicht raten.
     */
    public const KOSTFORMEN = [
        'vegetarisch' => 'Vegetarisch',
        'vegan' => 'Vegan',
        'schweinefrei' => 'Schweinefleischfrei',
        'glutenfrei' => 'Glutenfrei',
        'laktosefrei' => 'Laktosefrei',
        'halal' => 'Halal',
    ];

    /** In-Request-Memo: Eintrag-Inhalt (c{id}/p{id}/g{id}) → aufgelöste Gerichte-Sammlung. */
    private array $gerichteCache = [];

    /** Konfidenz-Rang (schwächstes Glied) — lokal, da die Aggregat-Konstante privat ist. */
    private const KONF_RANG = ['unknown' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistSpeiseplan::visibleToTeam($team)
            ->withCount('entries')
            ->when(($filters['search'] ?? '') !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $filters['search']))
            ->orderBy('name')->paginate($perPage);
    }

    public function detail(Team $team, int $id): ?FoodAlchemistSpeiseplan
    {
        return FoodAlchemistSpeiseplan::visibleToTeam($team)
            ->with(['linien',
                'entries.concept:id,name,price_per_person_cache',
                'entries.package:id,name,price_per_person,ek_per_person',
                'entries.dish:id,name,sales_net,ek_total_eur',
                'entries.line:id,name,color,is_vegetarian'])
            ->find($id);
    }

    private const FELDER = ['name', 'start_date', 'cycle_weeks', 'min_abstand_tage', 'status', 'description', 'note', 'default_pax', 'budget_wareneinsatz'];

    public function create(Team $team, array $in): FoodAlchemistSpeiseplan
    {
        $plan = FoodAlchemistSpeiseplan::create([
            'team_id' => $team->id,
            'name' => trim((string) ($in['name'] ?? 'Neuer Speiseplan')) ?: 'Neuer Speiseplan',
            'start_date' => $in['start_date'] ?? Carbon::now()->startOfWeek()->format('Y-m-d'),
            'cycle_weeks' => max(1, (int) ($in['cycle_weeks'] ?? 4)),
            'min_abstand_tage' => max(0, (int) ($in['min_abstand_tage'] ?? 0)),
            'status' => $in['status'] ?? 'draft',
        ]);

        // Starter-Linien (Kantinen-Standard) — pro Plan frei änderbar
        foreach ([['Menü 1', '#D85A30', false], ['Vegetarisch', '#639922', true], ['Dessert', '#EF9F27', false]] as $i => [$n, $f, $v]) {
            $plan->lines()->create(['team_id' => $team->id, 'name' => $n, 'color' => $f, 'is_vegetarian' => $v, 'sort_order' => $i + 1]);
        }

        return $plan;
    }

    public function update(Team $team, int $id, array $in): FoodAlchemistSpeiseplan
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($id);
        $this->guard($plan, $team);
        $update = array_intersect_key($in, array_flip(self::FELDER));
        foreach (['cycle_weeks' => 1, 'min_abstand_tage' => 0, 'default_pax' => 1] as $f => $min) {
            if (array_key_exists($f, $update)) {
                $update[$f] = max($min, (int) $update[$f]);
            }
        }
        if (array_key_exists('budget_wareneinsatz', $update)) {
            $update['budget_wareneinsatz'] = ($update['budget_wareneinsatz'] === '' || $update['budget_wareneinsatz'] === null)
                ? null : max(0, (float) str_replace(',', '.', (string) $update['budget_wareneinsatz']));
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

        return $plan->lines()->create([
            'team_id' => $plan->team_id,
            'name' => trim((string) ($in['name'] ?? 'Neue Linie')) ?: 'Neue Linie',
            'color' => $in['color'] ?? null,
            'is_vegetarian' => (bool) ($in['is_vegetarian'] ?? false),
            'sort_order' => (int) $plan->lines()->max('sort_order') + 1,
        ]);
    }

    public function updateLinie(Team $team, int $linieId, array $in): FoodAlchemistSpeiseplanLinie
    {
        $linie = FoodAlchemistSpeiseplanLinie::visibleToTeam($team)->with('mealPlan')->findOrFail($linieId);
        $this->guard($linie->mealPlan, $team);
        $upd = array_intersect_key($in, array_flip(['name', 'color', 'is_vegetarian']));
        if (isset($upd['name'])) {
            $upd['name'] = trim((string) $upd['name']) ?: $linie->name;
        }
        if (array_key_exists('is_vegetarian', $upd)) {
            $upd['is_vegetarian'] = (bool) $upd['is_vegetarian'];
        }
        $linie->update($upd);

        return $linie->refresh();
    }

    public function removeLinie(Team $team, int $linieId): void
    {
        $linie = FoodAlchemistSpeiseplanLinie::visibleToTeam($team)->with('mealPlan')->findOrFail($linieId);
        $this->guard($linie->mealPlan, $team);
        // FK app-seitig: Einträge der Linie entkoppeln statt löschen
        FoodAlchemistSpeiseplanEintrag::where('line_id', $linie->id)->update(['line_id' => null]);
        $linie->delete();
    }

    /** Linie um eine Position verschieben ($richtung < 0 = hoch, sonst runter). */
    public function reorderLinie(Team $team, int $linieId, int $richtung): void
    {
        $linie = FoodAlchemistSpeiseplanLinie::visibleToTeam($team)->with('mealPlan')->findOrFail($linieId);
        $this->guard($linie->mealPlan, $team);
        $nachbar = FoodAlchemistSpeiseplanLinie::where('menu_plan_id', $linie->menu_plan_id)->whereNull('deleted_at')
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
        $datum = Carbon::parse($in['entry_date'])->startOfDay();
        $mahlzeit = in_array($in['mahlzeit'] ?? '', array_keys(self::MAHLZEITEN), true) ? $in['mahlzeit'] : 'mittag';
        $linieId = $in['line_id'] ?? null;
        if ($linieId !== null && ! $plan->lines->contains('id', (int) $linieId)) {
            $linieId = null;
        }
        $tag = $datum->format('Y-m-d');

        return $plan->entries()->create([
            'team_id' => $plan->team_id,
            'entry_date' => $tag,
            'week' => 1, 'weekday' => (int) $datum->isoWeekday(),   // Back-Compat-Spalten
            'meal' => $mahlzeit,
            'line_id' => $linieId,
            'concept_id' => $in['concept_id'] ?? null,
            'package_id' => empty($in['concept_id']) ? ($in['package_id'] ?? null) : null,
            'sales_recipe_id' => empty($in['concept_id']) && empty($in['package_id']) ? ($in['sales_recipe_id'] ?? null) : null,
            'position' => (int) $plan->entries()
                ->where('entry_date', $tag)->where('meal', $mahlzeit)
                ->when($linieId !== null, fn ($q) => $q->where('line_id', $linieId))->max('position') + 1,
        ]);
    }

    public function removeEintrag(Team $team, int $id): void
    {
        $e = FoodAlchemistSpeiseplanEintrag::visibleToTeam($team)->with('mealPlan')->findOrFail($id);
        $this->guard($e->mealPlan, $team);
        $e->delete();
    }

    /** Spec 31 / Stufe C: Pax-Override je Eintrag setzen (leer/0 → NULL = Plan-Default gilt). */
    public function setEintragPax(Team $team, int $id, $pax): void
    {
        $e = FoodAlchemistSpeiseplanEintrag::visibleToTeam($team)->with('mealPlan')->findOrFail($id);
        $this->guard($e->mealPlan, $team);
        $wert = (int) $pax;
        $e->update(['pax' => $wert > 0 ? $wert : null]);
    }

    // ── Wochen-Matrix + Monats-Kalender ──────────────────────────────────

    /**
     * Wochen-Matrix einer Mahlzeit: [line_id][Y-m-d] => list<Eintrag> (Mo..So ab $montag).
     * Einträge ohne Linie laufen unter Key 0 (»Ohne Linie«).
     *
     * @return array<int, array<string, list<FoodAlchemistSpeiseplanEintrag>>>
     */
    public function wochenRaster(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag): array
    {
        $start = $montag->copy()->startOfDay();
        $ende = $start->copy()->addDays(6);
        $grid = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->between($start, $ende)) {
                continue;
            }
            $grid[(int) $e->line_id][$e->entry_date->format('Y-m-d')][] = $e;
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
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || (int) $e->entry_date->year !== $jahr || (int) $e->entry_date->month !== $monat) {
                continue;
            }
            if ($mahlzeit !== null && $e->meal !== $mahlzeit) {
                continue;
            }
            $key = $e->entry_date->format('Y-m-d');
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

            return ['vk' => (float) $c['price_per_person'], 'ek' => (float) $c['ek_per_person']];
        }
        if ($e->package_id !== null && $e->package) {
            return ['vk' => (float) ($e->package->price_per_person ?? 0), 'ek' => (float) ($e->package->ek_per_person ?? 0)];
        }
        if ($e->sales_recipe_id !== null && $e->dish) {
            return ['vk' => (float) ($e->dish->sales_net ?? 0), 'ek' => (float) ($e->dish->ek_total_eur ?? 0)];
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
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->between($start, $ende)) {
                continue;
            }
            $p = $this->eintragPreis($e);
            $k = $e->entry_date->format('Y-m-d');
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
        $veggie = $plan->lines->where('is_vegetarian', true)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if ($veggie === []) {
            return ['active' => false, 'erfuellt' => false, 'fehltage' => []];
        }
        $fehl = [];
        for ($i = 0; $i < $tage; $i++) {
            $tag = $montag->copy()->addDays($i)->startOfDay();
            $hat = $plan->entries->first(fn ($e) => $e->entry_date !== null && $e->meal === $mahlzeit
                && in_array((int) $e->line_id, $veggie, true) && $e->entry_date->isSameDay($tag));
            if ($hat === null) {
                $fehl[] = $tag->format('Y-m-d');
            }
        }

        return ['active' => true, 'erfuellt' => $fehl === [], 'fehltage' => $fehl];
    }

    // ── Spec 31 (GV-Ausbau): Kennzeichnung + Kostformen-Abdeckung ─────────────

    /**
     * Löst einen Speiseplan-Eintrag (Concept/Paket/Gericht) auf seine tatsächlichen Gerichte
     * (Verkaufsrezepte) auf — Basis für Kennzeichnungs- und Diät-Rollups. Gleiche Sammel-Logik
     * wie {@see ConceptService::allergenRollup} (slots→package→dishes + slot→dish). In-Request
     * memoisiert, damit derselbe Inhalt in einer Woche nicht mehrfach geladen wird.
     *
     * @return Collection<int, FoodAlchemistRecipe>
     */
    /**
     * Anzeigename eines Eintrags fürs Kunden-/Aushang-Dokument: Gericht über die Wording-Kette
     * (saubere Kunden-Namen, ohne interne [HG]/[KAE]-Marker), Paket/Concept behalten ihren Namen.
     */
    public function eintragName(FoodAlchemistSpeiseplanEintrag $e): string
    {
        if ($e->sales_recipe_id !== null) {
            $dish = $e->relationLoaded('dish') ? $e->dish : $e->dish()->first();
            if ($dish !== null) {
                return app(WordingResolver::class)->fuerGericht($dish)['text'] ?? $e->inhaltName();
            }
        }

        return $e->inhaltName();
    }

    public function eintragGerichte(FoodAlchemistSpeiseplanEintrag $e): Collection
    {
        $key = $e->inhaltKey();
        if ($key === null) {
            return collect();
        }
        if (isset($this->gerichteCache[$key])) {
            return $this->gerichteCache[$key];
        }

        $gerichte = collect();
        if ($e->sales_recipe_id !== null) {
            $dish = FoodAlchemistRecipe::find($e->sales_recipe_id);
            $gerichte = $dish ? collect([$dish]) : collect();
        } elseif ($e->package_id !== null) {
            $pkg = FoodAlchemistPaket::with('dishes.gericht')->find($e->package_id);
            $gerichte = $pkg ? $pkg->dishes->pluck('gericht')->filter() : collect();
        } elseif ($e->concept_id !== null) {
            $c = FoodAlchemistConcept::with(['slots.package.dishes.gericht', 'slots.dish'])->find($e->concept_id);
            if ($c !== null) {
                foreach ($c->slots as $slot) {
                    if ($slot->package) {
                        $gerichte = $gerichte->merge($slot->package->dishes->pluck('gericht')->filter());
                    }
                    if ($slot->dish) {
                        $gerichte->push($slot->dish);
                    }
                }
            }
        }

        return $this->gerichteCache[$key] = $gerichte->filter()->unique('id')->values();
    }

    /**
     * LMIV-Kennzeichnung (14 Allergene + 18 Zusatzstoffe) je Werktag der sichtbaren Woche +
     * Wochen-Rollup, ALL-MAXIMAL über alle Gerichte des Tages. Für Rail-Übersicht + Aushang.
     *
     * @return array{pro_tag: array<string, array>, woche: array}
     */
    public function wochenKennzeichnung(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, int $tage = 5): array
    {
        $agg = app(ConcepterAggregateService::class);
        $proTag = [];
        $wocheGerichte = collect();
        for ($i = 0; $i < $tage; $i++) {
            $tag = $montag->copy()->addDays($i)->startOfDay();
            $tagGerichte = collect();
            foreach ($plan->entries as $e) {
                if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->isSameDay($tag)) {
                    continue;
                }
                $tagGerichte = $tagGerichte->merge($this->eintragGerichte($e));
            }
            $tagGerichte = $tagGerichte->filter()->unique('id')->values();
            $proTag[$tag->format('Y-m-d')] = $agg->kennzeichnungFromGerichte($tagGerichte);
            $wocheGerichte = $wocheGerichte->merge($tagGerichte);
        }

        return ['pro_tag' => $proTag, 'woche' => $agg->kennzeichnungFromGerichte($wocheGerichte->unique('id')->values())];
    }

    /**
     * Aushang-Daten (Spec 31 / Stufe B): druckbarer Wochen-Speiseplan als Grid Linien × Mo–Fr,
     * je Gericht Allergen-Buchstaben (A…) + Zusatzstoff-Nummern (1…), darunter eine Legende NUR
     * der tatsächlich vorkommenden Kennzeichen (LMIV). Spuren als »Code*« markiert.
     *
     * @return array{plan:FoodAlchemistSpeiseplan, mahlzeitLabel:string, kwLabel:string,
     *               tage:list<array{ymd:string,label:string}>,
     *               zeilen:list<array{linie:?string,color:?string,zellen:array<string,list<array{name:string,codes:list<string>}>>}>,
     *               legende:array{allergene:list<array{code:string,label:string}>, zusatzstoffe:list<array{code:string,label:string}>},
     *               kostformen:list, erzeugt:string}
     */
    public function dokumentDaten(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit = 'mittag', ?string $montag = null): array
    {
        $mahlzeit = array_key_exists($mahlzeit, self::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $mo = ($montag !== null ? Carbon::parse($montag) : ($plan->start_date ?? Carbon::now()))->startOfWeek(Carbon::MONDAY);

        // Codes: Allergene = Buchstaben in EU-Reihenfolge, Zusatzstoffe = Nummern.
        $allergenCode = [];
        $i = 0;
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $slug => $label) {
            $allergenCode[$slug] = ['code' => chr(65 + $i), 'label' => $label];
            $i++;
        }
        $zusatzCode = [];
        $j = 1;
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE as $slug => $label) {
            $zusatzCode[$slug] = ['code' => (string) $j, 'label' => $label];
            $j++;
        }

        $agg = app(ConcepterAggregateService::class);
        $tage = [];
        $raster = $this->wochenRaster($plan, $mahlzeit, $mo);       // [line_id][Ymd] => [entries]
        $usedAlg = [];
        $usedZus = [];

        // Zellen-Inhalt je Eintrag → Name + Codes; sammelt nebenbei die Legende.
        $codesFuer = function (FoodAlchemistSpeiseplanEintrag $e) use ($agg, $allergenCode, $zusatzCode, &$usedAlg, &$usedZus): array {
            $k = $agg->kennzeichnungFromGerichte($this->eintragGerichte($e));
            $codes = [];
            foreach ($k['allergene'] as $a) {
                if ($a['status'] === 'enthalten' || $a['status'] === 'spuren') {
                    $usedAlg[$a['slug']] = true;
                    $codes[] = $allergenCode[$a['slug']]['code'] . ($a['status'] === 'spuren' ? '*' : '');
                }
            }
            foreach ($k['zusatzstoffe'] as $z) {
                if ($z['status'] === 'ja') {
                    $usedZus[$z['slug']] = true;
                    $codes[] = $zusatzCode[$z['slug']]['code'];
                }
            }

            return ['name' => $this->eintragName($e), 'codes' => $codes];
        };

        $for = fn (Carbon $d) => $d->format('Y-m-d');
        for ($d = 0; $d < 5; $d++) {
            $tag = $mo->copy()->addDays($d);
            $tage[] = ['ymd' => $for($tag), 'label' => self::WOCHENTAGE[$tag->isoWeekday()] . ' ' . $tag->format('d.m.')];
        }

        // Zeilen = Menü-Linien (+ »Ohne Linie«, falls belegt).
        $linienListe = $plan->lines->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name, 'color' => $l->color])->values()->all();
        if (isset($raster[0])) {
            $linienListe[] = ['id' => 0, 'name' => 'Ohne Linie', 'color' => null];
        }

        $zeilen = [];
        foreach ($linienListe as $lin) {
            $zellen = [];
            foreach ($tage as $t) {
                $eintraege = $raster[$lin['id']][$t['ymd']] ?? [];
                $zellen[$t['ymd']] = array_map($codesFuer, $eintraege);
            }
            $zeilen[] = ['linie' => $lin['name'], 'color' => $lin['color'], 'zellen' => $zellen];
        }

        $legendeAlg = [];
        foreach ($allergenCode as $slug => $cl) {
            if (isset($usedAlg[$slug])) {
                $legendeAlg[] = $cl;
            }
        }
        $legendeZus = [];
        foreach ($zusatzCode as $slug => $cl) {
            if (isset($usedZus[$slug])) {
                $legendeZus[] = $cl;
            }
        }

        return [
            'plan' => $plan,
            'mahlzeitLabel' => self::MAHLZEITEN[$mahlzeit],
            'kwLabel' => 'KW ' . $mo->isoWeek() . ' · ' . $mo->format('d.m.') . '–' . $mo->copy()->addDays(4)->format('d.m.Y'),
            'tage' => $tage,
            'zeilen' => $zeilen,
            'legende' => ['allergene' => $legendeAlg, 'zusatzstoffe' => $legendeZus],
            'kostformen' => $this->kostformAbdeckung($plan, $mahlzeit, $mo),
            'naehrwerte' => $this->wochenNaehrwerte($plan, $mahlzeit, $mo),
            'erzeugt' => Carbon::now()->format('d.m.Y'),
        ];
    }

    /**
     * Spec 31 / Stufe C: Wochen-Speiseplan an die Produktion übergeben. Erzeugt je Werktag MIT
     * Belegung EINEN Produktionsauftrag (GV kocht tagesweise); jeder Eintrag wird zu einem Ziel
     * (Concept → persons, VK-Gericht → portions, Paket → seine Gerichte je portions), Menge =
     * effektive Pax (Eintrag-Override ?? Plan-Default). Zielform gespiegelt aus Produktion\Editor.
     *
     * @return array{auftraege:int, ziele:int, tage:list<string>}
     */
    public function wocheAnProduktion(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?int $userId = null): array
    {
        $this->guard($plan, $team);
        $mahlzeit = array_key_exists($mahlzeit, self::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $produktion = app(ProductionOrderService::class);
        $raster = $this->wochenRaster($plan, $mahlzeit, $montag);
        $defaultPax = max(1, (int) ($plan->default_pax ?: 100));

        $auftraege = 0;
        $zieleGesamt = 0;
        $tage = [];
        for ($d = 0; $d < 5; $d++) {
            $tag = $montag->copy()->addDays($d)->startOfDay();
            $ymd = $tag->format('Y-m-d');
            $eintraege = collect($raster)->flatMap(fn ($proLinie) => $proLinie[$ymd] ?? []);
            if ($eintraege->isEmpty()) {
                continue;
            }

            $targets = [];
            foreach ($eintraege as $e) {
                $pax = (int) ($e->pax ?: $defaultPax);
                $ref = 'menuplan:' . $plan->id . ':' . $ymd . ':' . $e->id;
                if ($e->concept_id !== null) {
                    $targets[] = ['concept_id' => (int) $e->concept_id, 'persons' => $pax, 'source_ref' => $ref];
                } elseif ($e->sales_recipe_id !== null) {
                    $targets[] = ['recipe_id' => (int) $e->sales_recipe_id, 'portions' => $pax, 'source_ref' => $ref];
                } elseif ($e->package_id !== null) {
                    foreach ($this->eintragGerichte($e) as $g) {
                        $targets[] = ['recipe_id' => (int) $g->id, 'portions' => $pax, 'source_ref' => $ref . ':d' . $g->id];
                    }
                }
            }
            if ($targets === []) {
                continue;
            }

            $name = $plan->name . ' · ' . self::WOCHENTAGE[$tag->isoWeekday()] . ' ' . $tag->format('d.m.') . ' (' . self::MAHLZEITEN[$mahlzeit] . ')';
            $produktion->saveNew($team, $ymd, $name, $targets, 'Speiseplan #' . $plan->id, null, $userId);
            $auftraege++;
            $zieleGesamt += count($targets);
            $tage[] = $ymd;
        }

        return ['auftraege' => $auftraege, 'ziele' => $zieleGesamt, 'tage' => $tage];
    }

    // ── Spec 31 / Stufe D: DGE-Nährwertbilanz + Abwechslung ──────────────────

    /**
     * Nährwert-Wochenbilanz — Ø je Person und Werktag (kcal/Eiweiß/Fett/ges.Fett/Salz/Zucker/KH).
     * Reuse {@see ConcepterAggregateService::naehrwertAggregat}: je Gericht 1 Portion/Person
     * (GV-Modell „eine Komponente = eine Portion"). Gemittelt über Werktage MIT Nährwertdaten.
     *
     * @return array{schnitt: array<string,?float>, tage_mit_daten:int, confidence:string}
     */
    public function wochenNaehrwerte(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, int $tage = 5): array
    {
        $agg = app(ConcepterAggregateService::class);
        $felder = ['kcal', 'protein_g', 'fett_g', 'gesfett_g', 'salz_g', 'zucker_g', 'kh_g'];
        $summe = array_fill_keys($felder, 0.0);
        $nTage = 0;
        $konfRang = null;

        for ($i = 0; $i < $tage; $i++) {
            $tag = $montag->copy()->addDays($i)->startOfDay();
            $rows = collect();
            foreach ($plan->entries as $e) {
                if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->isSameDay($tag)) {
                    continue;
                }
                foreach ($this->eintragGerichte($e) as $g) {
                    $rows->push(['gericht' => $g, 'quantity' => 1, 'unit' => null]);
                }
            }
            if ($rows->isEmpty()) {
                continue;
            }
            $n = $agg->naehrwertAggregat($rows);
            if (($n['n_mit_naehrwerten'] ?? 0) === 0) {
                continue;
            }
            $nTage++;
            foreach ($felder as $f) {
                $summe[$f] += (float) ($n[$f] ?? 0);
            }
            $rang = self::KONF_RANG[$n['confidence']] ?? 0;
            $konfRang = $konfRang === null ? $rang : min($konfRang, $rang);
        }

        $schnitt = array_fill_keys($felder, null);
        if ($nTage > 0) {
            foreach ($felder as $f) {
                $roh = $summe[$f] / $nTage;
                $schnitt[$f] = $f === 'kcal' ? round($roh) : ($f === 'salz_g' ? round($roh, 2) : round($roh, 1));
            }
        }

        return [
            'schnitt' => $schnitt,
            'tage_mit_daten' => $nTage,
            'confidence' => $nTage === 0 ? 'unknown' : (array_search($konfRang ?? 0, self::KONF_RANG, true) ?: 'unknown'),
        ];
    }

    /**
     * Abwechslung/Häufigkeit der Woche: Diät-Mix (vegan/vegetarisch/mit Fleisch·Fisch) je serviertem
     * Gericht + Warengruppen-Häufigkeit (dish_main_group). Weicher Hinweis, wenn eine Warengruppe die
     * Woche dominiert (≥ $tage Vorkommen). Alles aus vorhandenen Feldern (spec_*, dish_main_group_id).
     *
     * @return array{diaet: array{vegan:int, vegetarisch:int, omnivor:int}, warengruppen: list<array{name:string,count:int}>, hinweis: ?string}
     */
    public function wochenAbwechslung(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, int $tage = 5): array
    {
        $vegan = 0;
        $veg = 0;
        $omni = 0;
        $wg = [];   // dish_main_group_id => count
        for ($i = 0; $i < $tage; $i++) {
            $tag = $montag->copy()->addDays($i)->startOfDay();
            foreach ($plan->entries as $e) {
                if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->isSameDay($tag)) {
                    continue;
                }
                foreach ($this->eintragGerichte($e) as $g) {
                    if ((bool) $g->spec_is_vegan) {
                        $vegan++;
                    } elseif ((bool) $g->spec_is_vegetarian) {
                        $veg++;
                    } else {
                        $omni++;
                    }
                    $gid = $g->dish_main_group_id;
                    if ($gid !== null) {
                        $wg[(int) $gid] = ($wg[(int) $gid] ?? 0) + 1;
                    }
                }
            }
        }

        // Warengruppen-Namen in EINER Query auflösen.
        $namen = [];
        if ($wg !== []) {
            $namen = \Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup::whereIn('id', array_keys($wg))
                ->pluck('label', 'id')->all();
        }
        arsort($wg);
        $warengruppen = [];
        $dominant = null;
        foreach ($wg as $gid => $count) {
            $name = $namen[$gid] ?? ('#' . $gid);
            $warengruppen[] = ['name' => $name, 'count' => $count];
            if ($dominant === null && $count >= $tage) {
                $dominant = $name;
            }
        }

        return [
            'diaet' => ['vegan' => $vegan, 'vegetarisch' => $veg, 'omnivor' => $omni],
            'warengruppen' => array_slice($warengruppen, 0, 6),
            'hinweis' => $dominant !== null ? 'Warengruppe „' . $dominant . '" dominiert die Woche — mehr Abwechslung erwägen.' : null,
        ];
    }

    /**
     * Kostformen-Abdeckung: hat jeder Werktag in der gewählten Mahlzeit mindestens EINEN Eintrag,
     * der die jeweilige Kostform erfüllt? Verallgemeinert den linien-basierten {@see veggieCheck}
     * auf die tatsächliche Rezept-Diät (Diät-Flag-Rollup je Eintrag, ein Eintrag genügt/Tag).
     *
     * @return list<array{key:string, label:string, erfuellt:bool, fehltage:list<string>, abgedeckt:int, tage:int}>
     */
    public function kostformAbdeckung(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, int $tage = 5): array
    {
        $agg = app(ConcepterAggregateService::class);
        $fehl = array_fill_keys(array_keys(self::KOSTFORMEN), []);

        for ($i = 0; $i < $tage; $i++) {
            $tag = $montag->copy()->addDays($i)->startOfDay();
            $tagErfuellt = array_fill_keys(array_keys(self::KOSTFORMEN), false);
            foreach ($plan->entries as $e) {
                if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->isSameDay($tag)) {
                    continue;
                }
                $roll = $agg->allergenRollupFromGerichte($this->eintragGerichte($e));
                foreach (self::KOSTFORMEN as $k => $_) {
                    if (! $tagErfuellt[$k] && $this->kostformErfuellt($k, $roll)) {
                        $tagErfuellt[$k] = true;
                    }
                }
            }
            foreach (self::KOSTFORMEN as $k => $_) {
                if (! $tagErfuellt[$k]) {
                    $fehl[$k][] = $tag->format('Y-m-d');
                }
            }
        }

        $out = [];
        foreach (self::KOSTFORMEN as $k => $label) {
            $out[] = [
                'key' => $k, 'label' => $label,
                'erfuellt' => $fehl[$k] === [], 'fehltage' => $fehl[$k],
                'abgedeckt' => $tage - count($fehl[$k]), 'tage' => $tage,
            ];
        }

        return $out;
    }

    /** Erfüllt der Diät-Flag-Rollup eines Eintrags die Kostform? (leerer Eintrag erfüllt nichts) */
    private function kostformErfuellt(string $key, array $roll): bool
    {
        if (($roll['n_gerichte'] ?? 0) === 0) {
            return false;
        }

        return match ($key) {
            'vegetarisch' => (bool) $roll['is_vegetarian'],
            'vegan' => (bool) $roll['is_vegan'],
            'schweinefrei' => ! $roll['contains_pork'],
            'glutenfrei' => (bool) $roll['is_gluten_free'],
            'laktosefrei' => (bool) $roll['is_lactose_free'],
            'halal' => (bool) $roll['is_halal'],
            default => false,
        };
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
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null) {
                continue;
            }
            $key = $e->inhaltKey();
            if ($key === null) {
                continue;
            }
            $proInhalt[$key]['name'] ??= $e->inhaltName();
            $proInhalt[$key]['tage'][] = $e->entry_date->copy()->startOfDay()->getTimestamp();
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
     * Zyklus-Vorlage ausrollen: den Block [start_date, +cycle_weeks Wochen) auf alle
     * folgenden Zyklen bis $bisDatum kopieren. Dedupe je (Datum|Mahlzeit|Linie|Inhalt).
     *
     * @return int Anzahl neu erzeugter Einträge
     */
    public function vorlageAusrollen(Team $team, int $planId, string $bisDatum): int
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);
        if ($plan->start_date === null) {
            return 0;
        }
        $start = $plan->start_date->copy()->startOfDay();
        $bis = Carbon::parse($bisDatum)->startOfDay();
        $blockTage = max(1, (int) $plan->cycle_weeks) * 7;
        $blockEnde = $start->copy()->addDays($blockTage - 1);

        $basis = $plan->entries->filter(fn ($e) => $e->entry_date !== null && $e->entry_date->between($start, $blockEnde));
        if ($basis->isEmpty()) {
            return 0;
        }

        $vorhanden = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date !== null) {
                $vorhanden[$e->entry_date->format('Y-m-d') . '|' . $e->meal . '|' . (int) $e->line_id . '|' . $e->inhaltKey()] = true;
            }
        }

        $neu = 0;
        for ($k = 1; $k <= 520; $k++) {           // Sicherheitsdeckel ~10 Jahre
            $offset = $k * $blockTage;
            if ($start->copy()->addDays($offset)->gt($bis)) {
                break;
            }
            foreach ($basis as $e) {
                $ziel = $e->entry_date->copy()->addDays($offset);
                if ($ziel->gt($bis)) {
                    continue;
                }
                $sig = $ziel->format('Y-m-d') . '|' . $e->meal . '|' . (int) $e->line_id . '|' . $e->inhaltKey();
                if (isset($vorhanden[$sig])) {
                    continue;
                }
                $plan->entries()->create([
                    'team_id' => $plan->team_id, 'entry_date' => $ziel->format('Y-m-d'),
                    'week' => 1, 'weekday' => (int) $ziel->isoWeekday(), 'meal' => $e->meal,
                    'line_id' => $e->line_id, 'concept_id' => $e->concept_id, 'package_id' => $e->package_id,
                    'sales_recipe_id' => $e->sales_recipe_id, 'position' => $e->position,
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
