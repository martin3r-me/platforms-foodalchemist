<?php

namespace Platform\FoodAlchemist\Livewire\Geschirr;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\GeschirrService;
use Platform\FoodAlchemist\Support\TeamScope;
use RuntimeException;

/**
 * #388 Geschirr-Datenbank — Browser (Master-Detail wie Lieferanten, ohne GP/Match).
 * Leih-Lieferant links, Geschirr-Artikel-Tabelle Mitte; Anlegen/Edit über EIN Modal
 * (Neu + Bearbeiten geteilt). Auswahl/Suche in der URL (Kontext-Erhalt, V-17).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'lieferant')]
    public ?int $supplierId = null;

    #[Url]
    public string $q = '';

    public string $supplierSuche = '';

    #[Url(as: 'aq')]
    public string $artikelSuche = '';

    public bool $includeInactive = false;

    public bool $onlyActive = true;

    #[Url(as: 'zeilen')]
    public int $perPage = 100;

    public array $neuLieferant = ['name' => '', 'city' => '', 'email_order' => '', 'telefon' => ''];

    public array $editLieferant = [];

    /** Geteiltes Artikel-Modal: null = Neu-Modus, sonst Edit-Modus. */
    public ?int $editItemId = null;

    /** Formular-Array (Name ≠ View-Variable $artikel = Paginator!). */
    public array $artikelForm = [
        'label' => '', 'artikel_nr' => '', 'category' => '', 'material' => '', 'form' => '', 'color' => '',
        'diameter_mm' => '', 'length_mm' => '', 'width_mm' => '', 'height_mm' => '', 'volumen_ml' => '', 'weight_g' => '',
        'rental_price' => '', 'pfand' => '', 'unit' => 'Stk', 'note' => '',
        'vehicle_vocab_id' => '', // A2: Servier-Vehikel-Typ (Darreichungs-Scharnier)
    ];

    public ?string $fehler = null;

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

    // ── Lieferant ───────────────────────────────────────────────────────

    public function lieferantAnlegen(): void
    {
        try {
            $s = app(GeschirrService::class)->createSupplier(Auth::user()->currentTeamRelation, $this->neuLieferant);
            $this->reset('neuLieferant', 'fehler');
            $this->dispatch('modal.close', name: 'g-lieferant-neu');
            $this->waehleLieferant($s->id);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function lieferantBearbeiten(): void
    {
        $team = Auth::user()->currentTeamRelation;
        $s = app(GeschirrService::class)->listSuppliersWithCounts($team, true)->firstWhere('id', $this->supplierId);
        if ($s === null) {
            return;
        }
        $this->editLieferant = $s->only(['name', 'city', 'address', 'postal_code', 'email_order', 'homepage', 'telefon']);
        $this->fehler = null;
        $this->dispatch('modal.open', name: 'g-lieferant-edit');
    }

    public function lieferantSpeichern(): void
    {
        try {
            app(GeschirrService::class)->updateSupplier(Auth::user()->currentTeamRelation, (int) $this->supplierId, $this->editLieferant);
            $this->dispatch('modal.close', name: 'g-lieferant-edit');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function lieferantDeaktivieren(bool $inactive): void
    {
        try {
            app(GeschirrService::class)->setSupplierInactive(Auth::user()->currentTeamRelation, (int) $this->supplierId, $inactive);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    // ── Artikel (geteiltes Modal) ───────────────────────────────────────

    public function artikelNeu(): void
    {
        $this->editItemId = null;
        $this->reset('artikelForm', 'fehler');
        $this->dispatch('modal.open', name: 'g-artikel');
    }

    public function artikelOeffnen(int $id): void
    {
        $item = app(GeschirrService::class)->findItem($id, Auth::user()->currentTeamRelation);
        if ($item === null) {
            return;
        }
        $this->editItemId = $id;
        $this->fehler = null;
        $this->artikelForm = collect($this->artikelForm)->map(fn ($_, $k) => (string) ($item->{$k} ?? ''))->all();
        $this->artikelForm['unit'] = $item->unit ?: 'Stk';
        $this->dispatch('modal.open', name: 'g-artikel');
    }

    public function artikelSpeichern(): void
    {
        $team = Auth::user()->currentTeamRelation;
        try {
            if ($this->editItemId === null) {
                if ($this->supplierId === null) {
                    throw new RuntimeException('Erst einen Geschirr-Lieferanten wählen.');
                }
                app(GeschirrService::class)->createItem($team, (int) $this->supplierId, $this->artikelForm);
            } else {
                app(GeschirrService::class)->updateItem($team, $this->editItemId, $this->artikelForm);
            }
            $this->reset('artikelForm', 'fehler', 'editItemId');
            $this->dispatch('modal.close', name: 'g-artikel');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function artikelDeaktivieren(int $id, bool $inactive): void
    {
        try {
            app(GeschirrService::class)->setItemInactive(Auth::user()->currentTeamRelation, $id, $inactive);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(GeschirrService $svc)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        $lieferanten = $svc->listSuppliersWithCounts($team, $this->includeInactive, $this->supplierSuche);
        $globaleSuche = trim($this->q) !== '';

        if (! $globaleSuche && $this->supplierId === null) {
            $this->supplierId = $lieferanten->first()?->id;
        }

        $artikel = match (true) {
            $globaleSuche => $svc->searchGlobal($team, trim($this->q), ['onlyActive' => $this->onlyActive], $this->perPage),
            $this->supplierId !== null => $svc->paginateForSupplier($team, $this->supplierId, ['onlyActive' => $this->onlyActive, 'q' => $this->artikelSuche], $this->perPage),
            default => null,
        };

        $aktiverLieferant = $globaleSuche ? null : $lieferanten->firstWhere('id', $this->supplierId);

        return view('foodalchemist::livewire.geschirr.index', [
            'lieferanten' => $lieferanten,
            'artikel' => $artikel,
            'globaleSuche' => $globaleSuche,
            'aktiverLieferant' => $aktiverLieferant,
            'darfLieferantEdit' => $aktiverLieferant !== null && $aktiverLieferant->isOwnedBy($team),
            // A2: Servier-Vehikel-Typen fürs Artikel-Formular (Darreichungs-Scharnier)
            'vehikelListe' => TeamScope::applyVisible(\Illuminate\Support\Facades\DB::table('foodalchemist_vocab_serving_vehicles')
                ->whereNull('deleted_at')->where('is_inactive', false), 'team_id', $team)
                ->orderBy('group_name')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'group_name']),
        ])->layout('platform::layouts.app');
    }
}
