<?php

namespace Platform\FoodAlchemist\Livewire\Suppliers;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\RebateService;
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

    /** Einkauf E1: Rückvergütungs-Staffel (Zeilen threshold_eur/percent) + Config + Live-Info. */
    public array $staffel = [];

    public array $rebateConfig = ['active' => true, 'assumed_annual_revenue' => '', 'selected_threshold' => '', 'excluded' => ''];

    public array $stufenInfo = [];

    /** Spec 17/S1 Bestell-Logistik: Liefertage (ISO-Wochentage) + Bestellschluss/Vorlaufzeit. */
    public array $liefertage = [];

    public array $bestellung = ['order_cutoff_time' => '', 'order_lead_days' => ''];

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
        $tage = $sb['bestellung']['delivery_days'] ?? null;
        $this->liefertage = $tage ? array_map('intval', array_filter(explode(',', (string) $tage), 'strlen')) : [];
        $this->bestellung = [
            'order_cutoff_time' => $sb['bestellung']['order_cutoff_time'] ?? '',
            'order_lead_days' => $sb['bestellung']['order_lead_days'] ?? '',
        ];
        $this->ladeRueckverguetung($id);
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

    /** Einkauf E1: Staffel + Config eines Lieferanten in den Editor-State laden. */
    private function ladeRueckverguetung(int $supplierId): void
    {
        $rebate = app(RebateService::class);
        $team = $this->team();
        $this->staffel = $rebate->tiersFor($team, $supplierId)->map(fn ($t) => [
            'threshold_eur' => (float) $t->threshold_eur,
            'percent' => (float) $t->percent,
        ])->all();
        $config = $rebate->configFor($team, $supplierId);
        $selThreshold = '';
        if ($config?->selected_tier_id !== null && $config !== null) {
            $sel = $rebate->tiersFor($team, $supplierId)->firstWhere('id', $config->selected_tier_id);
            $selThreshold = $sel !== null ? (string) (float) $sel->threshold_eur : '';
        }
        $this->rebateConfig = [
            'active' => $config?->active ?? true,
            'assumed_annual_revenue' => $config?->assumed_annual_revenue !== null ? (string) (float) $config->assumed_annual_revenue : '',
            'selected_threshold' => $selThreshold,
            'excluded' => is_array($config?->excluded_commodity_groups) ? implode(', ', $config->excluded_commodity_groups) : '',
        ];
        $this->stufenInfo = $rebate->stufenInfo($team, $supplierId);
    }

    public function staffelZeileHinzufuegen(): void
    {
        $this->staffel[] = ['threshold_eur' => '', 'percent' => ''];
    }

    public function staffelZeileEntfernen(int $i): void
    {
        unset($this->staffel[$i]);
        $this->staffel = array_values($this->staffel);
    }

    /** Einkauf E1: Staffel (Replace-Set) + Config speichern; manuelle Stufe per Schwelle (ID-Churn-fest). */
    public function rueckverguetungSpeichern(): void
    {
        $this->fehler = null;
        $team = $this->team();
        try {
            $rebate = app(RebateService::class);
            $created = $rebate->saveTiers($team, $this->supplierId, array_map(
                fn ($z) => ['threshold_eur' => $z['threshold_eur'] ?? 0, 'percent' => $z['percent'] ?? 0],
                $this->staffel
            ));

            $selThreshold = (string) ($this->rebateConfig['selected_threshold'] ?? '');
            $selTierId = null;
            if ($selThreshold !== '') {
                $selTierId = optional($created->first(
                    fn ($t) => abs((float) $t->threshold_eur - (float) $selThreshold) < 0.005
                ))->id;
            }

            $excluded = array_values(array_filter(array_map('trim',
                explode(',', (string) ($this->rebateConfig['excluded'] ?? '')))));

            $rebate->saveConfig($team, $this->supplierId, [
                'active' => (bool) ($this->rebateConfig['active'] ?? true),
                'assumed_annual_revenue' => $this->rebateConfig['assumed_annual_revenue'] ?? '',
                'selected_tier_id' => $selTierId,
                'excluded_commodity_groups' => $excluded,
            ]);

            $this->ladeRueckverguetung($this->supplierId);
            $this->melde('Rückvergütungs-Staffel gespeichert.');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Spec 17/S1: Bestell-Logistik speichern — Liefertage (ISO-CSV) + Bestellschluss/Vorlaufzeit. */
    public function bestellungSpeichern(): void
    {
        $tage = array_values(array_unique(array_map('intval', $this->liefertage)));
        sort($tage);
        $input = [
            'delivery_days' => $tage === [] ? '' : implode(',', $tage),
            'order_cutoff_time' => $this->bestellung['order_cutoff_time'] ?? '',
            'order_lead_days' => $this->bestellung['order_lead_days'] ?? '',
        ];
        $this->fuehreAus(fn ($svc, $team) => $svc->updateConditions($team, $this->supplierId, $input),
            'Bestell-Logistik gespeichert.');
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
        $this->reset('status', 'konditionen', 'staffel', 'rebateConfig', 'stufenInfo', 'liefertage', 'bestellung', 'neuKontakt', 'neueAbsprache', 'neuesDokument', 'fehler', 'hinweis');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
