<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;

/**
 * M10-03/04/05 / Doc 15 §M10: Concept = Slot-Gerüst über mehrere Rollen
 * (z. B. „Grill-Buffet"). Jeder Slot ist mit GENAU EINEM gefüllt: Paket
 * (austauschbar) ODER festes Gericht.
 *
 * Preis (M10-04): Concept-Preis = Σ der GESPEICHERTEN Paket-Preise (+ feste
 * Gerichte) — ein Paket-Tausch ändert nur die Differenz, KEIN Kaskaden-
 * Recompute der ganzen GP→Rezept→Gericht-Kette.
 *
 * Vorlage (M10-05, D-CON-7): Vorlage = Kopie-Quelle. „Aus Vorlage starten" forkt
 * das Slot-Gerüst; das Concept lebt danach eigenständig (Vorlage zieht NICHT
 * durch). Paket bleibt dagegen Referenz (Änderung schlägt durch).
 */
class ConceptService
{
    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistConcept::visibleToTeam($team)
            ->withCount('slots')
            ->when(($filters['vorlagen'] ?? false), fn ($q) => $q->vorlagen(), fn ($q) => $q->echte())
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $s = '%' . mb_strtolower($filters['search']) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(anlass, \'\')) LIKE ?', [$s]));
            })
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['category'] ?? null) === 'none', fn ($q) => $q->whereNull('category_id'))
            ->when(is_numeric($filters['category'] ?? null), fn ($q) => $q
                ->whereIn('category_id', $this->descendantIds($team, (int) $filters['category'])))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function detail(Team $team, int $id): ?FoodAlchemistConcept
    {
        return FoodAlchemistConcept::visibleToTeam($team)
            ->with([
                'slots' => fn ($q) => $q->orderBy('position'),
                'slots.paket:id,name,rolle,preis_pro_person,ek_pro_person,wareneinsatz_prozent,preis_modus,preis_stale',
                'slots.gericht:id,name,vk_netto,ek_total_eur',
                'slots.einheit:id,slug,display_de',
                'vorlageQuelle:id,name',
            ])
            ->find($id);
    }

    public function create(Team $team, array $in): FoodAlchemistConcept
    {
        return FoodAlchemistConcept::create([
            'team_id' => $team->id,
            'name' => trim((string) ($in['name'] ?? 'Neues Concept')) ?: 'Neues Concept',
            'anlass' => $in['anlass'] ?? null,
            'niveau' => $in['niveau'] ?? null,
            'status' => $in['status'] ?? 'draft',
            'is_vorlage' => (bool) ($in['is_vorlage'] ?? false),
        ]);
    }

    private const FELDER = ['name', 'anlass', 'niveau', 'category_id', 'status', 'beschreibung', 'note'];

    public function update(Team $team, int $id, array $in): FoodAlchemistConcept
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($concept, $team);
        $update = array_intersect_key($in, array_flip(self::FELDER));
        if (array_key_exists('category_id', $update) && ($update['category_id'] === '' || (int) $update['category_id'] === 0)) {
            $update['category_id'] = null;
        }
        $concept->update($update);

        return $concept->refresh();
    }

    public function delete(Team $team, int $id): void
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($concept, $team);
        $concept->delete();
    }

    // ── Slots ────────────────────────────────────────────────────────────

    public function addSlot(Team $team, int $conceptId, array $in = []): FoodAlchemistConceptSlot
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        $this->guardOwner($concept, $team);

        $slot = $concept->slots()->create([
            'team_id' => $concept->team_id,
            'rolle' => $this->norm($in['rolle'] ?? null),
            'titel' => $this->norm($in['titel'] ?? null),
            'is_pflicht' => (bool) ($in['is_pflicht'] ?? true),
            'position' => (int) ($concept->slots()->max('position') ?? -1) + 1,
        ]);
        $this->refreshCache($concept);

        return $slot;
    }

    public function updateSlot(Team $team, int $slotId, array $in): FoodAlchemistConceptSlot
    {
        $slot = $this->ownedSlot($team, $slotId);
        $slot->update([
            'rolle' => array_key_exists('rolle', $in) ? $this->norm($in['rolle']) : $slot->rolle,
            'titel' => array_key_exists('titel', $in) ? $this->norm($in['titel']) : $slot->titel,
            'is_pflicht' => array_key_exists('is_pflicht', $in) ? (bool) $in['is_pflicht'] : $slot->is_pflicht,
        ]);

        return $slot->refresh();
    }

    /**
     * Befüllt einen Slot mit GENAU EINEM: Paket ODER festem Gericht.
     * Das jeweils andere wird geleert (Invariante „genau eines").
     */
    public function fillSlot(Team $team, int $slotId, array $in): FoodAlchemistConceptSlot
    {
        $slot = $this->ownedSlot($team, $slotId);

        if (! empty($in['paket_id'])) {
            $slot->update([
                'paket_id' => (int) $in['paket_id'],
                'vk_recipe_id' => null, 'menge' => null, 'einheit_vocab_id' => null,
            ]);
        } elseif (! empty($in['vk_recipe_id'])) {
            $slot->update([
                'vk_recipe_id' => (int) $in['vk_recipe_id'],
                'menge' => $in['menge'] ?? null, 'einheit_vocab_id' => $in['einheit_vocab_id'] ?? null,
                'paket_id' => null,
            ]);
        } else {
            $slot->update(['paket_id' => null, 'vk_recipe_id' => null, 'menge' => null, 'einheit_vocab_id' => null]);
        }
        $this->refreshCache($slot->concept);

        return $slot->refresh();
    }

    public function removeSlot(Team $team, int $slotId): void
    {
        $slot = $this->ownedSlot($team, $slotId);
        $concept = $slot->concept;
        $slot->delete();
        $this->refreshCache($concept);
    }

    /** @param list<int> $ids neue Reihenfolge */
    public function reorderSlots(Team $team, int $conceptId, array $ids): void
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        $this->guardOwner($concept, $team);
        DB::transaction(function () use ($conceptId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistConceptSlot::where('id', (int) $id)->where('concept_id', $conceptId)->update(['position' => $i]);
            }
        });
    }

    /** Austauschbare Pakete für einen Slot = gleiche Rolle (M13-Vorstufe). */
    public function tauschbarePakete(Team $team, FoodAlchemistConceptSlot $slot): Collection
    {
        return FoodAlchemistPaket::visibleToTeam($team)
            ->where('is_inactive', false)
            ->when($slot->rolle, fn ($q, $rolle) => $q->where('rolle', $rolle))
            ->orderBy('name')
            ->get(['id', 'name', 'rolle', 'preis_pro_person']);
    }

    // ── M10-04: Live-Output-Preis (Σ gespeicherte Paket-Preise) ─────────

    /**
     * Concept-Preis = Σ Slot-Preise aus den GESPEICHERTEN Paket-Preisen
     * (+ feste Gerichte). KEIN Kaskaden-Recompute — ein Tausch ändert nur die
     * betroffene Zeile.
     *
     * @return array{zeilen: list<array>, preis_pro_person: float, ek_pro_person: float, hat_stale: bool, hat_leer: bool}
     */
    public function preisCockpit(FoodAlchemistConcept $concept): array
    {
        $concept->loadMissing(['slots' => fn ($q) => $q->orderBy('position'),
            'slots.paket:id,name,preis_pro_person,ek_pro_person,preis_stale',
            'slots.gericht:id,name,vk_netto,ek_total_eur']);

        $zeilen = [];
        $vkTotal = 0.0;
        $ekTotal = 0.0;
        $hatStale = false;
        $hatLeer = false;

        foreach ($concept->slots as $slot) {
            if ($slot->paket_id !== null && $slot->paket) {
                $vk = (float) ($slot->paket->preis_pro_person ?? 0);
                $ek = (float) ($slot->paket->ek_pro_person ?? 0);
                $hatStale = $hatStale || (bool) $slot->paket->preis_stale;
                $zeilen[] = ['slot_id' => $slot->id, 'typ' => 'paket', 'rolle' => $slot->rolle,
                    'label' => $slot->paket->name, 'preis' => $vk, 'stale' => (bool) $slot->paket->preis_stale];
            } elseif ($slot->vk_recipe_id !== null && $slot->gericht) {
                $faktor = $slot->menge !== null ? (float) $slot->menge : 1.0;
                $vk = (float) ($slot->gericht->vk_netto ?? 0) * $faktor;
                $ek = (float) ($slot->gericht->ek_total_eur ?? 0) * $faktor;
                $zeilen[] = ['slot_id' => $slot->id, 'typ' => 'gericht', 'rolle' => $slot->rolle,
                    'label' => $slot->gericht->name, 'preis' => round($vk, 2), 'stale' => false];
            } else {
                $vk = 0.0;
                $ek = 0.0;
                $hatLeer = true;
                $zeilen[] = ['slot_id' => $slot->id, 'typ' => 'leer', 'rolle' => $slot->rolle,
                    'label' => $slot->titel ?? '(leer)', 'preis' => null, 'stale' => false];
            }
            $vkTotal += $vk;
            $ekTotal += $ek;
        }

        return [
            'zeilen' => $zeilen,
            'preis_pro_person' => round($vkTotal, 2),
            'ek_pro_person' => round($ekTotal, 2),
            'hat_stale' => $hatStale,
            'hat_leer' => $hatLeer,
        ];
    }

    /**
     * Mengen-Hochrechnung für eine GEGEBENE Pax-Zahl — je Gericht (aus den
     * Paketen + fest gesetzte Gerichte) `menge` pro Person × Pax. Das Concept
     * ist person-UNABHÄNGIG (D-CON-5) — die Pax kommt vom Aufruf (Foodbook/Angebot,
     * M11), nicht vom Concept. `menge` = Menge pro Person in der Einheit.
     *
     * @return list<array{rolle:?string, paket:?string, gericht:string, menge_pro_person:?float, einheit:?string, gesamt_menge:?float}>
     */
    public function mengenHochrechnung(FoodAlchemistConcept $concept, ?int $personen = null): array
    {
        $concept->loadMissing([
            'slots' => fn ($q) => $q->orderBy('position'),
            'slots.paket.gerichte.gericht:id,name',
            'slots.paket.gerichte.einheit:id,slug,display_de',
            'slots.gericht:id,name', 'slots.einheit:id,slug,display_de',
        ]);

        $zeilen = [];
        foreach ($concept->slots as $slot) {
            if ($slot->paket_id !== null && $slot->paket) {
                foreach ($slot->paket->gerichte as $bg) {
                    $mpp = $bg->menge !== null ? (float) $bg->menge : null;
                    $zeilen[] = [
                        'rolle' => $slot->rolle, 'paket' => $slot->paket->name,
                        'gericht' => $bg->gericht?->name ?? '—',
                        'menge_pro_person' => $mpp,
                        'einheit' => $bg->einheit?->display_de ?? $bg->einheit?->slug,
                        'gesamt_menge' => $mpp !== null && $personen !== null ? round($mpp * $personen, 2) : null,
                    ];
                }
            } elseif ($slot->vk_recipe_id !== null && $slot->gericht) {
                $mpp = $slot->menge !== null ? (float) $slot->menge : null;
                $zeilen[] = [
                    'rolle' => $slot->rolle, 'paket' => null,
                    'gericht' => $slot->gericht->name,
                    'menge_pro_person' => $mpp,
                    'einheit' => $slot->einheit?->display_de ?? $slot->einheit?->slug,
                    'gesamt_menge' => $mpp !== null && $personen !== null ? round($mpp * $personen, 2) : null,
                ];
            }
        }

        return $zeilen;
    }

    /**
     * C-09: Allergen-/Diät-Rollup über ALLE Gerichte des Concepts (aus den
     * Paketen + feste Gerichte). „all"-Flags (vegan/vegetarisch/halal/glutenfrei/
     * laktosefrei) gelten nur, wenn ALLE Gerichte sie erfüllen; „enthält"-Flags
     * (Schwein/Rind) bei MIND. EINEM. Konfidenz = schwächstes Glied. Liest die
     * GL-08-Spec-Flags am Rezept — keine eigene Aggregation (eine Regel-Stelle).
     *
     * @return array{n_gerichte:int, is_vegan:bool, is_vegetarian:bool, is_halal:bool, is_gluten_free:bool, is_lactose_free:bool, contains_pork:bool, contains_beef:bool, konfidenz:string}
     */
    public function allergenRollup(FoodAlchemistConcept $concept): array
    {
        $concept->loadMissing([
            'slots.paket.gerichte.gericht:id,spec_is_vegan,spec_is_vegetarian,spec_is_halal,spec_is_gluten_free,spec_is_lactose_free,spec_contains_pork,spec_contains_beef,allergene_konfidenz',
            'slots.gericht:id,spec_is_vegan,spec_is_vegetarian,spec_is_halal,spec_is_gluten_free,spec_is_lactose_free,spec_contains_pork,spec_contains_beef,allergene_konfidenz',
        ]);

        $gerichte = collect();
        foreach ($concept->slots as $slot) {
            if ($slot->paket) {
                $gerichte = $gerichte->merge($slot->paket->gerichte->pluck('gericht')->filter());
            }
            if ($slot->gericht) {
                $gerichte->push($slot->gericht);
            }
        }

        // M10R-1: kanonische Rollup-Stelle (eine Regel) — Output-Form unverändert.
        return app(ConcepterAggregateService::class)->allergenRollupFromGerichte($gerichte);
    }

    private function refreshCache(FoodAlchemistConcept $concept): void
    {
        // M10R-1: Preis-Cache + Voll-Aggregat-Caches (Nährwerte/Person, Arbeitszeit, EK).
        $agg = app(ConcepterAggregateService::class)->conceptAggregat($concept);
        $concept->update([
            'preis_pro_person_cache' => $this->preisCockpit($concept)['preis_pro_person'],
            'naehrwerte_cache' => $agg['naehrwerte'],
            'arbeitszeit_min_cache' => $agg['arbeitszeit_min'],
            'ek_pro_person_cache' => $agg['ek_pro_person'],
        ]);
    }

    // ── M10-05: Vorlage = Fork ─────────────────────────────────────────────

    /** „Aus Vorlage starten" — forkt das Slot-Gerüst in ein neues, eigenständiges Concept. */
    public function forkVonVorlage(Team $team, int $vorlageId, string $name): FoodAlchemistConcept
    {
        $vorlage = FoodAlchemistConcept::visibleToTeam($team)->with('slots')->findOrFail($vorlageId);

        return DB::transaction(function () use ($team, $vorlage, $name) {
            $neu = FoodAlchemistConcept::create([
                'team_id' => $team->id, 'name' => $name,
                'anlass' => $vorlage->anlass, 'niveau' => $vorlage->niveau,
                'status' => 'draft', 'is_vorlage' => false, 'vorlage_quelle_id' => $vorlage->id,
            ]);
            foreach ($vorlage->slots as $slot) {
                $neu->slots()->create([
                    'team_id' => $team->id, 'rolle' => $slot->rolle, 'titel' => $slot->titel,
                    'position' => $slot->position, 'is_pflicht' => $slot->is_pflicht,
                    'paket_id' => $slot->paket_id,          // Paket bleibt Referenz (zieht durch)
                    'vk_recipe_id' => $slot->vk_recipe_id, 'menge' => $slot->menge,
                    'einheit_vocab_id' => $slot->einheit_vocab_id,
                ]);
            }
            $this->refreshCache($neu);

            return $neu->refresh();
        });
    }

    /** „Als Vorlage speichern" — friert das aktuelle Slot-Gerüst als neue Vorlage ein. */
    public function alsVorlageSpeichern(Team $team, int $conceptId, ?string $name = null): FoodAlchemistConcept
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        $vorlage = $this->forkVonVorlage($team, $conceptId, $name ?: ($concept->name . ' (Vorlage)'));
        $vorlage->update(['is_vorlage' => true, 'vorlage_quelle_id' => null, 'status' => 'aktiv']);

        return $vorlage->refresh();
    }

    // ── M13: Zielpreis-Konfigurator (Modus im Concept-Editor) ───────────────

    /**
     * Schlägt eine Paket-Kombination vor, die dem Zielpreis (€/Person) am nächsten
     * kommt. Greift NUR an Paket-Slots (gleiche Rolle = `tauschbarePakete`); feste
     * Gerichte sind Fixkosten. Deterministisch: exhaustiv bei kleiner Kombinatorik,
     * sonst greedy Hill-Climb. Persistiert NICHTS — erst `zielpreisAnwenden`.
     *
     * @return array{vorschlag: array<int,int>, preis: float, aktuell: float, ziel: float,
     *               aenderungen: int, min: float, max: float, fix: float}
     */
    public function zielpreisVorschlag(Team $team, int $conceptId, float $ziel): array
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->with('slots')->findOrFail($conceptId);

        $fix = 0.0;
        $slots = [];   // adjustable: ['slot_id', 'current_paket_id', 'kandidaten' => [paket_id => preis]]
        foreach ($concept->slots as $slot) {
            $kandidaten = $this->tauschbarePakete($team, $slot)
                ->mapWithKeys(fn ($p) => [(int) $p->id => (float) ($p->preis_pro_person ?? 0)])->all();
            // aktuelles Paket aufnehmen, falls (z. B. inaktiv) nicht in den Kandidaten
            if ($slot->paket_id !== null && ! isset($kandidaten[$slot->paket_id]) && $slot->paket) {
                $kandidaten[(int) $slot->paket_id] = (float) ($slot->paket->preis_pro_person ?? 0);
            }
            if (! empty($kandidaten)) {
                $slots[] = ['slot_id' => (int) $slot->id, 'current' => $slot->paket_id !== null ? (int) $slot->paket_id : null, 'kandidaten' => $kandidaten];
            } elseif ($slot->vk_recipe_id !== null && $slot->gericht) {
                $fix += (float) ($slot->gericht->vk_netto ?? 0) * ($slot->menge !== null ? (float) $slot->menge : 1.0);
            }
        }

        $aktuell = $this->preisCockpit($concept)['preis_pro_person'];
        $zielRest = $ziel - $fix;

        // erreichbare Spanne (Σ min/max je Slot)
        $min = $fix;
        $max = $fix;
        foreach ($slots as $s) {
            $min += min($s['kandidaten']);
            $max += max($s['kandidaten']);
        }

        $wahl = $this->loeseZielpreis($slots, $zielRest);  // [slot_id => paket_id]
        $preis = $fix;
        foreach ($wahl as $slotId => $paketId) {
            $s = collect($slots)->firstWhere('slot_id', $slotId);
            $preis += $s['kandidaten'][$paketId];
        }
        $aenderungen = collect($slots)->filter(fn ($s) => ($wahl[$s['slot_id']] ?? null) !== $s['current'])->count();

        return [
            'vorschlag' => $wahl,
            'preis' => round($preis, 2),
            'aktuell' => $aktuell,
            'ziel' => round($ziel, 2),
            'aenderungen' => $aenderungen,
            'min' => round($min, 2),
            'max' => round($max, 2),
            'fix' => round($fix, 2),
        ];
    }

    /**
     * Kombinations-Optimierung: eine Kandidaten-ID je Slot so wählen, dass die Summe
     * dem Rest-Ziel am nächsten kommt. Exhaustiv (≤ 4000 Kombis), sonst greedy.
     *
     * @param  list<array{slot_id:int, current:?int, kandidaten:array<int,float>}>  $slots
     * @return array<int,int> slot_id => paket_id
     */
    private function loeseZielpreis(array $slots, float $zielRest): array
    {
        if (empty($slots)) {
            return [];
        }
        $kombis = array_product(array_map(fn ($s) => count($s['kandidaten']), $slots));

        if ($kombis > 0 && $kombis <= 4000) {
            $bestGap = INF;
            $best = [];
            $listen = array_map(fn ($s) => array_keys($s['kandidaten']), $slots);
            $n = count($slots);
            for ($i = 0; $i < $kombis; $i++) {
                $rest = $i;
                $sum = 0.0;
                $wahl = [];
                for ($j = 0; $j < $n; $j++) {
                    $anz = count($listen[$j]);
                    $idx = $rest % $anz;
                    $rest = intdiv($rest, $anz);
                    $pid = $listen[$j][$idx];
                    $wahl[$slots[$j]['slot_id']] = $pid;
                    $sum += $slots[$j]['kandidaten'][$pid];
                }
                $gap = abs($sum - $zielRest);
                if ($gap < $bestGap - 1e-9) {
                    $bestGap = $gap;
                    $best = $wahl;
                }
            }

            return $best;
        }

        // Greedy Hill-Climb: Start = aktuelles (oder günstigstes) Paket je Slot
        $wahl = [];
        $sum = 0.0;
        foreach ($slots as $s) {
            $pid = $s['current'] !== null && isset($s['kandidaten'][$s['current']])
                ? $s['current'] : array_key_first($s['kandidaten']);
            $wahl[$s['slot_id']] = $pid;
            $sum += $s['kandidaten'][$pid];
        }
        for ($iter = 0; $iter < 200; $iter++) {
            $bestGap = abs($sum - $zielRest);
            $move = null;
            foreach ($slots as $s) {
                foreach ($s['kandidaten'] as $pid => $preis) {
                    if ($pid === $wahl[$s['slot_id']]) {
                        continue;
                    }
                    $neu = $sum - $s['kandidaten'][$wahl[$s['slot_id']]] + $preis;
                    if (abs($neu - $zielRest) < $bestGap - 1e-9) {
                        $bestGap = abs($neu - $zielRest);
                        $move = ['slot' => $s['slot_id'], 'pid' => $pid, 'sum' => $neu];
                    }
                }
            }
            if ($move === null) {
                break;
            }
            $wahl[$move['slot']] = $move['pid'];
            $sum = $move['sum'];
        }

        return $wahl;
    }

    /** Wendet einen Zielpreis-Vorschlag an (Paket-Tausch je Slot). */
    public function zielpreisAnwenden(Team $team, int $conceptId, array $vorschlag): FoodAlchemistConcept
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
        $this->guardOwner($concept, $team);
        foreach ($vorschlag as $slotId => $paketId) {
            $this->fillSlot($team, (int) $slotId, ['paket_id' => (int) $paketId]);
        }

        return $concept->refresh();
    }

    // ── M10c-B: Kategorien (Baum) ──────────────────────────────────────────

    /**
     * Flache, vorsortierte Kategorie-Liste mit Tiefe (Pre-Order) — die UI rendert
     * daraus Baum (Einrückung) und Select.
     *
     * @return list<array{id:int, name:string, parent_id:?int, depth:int, label:string}>
     */
    public function categoriesFlat(Team $team): array
    {
        $alle = FoodAlchemistConceptCategory::visibleToTeam($team)
            ->orderBy('position')->orderBy('name')->get(['id', 'name', 'parent_id']);
        $byParent = $alle->groupBy(fn ($c) => $c->parent_id ?? 0);
        $out = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$out) {
            foreach ($byParent[$parentId] ?? [] as $c) {
                $out[] = [
                    'id' => (int) $c->id, 'name' => $c->name,
                    'parent_id' => $c->parent_id !== null ? (int) $c->parent_id : null,
                    'depth' => $depth, 'label' => str_repeat('— ', $depth) . $c->name,
                ];
                $walk((int) $c->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /** Kategorie-ID + alle Nachfahren (Filter inkl. Untergruppen). @return list<int> */
    public function descendantIds(Team $team, int $categoryId): array
    {
        $kinder = [];
        foreach ($this->categoriesFlat($team) as $row) {
            $kinder[$row['parent_id'] ?? 0][] = $row['id'];
        }
        $ids = [];
        $stack = [$categoryId];
        while ($stack) {
            $id = array_pop($stack);
            $ids[] = $id;
            foreach ($kinder[$id] ?? [] as $kid) {
                $stack[] = $kid;
            }
        }

        return $ids;
    }

    public function createCategory(Team $team, string $name, ?int $parentId = null): FoodAlchemistConceptCategory
    {
        $name = trim($name);
        $maxPos = FoodAlchemistConceptCategory::where('team_id', $team->id)
            ->when($parentId, fn ($q, $p) => $q->where('parent_id', $p), fn ($q) => $q->whereNull('parent_id'))
            ->max('position');

        return FoodAlchemistConceptCategory::create([
            'team_id' => $team->id,
            'name' => $name !== '' ? $name : 'Neue Kategorie',
            'parent_id' => $parentId ?: null,
            'position' => (int) $maxPos + 1,
        ]);
    }

    public function renameCategory(Team $team, int $id, string $name): void
    {
        $cat = FoodAlchemistConceptCategory::visibleToTeam($team)->findOrFail($id);
        $this->guardOwnerCategory($cat, $team);
        $name = trim($name);
        if ($name !== '') {
            $cat->update(['name' => $name]);
        }
    }

    /** Löschen: Kinder + zugeordnete Concepts an den Eltern hängen, dann löschen. */
    public function deleteCategory(Team $team, int $id): void
    {
        $cat = FoodAlchemistConceptCategory::visibleToTeam($team)->findOrFail($id);
        $this->guardOwnerCategory($cat, $team);
        DB::transaction(function () use ($cat) {
            FoodAlchemistConceptCategory::where('parent_id', $cat->id)->update(['parent_id' => $cat->parent_id]);
            FoodAlchemistConcept::where('category_id', $cat->id)->update(['category_id' => $cat->parent_id]);
            $cat->delete();
        });
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function guardOwnerCategory(FoodAlchemistConceptCategory $cat, Team $team): void
    {
        if (! $cat->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Kategorie — Pflege nur durchs Besitzer-Team (D1).');
        }
    }

    private function ownedSlot(Team $team, int $slotId): FoodAlchemistConceptSlot
    {
        $slot = FoodAlchemistConceptSlot::with('concept')->findOrFail($slotId);
        if ($slot->concept === null || ! in_array((int) $slot->concept->team_id, FoodAlchemistConcept::teamAncestryIds($team), true)) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Slot nicht sichtbar.');
        }
        $this->guardOwner($slot->concept, $team);

        return $slot;
    }

    private function guardOwner(FoodAlchemistConcept $concept, Team $team): void
    {
        if (! $concept->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Concept — Pflege nur durchs Besitzer-Team (D1).');
        }
    }

    private function norm(?string $v): ?string
    {
        $v = $v !== null ? trim($v) : null;

        return $v === '' ? null : $v;
    }
}
