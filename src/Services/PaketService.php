<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabRolle;

/**
 * M10-02/04 / Doc 15 §M10: Paket = bepreistes Bündel mehrerer Gerichte, das
 * eine Rolle füllt. Trägt einen GESPEICHERTEN Per-Person-Preis (Einzelpreis),
 * damit ein Tausch im Concept nur die Differenz rechnet (kein Kaskaden-Recompute).
 *
 * Preis-Modi (D-CON-1):
 *  - manuell: der Verkäufer setzt den Per-Person-Preis (Buffet-Normalfall — ein
 *             Gast nimmt quer durch die Gerichte, nicht 1× jeden Einzelpreis).
 *  - auto:    Vorschlag = Σ der vk_netto / ek_total der Gerichte (plattiertes
 *             Mehr-Komponenten-Gericht); W% via MargeService (eine Regel-Stelle).
 *
 * Scope-Härte: visibleToTeam in JEDER Query; Schreiben nur durchs Besitzer-Team
 * (D1/Curate). team_id NOT NULL im Service erzwungen.
 */
class PaketService
{
    public function __construct(private MargeService $marge)
    {
    }

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistPaket::visibleToTeam($team)
            ->withCount('gerichte')
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $s = '%' . mb_strtolower($filters['search']) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(rolle, \'\')) LIKE ?', [$s]));
            })
            ->when(($filters['rolle'] ?? '') !== '', fn ($q) => $q->where('rolle', $filters['rolle']))
            ->when(($filters['niveau'] ?? '') !== '', fn ($q) => $q->where('niveau', $filters['niveau']))
            ->orderBy('rolle')->orderBy('name')
            ->paginate($perPage);
    }

    /** Distinkte, real verwendete Rollen (für Filter) + Vokabular-Vorschläge. */
    public function rollen(Team $team): array
    {
        $verwendet = FoodAlchemistPaket::visibleToTeam($team)
            ->whereNotNull('rolle')->distinct()->orderBy('rolle')->pluck('rolle')->all();
        $vokabular = FoodAlchemistVocabRolle::visibleToTeam($team)
            ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->pluck('name')->all();

        return collect($verwendet)->merge($vokabular)->unique()->values()->all();
    }

    public function detail(Team $team, int $id): ?FoodAlchemistPaket
    {
        return FoodAlchemistPaket::visibleToTeam($team)
            ->with(['gerichte' => fn ($q) => $q->orderBy('position'),
                'gerichte.gericht:id,name,vk_netto,vk_brutto,ek_total_eur,mwst_satz',
                'gerichte.einheit:id,slug,display_de'])
            ->find($id);
    }

    public function create(Team $team, array $in): FoodAlchemistPaket
    {
        $modus = $in['preis_modus'] ?? 'manuell';

        return FoodAlchemistPaket::create([
            'team_id' => $team->id,
            'name' => trim((string) ($in['name'] ?? 'Neuer Paket')) ?: 'Neuer Paket',
            'rolle' => $this->normalizeRolle($in['rolle'] ?? null),
            'niveau' => $in['niveau'] ?? null,
            'preis_modus' => in_array($modus, ['auto', 'manuell'], true) ? $modus : 'manuell',
        ]);
    }

    /** Editierbare Paket-Felder (Stamm + manuelle Preise). */
    private const FELDER = [
        'name', 'rolle', 'niveau', 'preis_modus', 'preis_pro_person',
        'ek_pro_person', 'wareneinsatz_prozent', 'beschreibung', 'note', 'is_inactive',
    ];

    public function update(Team $team, int $id, array $in): FoodAlchemistPaket
    {
        $paket = FoodAlchemistPaket::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($paket, $team);

        $update = array_intersect_key($in, array_flip(self::FELDER));
        if (array_key_exists('rolle', $update)) {
            $update['rolle'] = $this->normalizeRolle($update['rolle']);
        }
        $paket->update($update);

        // Auto-Modus: Preis wird abgeleitet, manuelle Eingaben werden überschrieben
        if ($paket->preis_modus === 'auto') {
            $this->recomputePrice($paket);
        }

        return $paket->refresh();
    }

    public function delete(Team $team, int $id): void
    {
        $paket = FoodAlchemistPaket::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($paket, $team);
        $paket->delete();
    }

    /**
     * Setzt die Gerichte des Pakets (Vollersatz) in EINER Transaktion (V-07),
     * danach Preis-Recompute im Auto-Modus.
     *
     * @param  array<int, array{vk_recipe_id:int, menge?:float|null, einheit_vocab_id?:int|null}>  $items
     */
    public function syncGerichte(Team $team, int $paketId, array $items): FoodAlchemistPaket
    {
        $paket = FoodAlchemistPaket::visibleToTeam($team)->findOrFail($paketId);
        $this->guardOwner($paket, $team);

        DB::transaction(function () use ($paket, $items) {
            $paket->gerichte()->forceDelete();
            foreach (array_values($items) as $i => $row) {
                if (empty($row['vk_recipe_id'])) {
                    continue;
                }
                $paket->gerichte()->create([
                    'team_id' => $paket->team_id,
                    'vk_recipe_id' => (int) $row['vk_recipe_id'],
                    'menge' => $row['menge'] ?? null,
                    'einheit_vocab_id' => $row['einheit_vocab_id'] ?? null,
                    'position' => $i,
                ]);
            }
        });

        if ($paket->preis_modus === 'auto') {
            $this->recomputePrice($paket);
        } else {
            $paket->update(['preis_stale' => false]); // manuell: Stand bestätigt
        }

        // M10R-1: Nährwert-/Arbeitszeit-Aggregat-Cache aktualisieren (Gerichte geändert).
        app(ConcepterAggregateService::class)->cachePaket($paket);

        return $paket->refresh();
    }

    /** Menge/Person eines Gerichts im Paket setzen (C-08-Hochrechnung). */
    public function setGerichtMenge(Team $team, int $paketId, int $gerichtRowId, ?float $menge): void
    {
        $paket = FoodAlchemistPaket::visibleToTeam($team)->findOrFail($paketId);
        $this->guardOwner($paket, $team);
        $paket->gerichte()->where('id', $gerichtRowId)->update(['menge' => $menge]);

        // M10R-1: Mengen-Faktor fließt in Nährwert-/Kosten-Rollup → Cache neu.
        app(ConcepterAggregateService::class)->cachePaket($paket->refresh());
    }

    /** @param list<int> $ids neue Reihenfolge der paket_gerichte-IDs */
    public function reorderGerichte(Team $team, int $paketId, array $ids): void
    {
        $paket = FoodAlchemistPaket::visibleToTeam($team)->findOrFail($paketId);
        $this->guardOwner($paket, $team);
        DB::transaction(function () use ($paketId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                \Platform\FoodAlchemist\Models\FoodAlchemistPaketGericht::where('id', (int) $id)
                    ->where('paket_id', $paketId)->update(['position' => $i]);
            }
        });
    }

    /**
     * Auto-Preis = Σ der Gerichte (vk_netto/ek_total), W% via MargeService.
     * Manuell-Modus: nur den Stale-Marker löschen (gesetzter Preis bleibt).
     */
    public function recomputePrice(FoodAlchemistPaket $paket): FoodAlchemistPaket
    {
        if ($paket->preis_modus !== 'auto') {
            $paket->update(['preis_stale' => false]);

            return $paket->refresh();
        }

        $gerichte = $paket->gerichte()->with('gericht:id,vk_netto,ek_total_eur')->get();
        $vkSum = 0.0;
        $ekSum = 0.0;
        foreach ($gerichte as $g) {
            $faktor = $g->menge !== null ? (float) $g->menge : 1.0;
            $vkSum += (float) ($g->gericht->vk_netto ?? 0) * $faktor;
            $ekSum += (float) ($g->gericht->ek_total_eur ?? 0) * $faktor;
        }
        $marge = $this->marge->marge($vkSum > 0 ? $vkSum : null, $ekSum);

        $paket->update([
            'preis_pro_person' => $vkSum > 0 ? round($vkSum, 2) : null,
            'ek_pro_person' => $ekSum > 0 ? round($ekSum, 4) : null,
            'wareneinsatz_prozent' => $marge['wareneinsatz_pct'] ?? null,
            'preis_berechnet_am' => now(),
            'preis_stale' => false,
        ]);

        return $paket->refresh();
    }

    /**
     * GL-02-Muster: markiert alle Auto-Pakete, die ein bestimmtes Gericht
     * enthalten, als preis_stale (neu zu berechnen). Aufruf-Hook für die
     * Recompute-Pipeline, wenn sich ein GP-/Rezept-Preis ändert.
     */
    public function markStaleForRecipe(int $vkRecipeId): int
    {
        $paketIds = DB::table('foodalchemist_paket_gerichte')
            ->where('vk_recipe_id', $vkRecipeId)->whereNull('deleted_at')
            ->distinct()->pluck('paket_id');

        return FoodAlchemistPaket::whereIn('id', $paketIds)
            ->where('preis_modus', 'auto')->update(['preis_stale' => true]);
    }

    /** Gericht-Picker: VK-Rezepte zum Hinzufügen suchen (team-scoped). */
    public function gerichtKandidaten(Team $team, string $suche, int $limit = 30): Collection
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->when($suche !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($suche) . '%']))
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'vk_netto']);
    }

    private function normalizeRolle(?string $rolle): ?string
    {
        $rolle = $rolle !== null ? trim($rolle) : null;

        return $rolle === '' ? null : $rolle;
    }

    private function guardOwner(FoodAlchemistPaket $paket, Team $team): void
    {
        if (! $paket->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Paket — Pflege nur durchs Besitzer-Team (D1).');
        }
    }
}
