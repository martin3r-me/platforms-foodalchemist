<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKlasse;
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
            ->standardisiert()   // #380: angebots-lokale Entwürfe gehören nicht in den Katalog
            ->withCount('gerichte')
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $s = '%' . mb_strtolower($filters['search']) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(rolle, \'\')) LIKE ?', [$s]));
            })
            ->when(($filters['rolle'] ?? '') !== '', fn ($q) => $q->where('rolle', $filters['rolle']))
            ->when(($filters['klasse'] ?? '') !== '', fn ($q) => $q->where('klasse', $filters['klasse']))
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

    /** Distinkte verwendete Klassen (Filter) + freies Klasse-Vokabular (§10.3). */
    public function klassen(Team $team): array
    {
        $verwendet = FoodAlchemistPaket::visibleToTeam($team)
            ->whereNotNull('klasse')->distinct()->orderBy('klasse')->pluck('klasse')->all();
        $vokabular = FoodAlchemistVocabKlasse::visibleToTeam($team)
            ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->pluck('name')->all();

        return collect($verwendet)->merge($vokabular)->unique()->values()->all();
    }

    public function detail(Team $team, int $id): ?FoodAlchemistPaket
    {
        return FoodAlchemistPaket::visibleToTeam($team)
            ->with(['gerichte' => fn ($q) => $q->orderBy('position'),
                'gerichte.gericht:id,name,vk_netto,vk_brutto,ek_total_eur,mwst_satz,ist_verkaufsrezept,yield_kg',
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
            'klasse' => $this->normalizeRolle($in['klasse'] ?? null),
            'niveau' => $in['niveau'] ?? null,
            'preis_modus' => in_array($modus, ['auto', 'manuell'], true) ? $modus : 'manuell',
        ]);
    }

    /** Editierbare Paket-Felder (Stamm + Klasse/Konsumenten-Name + manuelle Preise). */
    private const FELDER = [
        'name', 'konsumenten_name', 'rolle', 'klasse', 'niveau', 'preis_modus', 'preis_pro_person',
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
        if (array_key_exists('klasse', $update)) {
            $update['klasse'] = $this->normalizeRolle($update['klasse']);
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

    /** B-10: Paket duplizieren (Stamm + Gerichte, „(Kopie)"). */
    public function duplicate(Team $team, int $id): FoodAlchemistPaket
    {
        $orig = FoodAlchemistPaket::visibleToTeam($team)->with('gerichte')->findOrFail($id);

        return DB::transaction(function () use ($team, $orig) {
            $felder = array_intersect_key($orig->attributesToArray(), array_flip([
                'konsumenten_name', 'rolle', 'klasse', 'niveau', 'preis_modus', 'preis_pro_person',
                'ek_pro_person', 'wareneinsatz_prozent', 'beschreibung', 'note',
            ]));
            $neu = FoodAlchemistPaket::create($felder + [
                'team_id' => $team->id, 'name' => $orig->name . ' (Kopie)',
            ]);
            foreach ($orig->gerichte as $g) {
                $neu->gerichte()->create([
                    'team_id' => $team->id, 'vk_recipe_id' => $g->vk_recipe_id,
                    'menge' => $g->menge, 'einheit_vocab_id' => $g->einheit_vocab_id, 'position' => $g->position,
                ]);
            }
            app(ConcepterAggregateService::class)->cachePaket($neu);

            return $neu->refresh();
        });
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

        // EK/W% (im auto-Modus zusätzlich der Preis) aus den Gerichten ableiten —
        // setzt zugleich preis_stale=false (manuell: Stand bestätigt).
        $this->recomputePrice($paket);

        // M10R-1: Nährwert-/Arbeitszeit-Aggregat-Cache aktualisieren (Gerichte geändert).
        app(ConcepterAggregateService::class)->cachePaket($paket->refresh());

        return $paket->refresh();
    }

    /** Menge/Person eines Gerichts im Paket setzen (C-08-Hochrechnung). */
    public function setGerichtMenge(Team $team, int $paketId, int $gerichtRowId, ?float $menge): void
    {
        $paket = FoodAlchemistPaket::visibleToTeam($team)->findOrFail($paketId);
        $this->guardOwner($paket, $team);
        $paket->gerichte()->where('id', $gerichtRowId)->update(['menge' => $menge]);

        // M10R-1: Mengen-Faktor fließt in Nährwert-/Kosten-Rollup → EK + Cache neu.
        $this->recomputePrice($paket->refresh());
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
     * Kosten (EK/Person + W%) IMMER aus den Gerichten ableiten — ein Paket = noch ein
     * Rezept, der Wareneinsatz folgt dem, was draufliegt (Dominique-Feedback 2026-06-13).
     * Im manuell-Modus bleibt nur der VERKAUFspreis fix (Buffet-Pauschale); der auto-Modus
     * leitet zusätzlich den Preis ab. Sind keine Gerichte hinterlegt, bleibt ein evtl. von
     * Hand gesetzter EK unangetastet.
     *
     * EK je Gericht = ek_total_eur / vk_anzahl_einheiten (Wareneinsatz PRO PORTION, nicht
     * Batch!) × Portions-Äquivalent — einheit-abhängig über ConcepterAggregateService::
     * portionsAequivalent() (EINE Stelle, konsistent zu recipeHk/ConcepterAggregate/Cockpit).
     */
    public function recomputePrice(FoodAlchemistPaket $paket): FoodAlchemistPaket
    {
        $auto = $paket->preis_modus === 'auto';
        $gerichte = $paket->gerichte()->with([
            'gericht:id,vk_netto,ek_total_eur,vk_anzahl_einheiten,vk_menge_pro_einheit_g,ist_verkaufsrezept,yield_kg',
            'einheit:id,slug,dimension,default_in_g',
        ])->get();

        $vkSum = 0.0;
        $ekSum = 0.0;
        foreach ($gerichte as $g) {
            // Basisrezept-Posten (z. B. Hausbrot im Brotkorb-Paket): Menge/Person = GRAMM,
            // EK = g/Person ÷ Batch-Gramm × Batch-EK; kein Einzel-VK (Basis wird nicht solo verkauft).
            // Zweig greift nur für ist_verkaufsrezept=0 → Gericht-Pfad unverändert (keine Regression).
            if (! (bool) ($g->gericht->ist_verkaufsrezept ?? true)) {
                $yieldG = (float) ($g->gericht->yield_kg ?? 0) * 1000;
                $mengeG = $g->menge !== null ? (float) $g->menge : null;
                if ($yieldG > 0 && $mengeG !== null && $mengeG > 0) {
                    $ekSum += (float) ($g->gericht->ek_total_eur ?? 0) * ($mengeG / $yieldG);
                }

                continue;
            }
            $pae = ConcepterAggregateService::portionsAequivalent(
                $g->menge !== null ? (float) $g->menge : null,
                $g->einheit,
                $g->gericht,
            );
            if ($pae === null) {
                continue; // Gramm-Position ohne Portionsgewicht → trägt ehrlich nicht bei
            }
            $anzahl = max(1, (int) ($g->gericht->vk_anzahl_einheiten ?? 1));
            $vkSum += (float) ($g->gericht->vk_netto ?? 0) * $pae;
            $ekSum += (float) ($g->gericht->ek_total_eur ?? 0) / $anzahl * $pae;
        }

        $vkBezug = $auto ? ($vkSum > 0 ? $vkSum : null)
            : ($paket->preis_pro_person !== null ? (float) $paket->preis_pro_person : null);
        $marge = $this->marge->marge($vkBezug, $ekSum);

        $update = ['preis_berechnet_am' => now(), 'preis_stale' => false];
        if ($gerichte->isNotEmpty()) {
            $update['ek_pro_person'] = $ekSum > 0 ? round($ekSum, 4) : null;
            $update['wareneinsatz_prozent'] = $marge['wareneinsatz_pct'] ?? null;
        }
        if ($auto) {
            $update['preis_pro_person'] = $vkSum > 0 ? round($vkSum, 2) : null;
        }
        $paket->update($update);

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

    /**
     * Politur B-09: „Wo verwendet?" — Concepts, die dieses Paket in einem Slot
     * referenzieren (Verwendungsnachweis + Löschschutz-Hinweis).
     */
    public function verwendetInConcepts(Team $team, int $paketId): Collection
    {
        $conceptIds = FoodAlchemistConceptSlot::where('paket_id', $paketId)
            ->whereNull('deleted_at')->distinct()->pluck('concept_id');

        return FoodAlchemistConcept::visibleToTeam($team)
            ->whereIn('id', $conceptIds)->orderBy('name')
            ->get(['id', 'name', 'status', 'is_vorlage']);
    }

    /** Gericht-Picker: VK-Rezepte zum Hinzufügen suchen (team-scoped). */
    /**
     * Gericht-Treffer für den Aufbau-Picker. Neben der Freitext-Suche jetzt
     * BAUM-FILTER (VK-Hauptgruppe → Klasse, + Geschmack) — gleiche Kaskade wie
     * der VK-Browser, damit man Gerichte browsen statt nur tippen kann.
     *
     * @param  array{hauptgruppe?:?int, klasse?:?int, geschmack?:?string}  $filter
     */
    public function gerichtKandidaten(Team $team, string $suche, array $filter = [], int $limit = 60): Collection
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->when($suche !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($suche) . '%']))
            ->when($filter['hauptgruppe'] ?? null, fn ($q, $hg) => $q
                ->whereIn('speisen_klasse_id', FoodAlchemistDishClass::where('dish_main_group_id', $hg)->pluck('id')))
            ->when($filter['klasse'] ?? null, fn ($q, $k) => $q->where('speisen_klasse_id', $k))
            ->when(($filter['geschmack'] ?? '') !== '', fn ($q) => $q->where('geschmacksrichtung', $filter['geschmack']))
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'vk_netto']);
    }

    /** Pakete als Concept-Position (linke Liste, Umschalter) — Suche über Name/Rolle + Klasse-Filter. */
    public function paketKandidaten(Team $team, string $suche, array $filter = [], int $limit = 60): Collection
    {
        return FoodAlchemistPaket::visibleToTeam($team)
            ->when($suche !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($suche).'%'])
                ->orWhereRaw('LOWER(COALESCE(rolle, \'\')) LIKE ?', ['%'.mb_strtolower($suche).'%'])))
            ->when(($filter['klasse'] ?? '') !== '', fn ($q) => $q->where('klasse', $filter['klasse']))
            ->when(($filter['rolle'] ?? '') !== '', fn ($q) => $q->where('rolle', $filter['rolle']))
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'preis_pro_person', 'klasse', 'rolle']);
    }

    /**
     * B2: Basisrezepte als Concept-Position (keine Dish-Klassen — die hängen an VK-Gerichten).
     * Filter wie der Rezept-Browser: Hauptgruppe (via Kategorie→main_group), Kategorie, Niveau.
     */
    public function basisKandidaten(Team $team, string $suche, array $filter = [], int $limit = 60): Collection
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->when($suche !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($suche).'%']))
            ->when(($filter['hauptgruppe'] ?? null), fn ($q, $hg) => $q->whereHas('kategorie', fn ($k) => $k->where('main_group_id', (int) $hg)))
            ->when(($filter['kategorie'] ?? null), fn ($q, $kat) => $q->where('kategorie_id', (int) $kat))
            ->when(($filter['niveau'] ?? '') !== '', fn ($q) => $q->whereHas('niveauEignungen', fn ($n) => $n->where('niveau_slug', $filter['niveau'])))
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'ek_total_eur']);
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
