<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistBaustein;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;

/**
 * M10-03/04/05 / Doc 15 §M10: Concept = Slot-Gerüst über mehrere Rollen
 * (z. B. „Grill-Buffet"). Jeder Slot ist mit GENAU EINEM gefüllt: Baustein
 * (austauschbar) ODER festes Gericht.
 *
 * Preis (M10-04): Concept-Preis = Σ der GESPEICHERTEN Baustein-Preise (+ feste
 * Gerichte) — ein Baustein-Tausch ändert nur die Differenz, KEIN Kaskaden-
 * Recompute der ganzen GP→Rezept→Gericht-Kette.
 *
 * Vorlage (M10-05, D-CON-7): Vorlage = Kopie-Quelle. „Aus Vorlage starten" forkt
 * das Slot-Gerüst; das Concept lebt danach eigenständig (Vorlage zieht NICHT
 * durch). Baustein bleibt dagegen Referenz (Änderung schlägt durch).
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
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function detail(Team $team, int $id): ?FoodAlchemistConcept
    {
        return FoodAlchemistConcept::visibleToTeam($team)
            ->with([
                'slots' => fn ($q) => $q->orderBy('position'),
                'slots.baustein:id,name,rolle,preis_pro_person,ek_pro_person,wareneinsatz_prozent,preis_modus,preis_stale',
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

    private const FELDER = ['name', 'anlass', 'niveau', 'personen', 'status', 'beschreibung', 'note'];

    public function update(Team $team, int $id, array $in): FoodAlchemistConcept
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($concept, $team);
        $concept->update(array_intersect_key($in, array_flip(self::FELDER)));

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
     * Befüllt einen Slot mit GENAU EINEM: Baustein ODER festem Gericht.
     * Das jeweils andere wird geleert (Invariante „genau eines").
     */
    public function fillSlot(Team $team, int $slotId, array $in): FoodAlchemistConceptSlot
    {
        $slot = $this->ownedSlot($team, $slotId);

        if (! empty($in['baustein_id'])) {
            $slot->update([
                'baustein_id' => (int) $in['baustein_id'],
                'vk_recipe_id' => null, 'menge' => null, 'einheit_vocab_id' => null,
            ]);
        } elseif (! empty($in['vk_recipe_id'])) {
            $slot->update([
                'vk_recipe_id' => (int) $in['vk_recipe_id'],
                'menge' => $in['menge'] ?? null, 'einheit_vocab_id' => $in['einheit_vocab_id'] ?? null,
                'baustein_id' => null,
            ]);
        } else {
            $slot->update(['baustein_id' => null, 'vk_recipe_id' => null, 'menge' => null, 'einheit_vocab_id' => null]);
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

    /** Austauschbare Bausteine für einen Slot = gleiche Rolle (M13-Vorstufe). */
    public function tauschbareBausteine(Team $team, FoodAlchemistConceptSlot $slot): Collection
    {
        return FoodAlchemistBaustein::visibleToTeam($team)
            ->where('is_inactive', false)
            ->when($slot->rolle, fn ($q, $rolle) => $q->where('rolle', $rolle))
            ->orderBy('name')
            ->get(['id', 'name', 'rolle', 'preis_pro_person']);
    }

    // ── M10-04: Live-Output-Preis (Σ gespeicherte Baustein-Preise) ─────────

    /**
     * Concept-Preis = Σ Slot-Preise aus den GESPEICHERTEN Baustein-Preisen
     * (+ feste Gerichte). KEIN Kaskaden-Recompute — ein Tausch ändert nur die
     * betroffene Zeile.
     *
     * @return array{zeilen: list<array>, preis_pro_person: float, ek_pro_person: float, hat_stale: bool, hat_leer: bool}
     */
    public function preisCockpit(FoodAlchemistConcept $concept): array
    {
        $concept->loadMissing(['slots' => fn ($q) => $q->orderBy('position'),
            'slots.baustein:id,name,preis_pro_person,ek_pro_person,preis_stale',
            'slots.gericht:id,name,vk_netto,ek_total_eur']);

        $zeilen = [];
        $vkTotal = 0.0;
        $ekTotal = 0.0;
        $hatStale = false;
        $hatLeer = false;

        foreach ($concept->slots as $slot) {
            if ($slot->baustein_id !== null && $slot->baustein) {
                $vk = (float) ($slot->baustein->preis_pro_person ?? 0);
                $ek = (float) ($slot->baustein->ek_pro_person ?? 0);
                $hatStale = $hatStale || (bool) $slot->baustein->preis_stale;
                $zeilen[] = ['slot_id' => $slot->id, 'typ' => 'baustein', 'rolle' => $slot->rolle,
                    'label' => $slot->baustein->name, 'preis' => $vk, 'stale' => (bool) $slot->baustein->preis_stale];
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

        $personen = $concept->personen;

        return [
            'zeilen' => $zeilen,
            'preis_pro_person' => round($vkTotal, 2),
            'ek_pro_person' => round($ekTotal, 2),
            'personen' => $personen,
            'gesamt_preis' => $personen !== null ? round($vkTotal * $personen, 2) : null,
            'gesamt_ek' => $personen !== null ? round($ekTotal * $personen, 2) : null,
            'hat_stale' => $hatStale,
            'hat_leer' => $hatLeer,
        ];
    }

    /**
     * C-08: Mengen-Hochrechnung für N Personen — je Gericht (aus den Bausteinen +
     * fest gesetzte Gerichte) `menge` pro Person × Personen. Grundlage für die
     * spätere Produktionsplanung (M16). `menge` = Menge pro Person in der Einheit.
     *
     * @return list<array{rolle:?string, baustein:?string, gericht:string, menge_pro_person:?float, einheit:?string, gesamt_menge:?float}>
     */
    public function mengenHochrechnung(FoodAlchemistConcept $concept): array
    {
        $personen = $concept->personen;
        $concept->loadMissing([
            'slots' => fn ($q) => $q->orderBy('position'),
            'slots.baustein.gerichte.gericht:id,name',
            'slots.baustein.gerichte.einheit:id,slug,display_de',
            'slots.gericht:id,name', 'slots.einheit:id,slug,display_de',
        ]);

        $zeilen = [];
        foreach ($concept->slots as $slot) {
            if ($slot->baustein_id !== null && $slot->baustein) {
                foreach ($slot->baustein->gerichte as $bg) {
                    $mpp = $bg->menge !== null ? (float) $bg->menge : null;
                    $zeilen[] = [
                        'rolle' => $slot->rolle, 'baustein' => $slot->baustein->name,
                        'gericht' => $bg->gericht?->name ?? '—',
                        'menge_pro_person' => $mpp,
                        'einheit' => $bg->einheit?->display_de ?? $bg->einheit?->slug,
                        'gesamt_menge' => $mpp !== null && $personen !== null ? round($mpp * $personen, 2) : null,
                    ];
                }
            } elseif ($slot->vk_recipe_id !== null && $slot->gericht) {
                $mpp = $slot->menge !== null ? (float) $slot->menge : null;
                $zeilen[] = [
                    'rolle' => $slot->rolle, 'baustein' => null,
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
     * Bausteinen + feste Gerichte). „all"-Flags (vegan/vegetarisch/halal/glutenfrei/
     * laktosefrei) gelten nur, wenn ALLE Gerichte sie erfüllen; „enthält"-Flags
     * (Schwein/Rind) bei MIND. EINEM. Konfidenz = schwächstes Glied. Liest die
     * GL-08-Spec-Flags am Rezept — keine eigene Aggregation (eine Regel-Stelle).
     *
     * @return array{n_gerichte:int, is_vegan:bool, is_vegetarian:bool, is_halal:bool, is_gluten_free:bool, is_lactose_free:bool, contains_pork:bool, contains_beef:bool, konfidenz:string}
     */
    public function allergenRollup(FoodAlchemistConcept $concept): array
    {
        $concept->loadMissing([
            'slots.baustein.gerichte.gericht:id,spec_is_vegan,spec_is_vegetarian,spec_is_halal,spec_is_gluten_free,spec_is_lactose_free,spec_contains_pork,spec_contains_beef,allergene_konfidenz',
            'slots.gericht:id,spec_is_vegan,spec_is_vegetarian,spec_is_halal,spec_is_gluten_free,spec_is_lactose_free,spec_contains_pork,spec_contains_beef,allergene_konfidenz',
        ]);

        $gerichte = collect();
        foreach ($concept->slots as $slot) {
            if ($slot->baustein) {
                $gerichte = $gerichte->merge($slot->baustein->gerichte->pluck('gericht')->filter());
            }
            if ($slot->gericht) {
                $gerichte->push($slot->gericht);
            }
        }
        $gerichte = $gerichte->filter()->unique('id')->values();

        $alle = fn (string $feld) => $gerichte->isNotEmpty() && $gerichte->every(fn ($g) => (bool) $g->{$feld});
        $eines = fn (string $feld) => $gerichte->contains(fn ($g) => (bool) $g->{$feld});
        $rang = ['unknown' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
        $minKonf = $gerichte->min(fn ($g) => $rang[$g->allergene_konfidenz] ?? 0);

        return [
            'n_gerichte' => $gerichte->count(),
            'is_vegan' => $alle('spec_is_vegan'),
            'is_vegetarian' => $alle('spec_is_vegetarian'),
            'is_halal' => $alle('spec_is_halal'),
            'is_gluten_free' => $alle('spec_is_gluten_free'),
            'is_lactose_free' => $alle('spec_is_lactose_free'),
            'contains_pork' => $eines('spec_contains_pork'),
            'contains_beef' => $eines('spec_contains_beef'),
            'konfidenz' => array_search($minKonf, $rang, true) ?: 'unknown',
        ];
    }

    private function refreshCache(FoodAlchemistConcept $concept): void
    {
        $concept->update(['preis_pro_person_cache' => $this->preisCockpit($concept)['preis_pro_person']]);
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
                    'baustein_id' => $slot->baustein_id,          // Baustein bleibt Referenz (zieht durch)
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

    // ── Helpers ────────────────────────────────────────────────────────────

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
