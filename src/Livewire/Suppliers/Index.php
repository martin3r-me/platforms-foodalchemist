<?php

namespace Platform\FoodAlchemist\Livewire\Suppliers;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Services\SupplierService;
use Platform\FoodAlchemist\Support\Curate;
use RuntimeException;

/**
 * M2-01/02/03 / P-7: Lieferanten-Browser — Liste links (n Artikel · m gemapped),
 * Artikel-Tabelle Mitte (EK + Vergleichspreis M2-05), lieferantenübergreifende
 * Suche via ?q= (V-17/Kontext-Erhalt: Auswahl + Suche leben in der URL).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'lieferant')]
    public ?int $supplierId = null;

    #[Url]
    public string $q = '';

    public string $supplierSuche = '';

    /** M2-14: Suche INNERHALB des gewählten Lieferanten */
    #[Url(as: 'aq')]
    public string $artikelSuche = '';

    /** M2-14: Bearbeiten-Modal */
    public array $editLieferant = [];

    public bool $includeInactive = false;

    public bool $onlyActive = true;

    #[Url(as: 'zeilen')]
    public int $perPage = 100;

    /** Feedback 2026-06-11: „+ Neuer Lieferant" */
    public array $neuLieferant = ['name' => '', 'city' => '', 'email_order' => ''];

    /** M2-11: „+ Neuer Artikel" */
    public array $neuArtikel = ['designation' => '', 'article_number' => '', 'qty' => '', 'unit_code' => ''];

    public ?string $fehler = null;

    /** M2-12: Anomalien-Report (lazy beim Öffnen) */
    public ?array $anomalien = null;

    /** M3-11: Bulk-Match-Lauf-Statistik + Review-Sichtbarkeit */
    public ?array $bulkStats = null;

    public bool $reviewOffen = false;

    /** M3-11-Nachtrag: Checkbox-Selektion für die Bulk-Leiste (D-2 §4) */
    public array $auswahl = [];

    public string $bulkGpSuche = '';

    /** R12 (Jarvis): ★ in der Artikel-Tabelle — LA als Lead seines GPs setzen (GL-03). */
    public function leadSetzen(int $itemId): void
    {
        $this->fehler = null;
        $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation;
        $gpId = \Illuminate\Support\Facades\DB::table('foodalchemist_supplier_item_structures')
            ->where('supplier_item_id', $itemId)->whereNull('deleted_at')->value('gp_id');
        $gp = $team !== null && $gpId !== null
            ? \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->find($gpId)
            : null;
        if ($gp === null) {
            $this->fehler = 'Artikel ist keinem GP zugeordnet — erst mappen (LA-Modal → GP-Mapping).';

            return;
        }
        if (! \Platform\FoodAlchemist\Support\Curate::canCurate(\Illuminate\Support\Facades\Auth::user(), $gp)) {
            $this->fehler = 'Globale Katalog-Aktion — nur fürs Kurations-Team (D1).';

            return;
        }
        try {
            app(\Platform\FoodAlchemist\Services\LeadLaService::class)->setLeadLa($team, $gp, $itemId);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function waehleLieferant(int $id): void
    {
        $this->supplierId = $id;
        $this->q = '';
        $this->resetPage();
    }

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function updatedArtikelSuche(): void
    {
        $this->resetPage();
    }

    public function updatedOnlyActive(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array((int) $this->perPage, [25, 50, 100, 250, 500], true) ? (int) $this->perPage : 100;
        $this->resetPage();
    }

    public function lieferantBearbeiten(): void
    {
        $aktiv = app(SupplierService::class)->listWithCounts(Auth::user()->currentTeamRelation, true)->firstWhere('id', $this->supplierId);
        if ($aktiv === null) {
            return;
        }
        $this->editLieferant = $aktiv->only(['name', 'city', 'address', 'postal_code', 'email_order', 'homepage']);
        $this->fehler = null;
        $this->dispatch('modal.open', name: 'lieferant-edit');
    }

    public function lieferantSpeichern(): void
    {
        try {
            app(SupplierService::class)->update(Auth::user()->currentTeamRelation, (int) $this->supplierId, $this->editLieferant);
            $this->dispatch('modal.close', name: 'lieferant-edit');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function lieferantDeaktivieren(bool $inactive): void
    {
        try {
            app(SupplierService::class)->setInactive(Auth::user()->currentTeamRelation, (int) $this->supplierId, $inactive);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function lieferantAnlegen(): void
    {
        try {
            $supplier = app(SupplierService::class)->create(Auth::user()->currentTeamRelation, $this->neuLieferant);
            $this->reset('neuLieferant', 'fehler');
            $this->dispatch('modal.close', name: 'lieferant-neu');
            $this->waehleLieferant($supplier->id);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function artikelAnlegen(): void
    {
        try {
            $item = app(SupplierItemService::class)->create(
                Auth::user()->currentTeamRelation,
                (int) $this->supplierId,
                $this->neuArtikel,
            );
            $this->reset('neuArtikel', 'fehler');
            $this->dispatch('modal.close', name: 'artikel-neu');
            $this->dispatch('item-modal.oeffnen', id: $item->id);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function anomalienAnzeigen(): void
    {
        $ergebnis = app(PriceService::class)->detectAnomalies(Auth::user()->currentTeamRelation);
        $this->anomalien = [
            'spruenge' => $ergebnis['spruenge']->take(50)->all(),
            'ausreisser' => $ergebnis['ausreisser']->take(50)
                ->map(fn ($a) => ['label' => $a->label, 'lieferant' => $a->lieferant,
                    'wg' => $a->wg, 'value' => $a->value, 'median' => $a->median, 'faktor' => $a->faktor, 'unit' => $a->unit])
                ->all(),
        ];
        $this->dispatch('modal.open', name: 'preis-anomalien');
    }

    // ── M3-11: Bulk-Match + Review-Queue ────────────────────────────────

    public function bulkMatchStarten(): void
    {
        $this->fehler = null;
        if ($this->supplierId === null) {
            return;
        }
        $team = Auth::user()?->currentTeamRelation;
        $this->bulkStats = app(\Platform\FoodAlchemist\Services\MatchService::class)
            ->bulkFuerLieferant($team, $this->supplierId);
        $this->reviewOffen = true;
    }

    public function vorschlagUebernehmen(int $proposalId): void
    {
        $this->fehler = null;
        try {
            app(\Platform\FoodAlchemist\Services\MatchService::class)
                ->uebernehmeVorschlag(Auth::user()->currentTeamRelation, $proposalId);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function vorschlagVerwerfen(int $proposalId): void
    {
        app(\Platform\FoodAlchemist\Services\MatchService::class)->verwerfeVorschlag(Auth::user()->currentTeamRelation, $proposalId);
    }

    // ── M3-11-Nachtrag: Bulk-Leiste (D-2 §4) ────────────────────────────

    public function bulkEinstellen(bool $discontinued): void
    {
        $this->bulkArtikelAktion(function ($items, $team, $id) use ($discontinued) {
            $item = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::visibleToTeam($team)->findOrFail($id);
            $items->setDiscontinued($team, $item, $discontinued);
        });
    }

    public function bulkLoeschen(): void
    {
        $this->bulkArtikelAktion(function ($items, $team, $id) {
            \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::visibleToTeam($team)->findOrFail($id)->delete();
        });
    }

    public function bulkMappingEntfernen(): void
    {
        $this->bulkArtikelAktion(function ($items, $team, $id) {
            $struktur = \Illuminate\Support\Facades\DB::table('foodalchemist_supplier_item_structures')
                ->where('supplier_item_id', $id)->whereNull('deleted_at')->first();
            if ($struktur?->gp_id !== null) {
                $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::find($struktur->gp_id);
                if ($gp !== null) {
                    app(\Platform\FoodAlchemist\Services\LeadLaService::class)->entknuepfen($team, $gp, $id);
                }
            }
        });
    }

    public function bulkGpZuweisen(int $gpId): void
    {
        $this->bulkGpSuche = '';
        $this->bulkArtikelAktion(function ($items, $team, $id) use ($gpId) {
            $gp = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->findOrFail($gpId);
            $struktur = \Illuminate\Support\Facades\DB::table('foodalchemist_supplier_item_structures')
                ->where('supplier_item_id', $id)->whereNull('deleted_at')->first();
            if ($struktur === null || $struktur->gp_id !== null) {
                return;                                              // gemappte überspringen (erst lösen, GL-05)
            }
            app(\Platform\FoodAlchemist\Services\LeadLaService::class)->verknuepfen($team, $gp, $id);
        });
    }

    private function bulkArtikelAktion(\Closure $aktion): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        $items = app(SupplierItemService::class);
        try {
            foreach (array_keys(array_filter($this->auswahl)) as $id) {
                $aktion($items, $team, (int) $id);
            }
            $this->auswahl = [];
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(SupplierService $suppliers, SupplierItemService $items, PriceService $preise)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        $liste = $suppliers->listWithCounts($team, $this->includeInactive, $this->supplierSuche);
        $globaleSuche = trim($this->q) !== '';

        if (! $globaleSuche && $this->supplierId === null) {
            $this->supplierId = $liste->first()?->id;
        }

        $artikel = match (true) {
            $globaleSuche => $items->searchGlobal($team, trim($this->q), ['onlyActive' => $this->onlyActive], $this->perPage),
            $this->supplierId !== null => $items->paginateForSupplier($team, $this->supplierId, ['onlyActive' => $this->onlyActive, 'q' => $this->artikelSuche], $this->perPage),
            default => null,
        };

        // M2-05: Vergleichspreis je Zeile aus EK-Subquery-Wert (eine Regel-Stelle: PriceService)
        $artikel?->getCollection()->each(function ($item) use ($preise) {
            $ek = $item->aktiver_preis !== null ? (float) $item->aktiver_preis : null;
            $item->setAttribute('vergleichspreis', $preise->vergleichspreis($item, $ek));
        });

        return view('foodalchemist::livewire.suppliers.index', [
            'team' => $team,
            // #393: Banner-Zähler team-scoped (aktuelles Team) statt teamübergreifend (Multi-Tenancy-Leak).
            // Model hat SoftDeletes → deleted_at automatisch raus. ReviewQueue-Seite folgt mit #378.
            'offeneMatches' => \Platform\FoodAlchemist\Models\FoodAlchemistMatchProposal::where('team_id', $team->id)
                ->where('status', 'offen')->count(),
            'lieferanten' => $liste,
            'artikel' => $artikel,
            'globaleSuche' => $globaleSuche,
            'aktiverLieferant' => $globaleSuche ? null : $liste->firstWhere('id', $this->supplierId),
            'darfLieferantEdit' => ! $globaleSuche && Curate::canCurate(Auth::user(), $liste->firstWhere('id', $this->supplierId)),
            // M3-11: offene Match-Vorschläge des Lieferanten (Review-Liste)
            'vorschlaege' => $this->reviewOffen && $this->supplierId !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistMatchProposal::with(['item:id,designation', 'gp:id,name'])
                    ->whereHas('item', fn ($q) => $q->where('supplier_id', $this->supplierId))
                    ->where('status', 'offen')->orderByDesc('score')->limit(100)->get()
                : collect(),
            'offeneVorschlaege' => $this->supplierId !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistMatchProposal::whereHas('item', fn ($q) => $q->where('supplier_id', $this->supplierId))
                    ->where('status', 'offen')->count()
                : 0,
            'bulkGpKandidaten' => $this->bulkGpSuche !== ''
                ? \Platform\FoodAlchemist\Support\Suche::like(
                    \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team), 'name', $this->bulkGpSuche)
                    ->orderBy('name')->limit(6)->get()
                : collect(),
        ])->layout('platform::layouts.app');
    }
}
