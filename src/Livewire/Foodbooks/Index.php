<?php

namespace Platform\FoodAlchemist\Livewire\Foodbooks;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * M11-03 / Doc 15 §9.3: Foodbook-Editor — stellt fertige **Concepts** zu einem
 * Kunden-Angebot zusammen (KEINE Einzel-Gerichte — der Concepter ist der Kern).
 * Foodbook-Liste + Kapitel-Baum links · Block-Liste Mitte · Pax-Gesamt-Cockpit rechts.
 */
class Index extends Component
{
    use WithPagination, ManagesCanvas;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'fb')]
    public ?int $selectedId = null;

    #[Url(as: 'kap')]
    public ?int $selectedKapitelId = null;

    public array $form = ['bezeichnung' => '', 'kunde' => '', 'jahr' => null, 'personen' => null, 'status' => 'draft', 'beschreibung' => ''];

    public array $kapitelForm = ['titel' => '', 'konsumententitel' => '', 'preis_modus' => 'auto', 'preis_pro_person' => null];

    public string $neuesKapitelTitel = '';

    public string $conceptSuche = '';

    public ?int $conceptKategorie = null;

    /** #369: CRM-Kunde-Picker. */
    public string $firmaSuche = '';

    public string $kontaktSuche = '';

    /** Block, dessen Inline-Editor offen ist + dessen Formular. */
    public ?int $editBlockId = null;

    public array $blockForm = [];

    /** markierte concept_ref-Blöcke für die Wahl-Gruppe. */
    public array $markiert = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    // ── Foodbook ──────────────────────────────────────────────────────────

    public function neu(FoodbookService $svc): void
    {
        $fb = $svc->create($this->team(), ['bezeichnung' => 'Neues Foodbook']);
        $this->waehle($fb->id, $svc);
    }

    public function waehle(int $id, FoodbookService $svc): void
    {
        $fb = $svc->detail($this->team(), $id);
        if ($fb === null) {
            return;
        }
        $this->selectedId = $id;
        $this->form = [
            'bezeichnung' => $fb->bezeichnung, 'kunde' => $fb->kunde ?? '', 'jahr' => $fb->jahr,
            'personen' => $fb->personen, 'status' => $fb->status, 'beschreibung' => $fb->beschreibung ?? '',
        ];
        $this->selectedKapitelId = $fb->kapitel->first()->id ?? null;
        $this->ladeKapitelForm($svc);
        $this->editBlockId = null;
        $this->markiert = [];
    }

    public function speichern(FoodbookService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->update($this->team(), $this->selectedId, $this->form);
        }
    }

    // ── #369: CRM-Kunde-Link (MVP, nur verlinken) ──────────────────────────────

    public function verknuepfeFirma(int $companyId, FoodbookService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $fb = $svc->detail($this->team(), $this->selectedId);
        $svc->verknuepfeKunde($this->team(), $this->selectedId, $companyId, $fb?->crm_contact_id);
        $this->firmaSuche = '';
    }

    public function verknuepfeKontakt(int $contactId, FoodbookService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $fb = $svc->detail($this->team(), $this->selectedId);
        $svc->verknuepfeKunde($this->team(), $this->selectedId, $fb?->crm_company_id, $contactId);
        $this->kontaktSuche = '';
    }

    public function loeseKunde(FoodbookService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->verknuepfeKunde($this->team(), $this->selectedId, null, null);
    }

    public function loeschen(int $id, FoodbookService $svc): void
    {
        $svc->delete($this->team(), $id);
        if ($this->selectedId === $id) {
            $this->selectedId = null;
            $this->selectedKapitelId = null;
        }
    }

    // ── Kapitel ───────────────────────────────────────────────────────────

    public function kapitelNeu(?int $parentId = null): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc = app(FoodbookService::class);   // via Container, nicht als Action-Param — sonst kollidiert die DI mit $parentId
        $titel = $parentId !== null ? 'Neues Unterkapitel' : ($this->neuesKapitelTitel ?: 'Neues Kapitel');
        $k = $svc->addKapitel($this->team(), $this->selectedId, ['titel' => $titel], $parentId);
        $this->neuesKapitelTitel = '';
        $this->selectedKapitelId = $k->id;
        $this->ladeKapitelForm($svc);
    }

    public function kapitelWaehle(int $id, FoodbookService $svc): void
    {
        $this->selectedKapitelId = $id;
        $this->ladeKapitelForm($svc);
        $this->editBlockId = null;
        $this->markiert = [];
    }

    private function ladeKapitelForm(FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $k = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($this->selectedKapitelId);
        if ($k) {
            $this->kapitelForm = [
                'titel' => $k->titel, 'konsumententitel' => $k->konsumententitel ?? '',
                'preis_modus' => $k->preis_modus, 'preis_pro_person' => $k->preis_pro_person,
            ];
        }
    }

    public function kapitelSpeichern(FoodbookService $svc): void
    {
        if ($this->selectedKapitelId !== null) {
            $svc->updateKapitel($this->team(), $this->selectedKapitelId, $this->kapitelForm);
        }
    }

    public function kapitelLoeschen(int $id, FoodbookService $svc): void
    {
        $svc->deleteKapitel($this->team(), $id);
        if ($this->selectedKapitelId === $id) {
            $this->selectedKapitelId = null;
        }
    }

    public function kapitelHoch(int $id, FoodbookService $svc): void
    {
        $this->verschiebeKapitel($id, -1, $svc);
    }

    public function kapitelRunter(int $id, FoodbookService $svc): void
    {
        $this->verschiebeKapitel($id, 1, $svc);
    }

    private function verschiebeKapitel(int $id, int $richtung, FoodbookService $svc): void
    {
        $k = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($id);
        if ($k === null || $this->selectedId === null) {
            return;
        }
        $geschwister = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->selectedId)
            ->where('parent_id', $k->parent_id)->orderBy('position')->pluck('id')->all();
        $pos = array_search($id, $geschwister, true);
        $ziel = $pos + $richtung;
        if ($pos === false || $ziel < 0 || $ziel >= count($geschwister)) {
            return;
        }
        [$geschwister[$pos], $geschwister[$ziel]] = [$geschwister[$ziel], $geschwister[$pos]];
        $svc->reorderKapitel($this->team(), $this->selectedId, $k->parent_id, $geschwister);
    }

    // ── Blöcke ────────────────────────────────────────────────────────────

    public function conceptHinzu(int $conceptId, FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $svc->addBlock($this->team(), $this->selectedKapitelId, ['type' => 'concept_ref', 'concept_id' => $conceptId]);
        $this->conceptSuche = '';
    }

    public function presetHinzu(string $type, ?string $slug, ?string $label, ?string $preisBasis, bool $sichtbar, FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $svc->addBlock($this->team(), $this->selectedKapitelId, [
            'type' => $type, 'header_quelle' => $slug, 'bezeichnung' => $label,
            'preis_basis' => $type === 'header_frei_preis' ? ($preisBasis ?: 'person') : null,
            'preis_wert' => $type === 'header_frei_preis' ? 0 : null,
            'sichtbar' => $sichtbar,
        ]);
    }

    public function blockBasis(string $type, FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $svc->addBlock($this->team(), $this->selectedKapitelId, [
            'type' => $type,
            'hoehe' => $type === 'spacer' ? 'mittel' : null,
            'preis_basis' => $type === 'header_frei_preis' ? 'person' : null,
            'preis_wert' => $type === 'header_frei_preis' ? 0 : null,
        ]);
    }

    public function blockBearbeiten(int $id): void
    {
        $block = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::find($id);
        if ($block === null) {
            return;
        }
        $this->editBlockId = $id;
        $this->blockForm = [
            'bezeichnung' => $block->bezeichnung ?? '', 'kundentext' => $block->kundentext ?? '',
            'preis_wert' => $block->preis_wert, 'preis_basis' => $block->preis_basis ?? 'person',
            'hoehe' => $block->hoehe ?? 'mittel', 'interne_bemerkung' => $block->interne_bemerkung ?? '',
        ];
    }

    public function blockSpeichern(FoodbookService $svc): void
    {
        if ($this->editBlockId !== null) {
            $svc->updateBlock($this->team(), $this->editBlockId, $this->blockForm);
        }
        $this->editBlockId = null;
    }

    public function blockRaus(int $id, FoodbookService $svc): void
    {
        $svc->deleteBlock($this->team(), $id);
    }

    public function blockSichtbar(int $id, FoodbookService $svc): void
    {
        $block = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::find($id);
        if ($block !== null) {
            $svc->updateBlock($this->team(), $id, ['sichtbar' => ! $block->sichtbar]);
        }
    }

    public function blockEbene(int $id, int $delta, FoodbookService $svc): void
    {
        $block = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::find($id);
        if ($block !== null) {
            $svc->updateBlock($this->team(), $id, ['ebene' => max(0, min(2, (int) $block->ebene + $delta))]);
        }
    }

    public function blockHoch(int $id, FoodbookService $svc): void
    {
        $this->verschiebeBlock($id, -1, $svc);
    }

    public function blockRunter(int $id, FoodbookService $svc): void
    {
        $this->verschiebeBlock($id, 1, $svc);
    }

    private function verschiebeBlock(int $id, int $richtung, FoodbookService $svc): void
    {
        if ($this->selectedKapitelId === null) {
            return;
        }
        $ids = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::where('kapitel_id', $this->selectedKapitelId)
            ->orderBy('position')->pluck('id')->all();
        $pos = array_search($id, $ids, true);
        $ziel = $pos + $richtung;
        if ($pos === false || $ziel < 0 || $ziel >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$ziel]] = [$ids[$ziel], $ids[$pos]];
        $svc->reorderBlocks($this->team(), $this->selectedKapitelId, $ids);
    }

    // ── Wahl-Gruppe (A|B|C zwischen Concepts) ───────────────────────────────

    public function markiere(int $id): void
    {
        $this->markiert = in_array($id, $this->markiert, true)
            ? array_values(array_diff($this->markiert, [$id]))
            : [...$this->markiert, $id];
    }

    public function wahlGruppeBilden(FoodbookService $svc): void
    {
        if (count($this->markiert) < 2 || $this->selectedKapitelId === null) {
            return;
        }
        $gid = $svc->nextVariantGroupId($this->team(), $this->selectedKapitelId);
        $svc->setVariantGroup($this->team(), $this->markiert, $gid);
        $this->markiert = [];
    }

    public function wahlGruppeAufheben(int $id, FoodbookService $svc): void
    {
        $svc->setVariantGroup($this->team(), [$id], null);
    }

    public function render(FoodbookService $svc)
    {
        $team = $this->team();
        $fb = $this->selectedId !== null ? $svc->detail($team, $this->selectedId) : null;
        $kapitel = $fb !== null && $this->selectedKapitelId !== null
            ? $fb->kapitel->firstWhere('id', $this->selectedKapitelId) : null;

        // #389/Canvas: Foodbook-Leitidee-Canvas nur bei Selektions-WECHSEL (re)laden — kein Edit-Verlust je Roundtrip.
        if ($fb !== null && $this->canvasOwnerId !== $fb->id) {
            $this->canvasInit('foodbook', 'foodbook', $fb->id);
        }

        return view('foodalchemist::livewire.foodbooks.index', [
            'foodbooks' => $svc->paginateBrowser(['search' => $this->search], $team),
            'fb' => $fb,
            'kapitelTree' => $fb !== null ? $svc->kapitelTree($team, $fb->id) : [],
            'kapitel' => $kapitel,
            'kapitelAgg' => $kapitel !== null ? $svc->kapitelAggregat($team, $kapitel, $fb?->personen) : null,
            'gesamt' => $fb !== null ? $svc->gesamt($team, $fb) : null,
            'headerPresets' => FoodbookService::headerPresets(),
            'conceptKategorien' => app(\Platform\FoodAlchemist\Services\ConceptService::class)->categoriesFlat($team),
            'conceptKandidaten' => ($this->conceptSuche !== '' || $this->conceptKategorie !== null) && $this->selectedKapitelId !== null
                ? $svc->conceptKandidaten($team, $this->conceptSuche, $this->conceptKategorie, 50) : collect(),
            // #369: CRM-Kunde-Picker
            'crmVerfuegbar' => $svc->crmVerfuegbar(),
            'firmen' => $svc->sucheFirmen($this->firmaSuche),
            'kontakte' => $svc->sucheKontakte($this->kontaktSuche),
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
