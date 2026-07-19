<?php

namespace Platform\FoodAlchemist\Livewire\Suppliers;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\SupplierAgreementService;
use Platform\FoodAlchemist\Services\SupplierService;
use RuntimeException;

/**
 * R9.1/R9.2 UI-Slice: Lieferanten-Stammblatt als getabtes Modal — Oberfläche der
 * bereits gebauten Beziehungs-Engine (SupplierService + SupplierAgreementService).
 * Tabs: Stammblatt (Status · Kontakte · WG-Abdeckung) · Konditionen · Absprachen ·
 * Dokumente (Fristen) · Bündelung (Volumen-Proxy × Konditionen, R9.2 E6).
 *
 * Lesend für die Team-Kette (geerbte Lieferanten sichtbar), Schreiben nur fürs
 * Besitzer-Team (D1) — Services werfen, wir fangen in $fehler. Wie item-modal:
 * eigene Livewire-Komponente, per Event geöffnet, State-Reset beim Schließen.
 */
class SupplierDetail extends Component
{
    public ?int $supplierId = null;

    public string $status = 'aktiv';

    /** R9.1 (E4) Konditionen — vorbelegt beim Öffnen, gespeichert per updateConditions. */
    public array $konditionen = ['rebate_pct' => '', 'payment_term_days' => '', 'min_order_value' => '', 'free_shipping_threshold' => ''];

    public array $neuKontakt = ['name' => '', 'role' => '', 'phone' => '', 'email' => ''];

    public array $neueAbsprache = ['type' => 'absprache', 'note' => '', 'valid_from' => '', 'valid_to' => '', 'follow_up_at' => ''];

    public array $neuesDokument = ['kind' => 'vertrag', 'title' => '', 'file_ref' => '', 'term_start' => '', 'term_end' => '', 'notice_period_days' => ''];

    public ?string $fehler = null;

    public ?string $hinweis = null;

    #[On('supplier-detail.oeffnen')]
    public function oeffnen(int $id): void
    {
        $this->resetState();
        $team = $this->team();
        $sb = app(SupplierService::class)->stammblatt($team, $id);
        $this->supplierId = $id;
        $this->status = $sb['status'];
        $this->konditionen = [
            'rebate_pct' => $sb['konditionen']['rebate_pct'] ?? '',
            'payment_term_days' => $sb['konditionen']['payment_term_days'] ?? '',
            'min_order_value' => $sb['konditionen']['min_order_value'] ?? '',
            'free_shipping_threshold' => $sb['konditionen']['free_shipping_threshold'] ?? '',
        ];
        $this->dispatch('modal.open', name: 'supplier-detail');
    }

    #[On('modal.closed')]
    public function geschlossen(array $payload = []): void
    {
        if (($payload['name'] ?? null) === 'supplier-detail') {
            $this->resetState();
            $this->supplierId = null;
        }
    }

    public function statusSetzen(): void
    {
        $this->fuehreAus(fn ($svc, $team) => $svc->setStatus($team, $this->supplierId, $this->status),
            'Status gesetzt.');
    }

    public function konditionenSpeichern(): void
    {
        $this->fuehreAus(fn ($svc, $team) => $svc->updateConditions($team, $this->supplierId, $this->konditionen),
            'Konditionen gespeichert.');
    }

    public function kontaktAnlegen(): void
    {
        if ($this->fuehreAus(fn ($svc, $team) => $svc->addContact($team, $this->supplierId, $this->neuKontakt),
            'Ansprechpartner hinzugefügt.')) {
            $this->neuKontakt = ['name' => '', 'role' => '', 'phone' => '', 'email' => ''];
        }
    }

    public function abspracheAnlegen(): void
    {
        $team = $this->team();
        try {
            app(SupplierAgreementService::class)->create($team, $this->supplierId, $this->leereZuNull($this->neueAbsprache), Auth::id());
            $this->neueAbsprache = ['type' => 'absprache', 'note' => '', 'valid_from' => '', 'valid_to' => '', 'follow_up_at' => ''];
            $this->melde('Absprache erfasst.');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function dokumentAnlegen(): void
    {
        $team = $this->team();
        try {
            app(SupplierAgreementService::class)->addDocument($team, $this->supplierId, $this->leereZuNull($this->neuesDokument));
            $this->neuesDokument = ['kind' => 'vertrag', 'title' => '', 'file_ref' => '', 'term_start' => '', 'term_end' => '', 'notice_period_days' => ''];
            $this->melde('Dokument erfasst.');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(SupplierService $suppliers)
    {
        $team = Auth::user()?->currentTeamRelation;
        $stammblatt = ($this->supplierId !== null && $team !== null)
            ? $suppliers->stammblatt($team, $this->supplierId)
            : null;

        return view('foodalchemist::livewire.suppliers.supplier-detail', [
            'stammblatt' => $stammblatt,
            'darfEdit' => (bool) ($stammblatt['is_owned'] ?? false),
            // R9.2 (E6): Bündelungs-Ranking über alle sichtbaren Lieferanten (Nutzungs-Proxy, ehrlich markiert).
            'buendelung' => ($this->supplierId !== null && $team !== null)
                ? $suppliers->volumenProxyRanking($team)
                : [],
            'heute' => now()->startOfDay(),
        ]);
    }

    /** Schreib-Aktion mit D1-Fehlerfang; gibt true bei Erfolg. */
    private function fuehreAus(\Closure $aktion, string $erfolg): bool
    {
        $this->fehler = null;
        $team = $this->team();
        try {
            $aktion(app(SupplierService::class), $team);
            $this->melde($erfolg);

            return true;
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return false;
        }
    }

    private function melde(string $text): void
    {
        $this->hinweis = $text;
        $this->fehler = null;
    }

    /** Leerstrings zu null (Datums-/Zahlenfelder), damit optionale Angaben nicht als '' landen. */
    private function leereZuNull(array $input): array
    {
        return collect($input)->map(fn ($v) => $v === '' ? null : $v)->all();
    }

    private function resetState(): void
    {
        $this->reset('status', 'konditionen', 'neuKontakt', 'neueAbsprache', 'neuesDokument', 'fehler', 'hinweis');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
