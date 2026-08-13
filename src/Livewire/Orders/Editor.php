<?php

namespace Platform\FoodAlchemist\Livewire\Orders;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Support\Suche;

/**
 * Bestellungen-Editor (Fullscreen-Modal, pro Bestellschiene) — herausgezogen aus dem
 * bisherigen 3-Panel-Cockpit (Orders\Index). Tabs: Positionen · Hinzufügen (Direktbestellung:
 * Bedarf/Artikel) · Kopf, Status & Versand. Geöffnet per `orders-editor.bearbeiten` {id};
 * meldet Änderungen per `orders-geaendert` an den Browser (Liste). Schreiben durch den
 * D1-gescopten OrderService (isOwnedBy + Status-Guard); nur `draft` ist editierbar.
 */
class Editor extends Component
{
    public ?int $orderId = null;

    // Kopf-Edit-Form.
    public string $formReference = '';

    public string $formDeliveryDate = '';

    public string $formNote = '';

    public string $formSupplierOrderNumber = '';

    public string $formConfirmedDeliveryDate = '';

    public string $formSupplierConfirmationNote = '';

    public string $formInvoiceNumber = '';

    public string $formInvoiceDate = '';

    public string $formInvoiceNote = '';

    public string $formPaymentStatus = '';

    public string $formInvoicePaidAt = '';

    public string $formPaymentNote = '';

    public string $formApprovalStatus = '';

    public string $formApprovalNote = '';

    public ?string $hinweis = null;

    public ?string $fehler = null;

    // Direktbestellung (in den Editor gezogen).
    /** „＋ Artikel": globale LA-Livesearch. */
    public string $artikelSuche = '';

    /** Bedarf-Schnellerfassung: Gericht/Basisrezept → addNeedFromTarget. */
    public string $bedarfSuche = '';

    /** Grundprodukt-Suche fürs neue Bestellcockpit. */
    public string $gpSuche = '';

    /** Produktion-Suche fürs neue Bestellcockpit. */
    public string $produktionSuche = '';

    public ?int $bedarfRecipeId = null;

    public string $bedarfRecipeName = '';

    public bool $bedarfRecipeVk = true;

    /** portions (VK) | ansaetze | kg. */
    public string $bedarfEinheit = 'portions';

    public string $bedarfMenge = '';

    // Preisstrategie-Switch + „Neu quellen".
    public string $formStrategy = '';

    /** Vorschau der Wechsel aus resourceOrder(apply=false); null = kein Dialog offen. */
    public ?array $resourceVorschau = null;

    /** Neues Bestellcockpit: Quellen erst sammeln, dann auflösen/speichern. */
    public array $cockpitSources = [];

    public ?array $cockpitPreview = null;

    public string $cockpitStrategy = '';

    /** override_key => lead_la_id für einzelne Cockpit-Zutaten vor dem Speichern. */
    public array $cockpitOverrides = [];

    /** Zeile, deren Ausweichquellen-Dropdown offen ist (nur eine gleichzeitig). */
    public ?int $altLineId = null;

    /** Vorschau-Zutat, deren Ausweichquellen-Dropdown offen ist. */
    public ?string $cockpitAltKey = null;

    public array $cockpitAlternativen = [];

    #[On('orders-editor.bearbeiten')]
    public function oeffnenBearbeiten(int $id): void
    {
        $this->orderId = $id;
        $this->hinweis = null;
        $this->fehler = null;
        $this->resourceVorschau = null;
        $this->altLineId = null;
        $this->artikelSuche = '';
        $this->gpSuche = '';
        $this->produktionSuche = '';
        $this->cockpitReset();
        $this->bedarfRezeptZuruecksetzen();
        $this->ladeKopf();
        $this->dispatch('modal.open', name: 'orders-editor');
    }

    #[On('orders-editor.neu')]
    public function oeffnenNeu(?string $deliveryDate = null, ?string $strategy = null): void
    {
        $this->orderId = null;
        $this->hinweis = null;
        $this->fehler = null;
        $this->resourceVorschau = null;
        $this->altLineId = null;
        $this->artikelSuche = '';
        $this->gpSuche = '';
        $this->produktionSuche = '';
        $this->cockpitReset();
        $this->bedarfRezeptZuruecksetzen();
        $this->formReference = '';
        $this->formDeliveryDate = $deliveryDate ?: '';
        $this->formNote = '';
        $this->formSupplierOrderNumber = '';
        $this->formConfirmedDeliveryDate = '';
        $this->formSupplierConfirmationNote = '';
        $this->formInvoiceNumber = '';
        $this->formInvoiceDate = '';
        $this->formInvoiceNote = '';
        $this->formPaymentStatus = '';
        $this->formInvoicePaidAt = '';
        $this->formPaymentNote = '';
        $this->formApprovalStatus = '';
        $this->formApprovalNote = '';
        $this->formStrategy = (string) ($strategy ?? '');
        $this->cockpitStrategy = (string) ($strategy ?? '');
        $this->dispatch('modal.open', name: 'orders-editor');
    }

    /** Kopf-Felder des aktiven Belegs in die Form spiegeln. */
    private function ladeKopf(): void
    {
        $this->formReference = '';
        $this->formDeliveryDate = '';
        $this->formNote = '';
        $this->formSupplierOrderNumber = '';
        $this->formConfirmedDeliveryDate = '';
        $this->formSupplierConfirmationNote = '';
        $this->formInvoiceNumber = '';
        $this->formInvoiceDate = '';
        $this->formInvoiceNote = '';
        $this->formPaymentStatus = '';
        $this->formInvoicePaidAt = '';
        $this->formPaymentNote = '';
        $this->formApprovalStatus = '';
        $this->formApprovalNote = '';
        $this->formStrategy = '';
        if ($this->orderId === null) {
            return;
        }
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $d = app(OrderService::class)->detail($team, $this->orderId);
            $this->formReference = (string) ($d['reference'] ?? '');
            $this->formDeliveryDate = (string) ($d['desired_delivery_date'] ?? '');
            $this->formNote = (string) ($d['note'] ?? '');
            $this->formSupplierOrderNumber = (string) ($d['supplier_order_number'] ?? '');
            $this->formConfirmedDeliveryDate = (string) ($d['confirmed_delivery_date'] ?? '');
            $this->formSupplierConfirmationNote = (string) ($d['supplier_confirmation_note'] ?? '');
            $this->formInvoiceNumber = (string) ($d['invoice_number'] ?? '');
            $this->formInvoiceDate = (string) ($d['invoice_date'] ?? '');
            $this->formInvoiceNote = (string) ($d['invoice_note'] ?? '');
            $this->formPaymentStatus = (string) ($d['payment_status'] ?? '');
            $this->formInvoicePaidAt = (string) ($d['invoice_paid_at'] ?? '');
            $this->formPaymentNote = (string) ($d['payment_note'] ?? '');
            $this->formApprovalStatus = (string) ($d['approval_status'] ?? '');
            $this->formApprovalNote = (string) ($d['approval_note'] ?? '');
            $this->formStrategy = (string) ($d['sourcing_strategy'] ?? '');
        } catch (\Throwable) {
            // Detail wird in render() defensiv behandelt.
        }
    }

    public function saveHeader(OrderService $orders): void
    {
        if ($this->orderId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $orders->updateHeader($team, $this->orderId, [
            'reference' => $this->formReference,
            'desired_delivery_date' => $this->formDeliveryDate,
            'note' => $this->formNote,
        ]), 'Kopf gespeichert.');
    }

    public function setStatus(string $status, OrderService $orders): void
    {
        $ziel = OrderStatus::tryFrom($status);
        if ($ziel === null || $this->orderId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $orders->setStatus($team, $this->orderId, $ziel), 'Status gesetzt.');
    }

    public function saveSupplierConfirmation(OrderService $orders): void
    {
        if ($this->orderId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $orders->updateSupplierConfirmation($team, $this->orderId, [
            'supplier_order_number' => $this->formSupplierOrderNumber,
            'confirmed_delivery_date' => $this->formConfirmedDeliveryDate,
            'supplier_confirmation_note' => $this->formSupplierConfirmationNote,
        ]), 'Lieferantenbestätigung gespeichert.');
    }

    public function saveInvoiceHeader(OrderService $orders): void
    {
        if ($this->orderId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $orders->updateInvoiceHeader($team, $this->orderId, [
            'invoice_number' => $this->formInvoiceNumber,
            'invoice_date' => $this->formInvoiceDate,
            'invoice_note' => $this->formInvoiceNote,
        ]), 'Rechnungskopf gespeichert.');
    }

    public function savePayment(OrderService $orders): void
    {
        if ($this->orderId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $orders->updatePayment($team, $this->orderId, [
            'payment_status' => $this->formPaymentStatus,
            'invoice_paid_at' => $this->formInvoicePaidAt,
            'payment_note' => $this->formPaymentNote,
        ]), 'Zahlungsstatus gespeichert.');
    }

    public function saveApproval(OrderService $orders): void
    {
        if ($this->orderId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $orders->updateApproval($team, $this->orderId, [
            'approval_status' => $this->formApprovalStatus,
            'approval_note' => $this->formApprovalNote,
        ], Auth::id()), 'Freigabe gespeichert.');
    }

    public function updateLineQty(int $lineId, $qty, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateLine($team, $lineId, ['qty_packs' => $qty]), 'Menge angepasst.');
    }

    public function resetLineQty(int $lineId, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateLine($team, $lineId, ['reset_qty' => true]), 'Auto-Menge wiederhergestellt.');
    }

    public function updateLineNote(int $lineId, $note, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateLine($team, $lineId, ['note' => $note]), 'Notiz gespeichert.');
    }

    public function updateReceiptLine(int $lineId, $qty, ?string $note, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateReceiptLine($team, $lineId, $qty, $note), 'Wareneingang gebucht.');
    }

    public function updateReceiptNote(int $lineId, ?string $note, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateReceiptNote($team, $lineId, $note), 'Wareneingangsnotiz gespeichert.');
    }

    public function completeReceipt(OrderService $orders): void
    {
        if ($this->orderId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $orders->completeReceipt($team, $this->orderId), 'Wareneingang vollständig übernommen.');
    }

    public function createBackorder(OrderService $orders): void
    {
        if ($this->orderId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $orders->createBackorderFromReceipt($team, $this->orderId, null, Auth::id()), 'Nachlieferung als Entwurf angelegt.');
    }

    public function updateInvoiceLine(int $lineId, $qty, $price, ?string $note, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateInvoiceLine($team, $lineId, $qty, $price, $note), 'Rechnungszeile geprüft.');
    }

    public function updateInvoiceNote(int $lineId, ?string $note, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateInvoiceNote($team, $lineId, $note), 'Rechnungsnotiz gespeichert.');
    }

    public function updateClaimLine(int $lineId, string $status, $qty, $credit, ?string $note, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateClaimLine($team, $lineId, [
            'claim_status' => $status,
            'claim_qty_packs' => $qty,
            'credit_expected_net' => $credit,
            'claim_note' => $note,
        ]), 'Reklamation gespeichert.');
    }

    public function updateClaimStatus(int $lineId, string $status, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateClaimLine($team, $lineId, ['claim_status' => $status]), 'Reklamation gespeichert.');
    }

    public function updateClaimQty(int $lineId, $qty, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateClaimLine($team, $lineId, ['claim_qty_packs' => $qty]), 'Reklamationsmenge gespeichert.');
    }

    public function updateClaimCredit(int $lineId, $credit, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateClaimLine($team, $lineId, ['credit_expected_net' => $credit]), 'Gutschrift gespeichert.');
    }

    public function updateClaimNote(int $lineId, ?string $note, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateClaimLine($team, $lineId, ['claim_note' => $note]), 'Reklamationsnotiz gespeichert.');
    }

    public function completeInvoiceFromReceipt(OrderService $orders): void
    {
        if ($this->orderId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $orders->completeInvoiceFromReceipt($team, $this->orderId), 'Rechnung aus Wareneingang übernommen.');
    }

    public function removeLine(int $lineId, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->removeLine($team, $lineId), 'Position entfernt.');
    }

    /** Ausweichquellen-Dropdown einer Zeile auf-/zuklappen (nur eine gleichzeitig). */
    public function alternativenUmschalten(int $lineId): void
    {
        $this->altLineId = $this->altLineId === $lineId ? null : $lineId;
    }

    /** Zeile auf einen Ausweich-LA umstellen (gleicher Lieferant = Artikel-Tausch, sonst Schienen-Wechsel). */
    public function alternativeWaehlen(int $lineId, int $laId, OrderService $orders): void
    {
        $this->fuehreAus(function ($team) use ($orders, $lineId, $laId) {
            $res = $orders->switchLineArticle($team, $lineId, $laId, Auth::id());
            $this->altLineId = null;
            // Bei Schienen-Wechsel folgt der Editor der Zeile in ihre neue Schiene.
            if ($res['schiene_wechsel'] && $res['target_order_id'] !== null) {
                $this->orderId = (int) $res['target_order_id'];
                $this->ladeKopf();
            }
        }, 'Artikel umgestellt.');
    }

    /** Vorschau: welche Zeilen wechseln unter der gewählten Strategie? (nichts wird persistiert) */
    public function neuQuellenVorschau(OrderService $orders): void
    {
        if ($this->orderId === null) {
            return;
        }
        $this->hinweis = null;
        $this->fehler = null;
        $this->resourceVorschau = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $this->resourceVorschau = $orders->resourceOrder($team, $this->orderId, $this->strategieAusForm(), false, Auth::id());
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function neuQuellenAnwenden(OrderService $orders): void
    {
        if ($this->orderId === null) {
            return;
        }
        $this->fuehreAus(function ($team) use ($orders) {
            $orders->resourceOrder($team, $this->orderId, $this->strategieAusForm(), true, Auth::id());
            $this->resourceVorschau = null;
            $this->ladeKopf();
        }, 'Neu gequellt.');
    }

    public function neuQuellenAbbrechen(): void
    {
        $this->resourceVorschau = null;
    }

    private function strategieAusForm(): ?LeadLaStrategie
    {
        return $this->formStrategy !== '' ? LeadLaStrategie::tryFrom($this->formStrategy) : null;
    }

    private function cockpitStrategieAusForm(): ?LeadLaStrategie
    {
        return $this->cockpitStrategy !== '' ? LeadLaStrategie::tryFrom($this->cockpitStrategy) : null;
    }

    private function fuehreAus(callable $fn, string $ok): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $fn($team);
            $this->hinweis = $ok;
            $this->dispatch('orders-geaendert');
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    // ── Direktbestellung im Editor (Bedarf/Artikel hinzufügen) ────────────────

    private function cockpitReset(): void
    {
        $this->cockpitSources = [];
        $this->cockpitPreview = null;
        $this->cockpitStrategy = '';
        $this->cockpitOverrides = [];
        $this->cockpitAlternativenSchliessen();
    }

    private function cockpitAlternativenSchliessen(): void
    {
        $this->cockpitAltKey = null;
        $this->cockpitAlternativen = [];
    }

    public function cockpitArtikelEinfuegen(int $supplierItemId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $la = $team ? FoodAlchemistSupplierItem::visibleToTeam($team)->with('supplier:id,name')->find($supplierItemId) : null;
        if ($la === null) {
            return;
        }
        $this->cockpitSources[] = [
            'type' => 'supplier_item',
            'id' => (int) $la->id,
            'label' => trim(($la->designation ?: 'Artikel #' . $la->id) . ($la->supplier?->name ? ' · ' . $la->supplier->name : '')),
            'qty' => 1,
            'unit' => 'gebinde',
            'delivery_date' => $this->formDeliveryDate ?: null,
            'reference' => $this->formReference ?: null,
        ];
        $this->artikelSuche = '';
        $this->cockpitPreview = null;
        $this->cockpitOverrides = [];
        $this->cockpitAlternativenSchliessen();
    }

    public function cockpitAlternativenUmschalten(string $key, int $gpId, ?int $supplierId, ?int $leadLaId, OrderService $orders): void
    {
        if ($this->cockpitAltKey === $key) {
            $this->cockpitAltKey = null;
            $this->cockpitAlternativen = [];

            return;
        }

        $this->cockpitAltKey = $key;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $this->cockpitAlternativen = $orders->gpAlternativen($team, $gpId, $supplierId, $leadLaId);
        } catch (\Throwable $e) {
            $this->cockpitAltKey = null;
            $this->cockpitAlternativen = [];
            $this->fehler = $e->getMessage();
        }
    }

    public function cockpitGpEinfuegen(int $gpId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $gp = $team ? FoodAlchemistGp::visibleToTeam($team)->find($gpId) : null;
        if ($gp === null) {
            return;
        }
        $this->cockpitSources[] = [
            'type' => 'gp',
            'id' => (int) $gp->id,
            'label' => $gp->name,
            'qty' => 1,
            'unit' => 'kg',
            'delivery_date' => $this->formDeliveryDate ?: null,
            'reference' => $this->formReference ?: null,
        ];
        $this->gpSuche = '';
        $this->cockpitPreview = null;
        $this->cockpitOverrides = [];
        $this->cockpitAlternativenSchliessen();
    }

    public function cockpitRezeptEinfuegen(int $recipeId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $recipe = $team ? FoodAlchemistRecipe::visibleToTeam($team)->find($recipeId) : null;
        if ($recipe === null) {
            return;
        }
        $this->cockpitSources[] = [
            'type' => 'recipe',
            'id' => (int) $recipe->id,
            'label' => $recipe->name,
            'qty' => 1,
            'unit' => $recipe->is_sales_recipe ? 'portions' : 'ansaetze',
            'delivery_date' => $this->formDeliveryDate ?: null,
            'reference' => $this->formReference ?: null,
        ];
        $this->bedarfSuche = '';
        $this->bedarfRecipeId = null;
        $this->cockpitPreview = null;
        $this->cockpitOverrides = [];
        $this->cockpitAlternativenSchliessen();
    }

    public function cockpitProduktionEinfuegen(int $productionOrderId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $production = $team ? FoodAlchemistProductionOrder::visibleToTeam($team)->find($productionOrderId) : null;
        if ($production === null) {
            return;
        }
        $this->cockpitSources[] = [
            'type' => 'production',
            'id' => (int) $production->id,
            'label' => $production->name ?: ('Produktion #' . $production->id),
            'qty' => 1,
            'unit' => 'auftrag',
            'delivery_date' => $this->formDeliveryDate ?: $production->production_date?->toDateString(),
            'reference' => $this->formReference ?: ($production->name ?: null),
        ];
        $this->produktionSuche = '';
        $this->cockpitPreview = null;
        $this->cockpitOverrides = [];
        $this->cockpitAlternativenSchliessen();
    }

    public function cockpitQuelleEntfernen(int $index): void
    {
        if (! array_key_exists($index, $this->cockpitSources)) {
            return;
        }
        array_splice($this->cockpitSources, $index, 1);
        $this->cockpitPreview = null;
        $this->cockpitOverrides = [];
        $this->cockpitAlternativenSchliessen();
    }

    public function cockpitVorschau(OrderService $orders): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        $this->cockpitAlternativenSchliessen();
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $this->cockpitPreview = $orders->previewFromSources($team, $this->cockpitSources, $this->cockpitStrategieAusForm(), $this->cockpitOverrides);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function cockpitAlternativeWaehlen(string $key, int $leadLaId, OrderService $orders): void
    {
        $this->cockpitOverrides[$key] = $leadLaId;
        $this->cockpitVorschau($orders);
        $this->hinweis = 'Alternative in der Vorschau gesetzt.';
    }

    public function cockpitAlternativeZuruecksetzen(string $key, OrderService $orders): void
    {
        unset($this->cockpitOverrides[$key]);
        $this->cockpitVorschau($orders);
        $this->hinweis = 'Automatische Quelle wiederhergestellt.';
    }

    public function cockpitSpeichern(OrderService $orders): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $res = $orders->generateDraftsFromSources($team, $this->cockpitSources, $this->cockpitStrategieAusForm(), Auth::id(), $this->cockpitOverrides);
            $this->cockpitPreview = $res['preview'] ?? null;
            if (! empty($res['orders'])) {
                $this->orderId = (int) $res['orders'][0];
                $this->ladeKopf();
            }
            $this->hinweis = count($res['orders'] ?? []) . ' Bestellschiene(n) gespeichert'
                . (count($res['unresolved'] ?? []) > 0 ? ' · ' . count($res['unresolved']) . ' Klärpunkt(e)' : '') . '.';
            $this->dispatch('orders-geaendert');
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** „＋ Artikel": manuelle Zeile (Menge 1) an die Draft-Schiene des Lieferanten hängen. */
    public function artikelHinzufuegen(int $supplierItemId, OrderService $orders): void
    {
        $this->fuehreAus(function ($team) use ($orders, $supplierItemId) {
            $line = $orders->addManualLine($team, $supplierItemId, 1.0, null, Auth::id(), $this->formDeliveryDate ?: null);
            $this->orderId = (int) $line->order_id;
            $this->kopfNachStartSpeichern($orders, [$this->orderId]);
            $this->ladeKopf();
        }, 'Artikel hinzugefügt.');
        $this->artikelSuche = '';
    }

    public function bedarfRezeptWaehlen(int $recipeId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $r = $team ? FoodAlchemistRecipe::visibleToTeam($team)->find($recipeId) : null;
        if ($r === null) {
            return;
        }
        $this->bedarfRecipeId = (int) $r->id;
        $this->bedarfRecipeName = (string) $r->name;
        $this->bedarfRecipeVk = (bool) $r->is_sales_recipe;
        $this->bedarfEinheit = $this->bedarfRecipeVk ? 'portions' : 'ansaetze';
        $this->bedarfSuche = '';
    }

    public function bedarfRezeptZuruecksetzen(): void
    {
        $this->bedarfRecipeId = null;
        $this->bedarfRecipeName = '';
        $this->bedarfMenge = '';
        $this->bedarfSuche = '';
    }

    /**
     * Bedarf des gewählten Ziels in die Lieferanten-Schienen übernehmen. Cross-order: verteilt
     * je Zutat auf die Lead-LA-Schienen (kann fremde Schienen anlegen/berühren). Der Editor
     * folgt der ersten betroffenen Schiene; Hinweis nennt die Anzahl.
     */
    public function bedarfUebernehmen(OrderService $orders): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        if ($this->bedarfRecipeId === null) {
            $this->fehler = 'Erst ein Gericht/Basisrezept wählen.';

            return;
        }
        $menge = (float) str_replace(',', '.', trim($this->bedarfMenge));
        if ($menge <= 0) {
            $this->fehler = 'Menge größer 0 angeben.';

            return;
        }
        $ziel = ['recipe_id' => $this->bedarfRecipeId];
        if (! $this->bedarfRecipeVk && $this->bedarfEinheit === 'kg') {
            $ziel['amount_kg'] = $menge;
        } else {
            $ziel['portions'] = $menge;
        }
        $sourceRef = 'recipe:' . $this->bedarfRecipeId . '@' . uniqid();

        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $res = $orders->addNeedFromTarget($team, $ziel, $sourceRef, Auth::id(), null, $this->formDeliveryDate ?: null);
            $this->kopfNachStartSpeichern($orders, array_map('intval', $res['orders'] ?? []));
            if (! empty($res['orders'])) {
                $this->orderId = (int) $res['orders'][0];
                $this->ladeKopf();
            }
            if (empty($res['orders']) && empty($res['skipped_ohne_la'])) {
                $this->fehler = 'Kein bestellbarer Bedarf (Rezept ohne Zutaten mit Lead-LA?).';

                return;
            }
            $teile = [count($res['orders']) . ' Schiene(n) aktualisiert'];
            if (! empty($res['skipped_ohne_la'])) {
                $teile[] = 'ohne Lead-LA übersprungen: ' . implode(', ', $res['skipped_ohne_la']);
            }
            $this->hinweis = 'Bedarf übernommen — ' . implode(' · ', $teile) . '.';
            $this->bedarfRezeptZuruecksetzen();
            $this->dispatch('orders-geaendert');
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Beim neutralen Start auf alle gerade erzeugten Lieferanten-Schienen stempeln. */
    private function kopfNachStartSpeichern(OrderService $orders, array $orderIds): void
    {
        $kopf = [
            'reference' => $this->formReference,
            'note' => $this->formNote,
        ];
        if (trim($kopf['reference']) === '' && trim($kopf['note']) === '') {
            return;
        }

        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        foreach (array_unique($orderIds) as $id) {
            if ((int) $id > 0) {
                $orders->updateHeader($team, (int) $id, $kopf);
            }
        }
    }

    public function render(OrderService $orders)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        $detail = null;
        $erlaubteStatus = [];
        $mailto = null;
        if ($this->orderId !== null) {
            try {
                $detail = $orders->detail($team, $this->orderId);
                $aktuell = OrderStatus::from($detail['status']);
                foreach ([OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered, OrderStatus::Cancelled] as $z) {
                    if ($aktuell->darfWechselnZu($z)) {
                        $erlaubteStatus[] = $z;
                    }
                }
                $m = $orders->mailtoData($team, $this->orderId);
                if (($m['to'] ?? '') !== '') {
                    $mailto = 'mailto:' . $m['to'] . '?subject=' . rawurlencode($m['subject']) . '&body=' . rawurlencode($m['body']);
                }
            } catch (\Throwable) {
                $this->orderId = null;
            }
        }

        // Direktbestellung: Artikel-Livesearch + Bedarf-Rezept-Livesearch.
        $artikelTreffer = collect();
        $aq = trim($this->artikelSuche);
        if (mb_strlen($aq) >= 2) {
            $q = FoodAlchemistSupplierItem::visibleToTeam($team)
                ->where('is_discontinued', false)
                ->whereNotNull('supplier_id')
                ->with(['supplier:id,name', 'structure.gp:id,name']);
            foreach (Suche::tokens($aq) as $token) {
                $needle = mb_strtolower($token);
                $q->where(fn ($x) => $x
                    ->whereRaw('LOWER(designation) LIKE ?', ['%' . $needle . '%'])
                    ->orWhere('article_number', 'like', $token . '%')
                    ->orWhereHas('supplier', fn ($s) => $s->whereRaw('LOWER(name) LIKE ?', ['%' . $needle . '%']))
                    ->orWhereHas('structure.gp', fn ($gp) => $gp->whereRaw('LOWER(name) LIKE ?', ['%' . $needle . '%'])));
            }
            $artikelTreffer = $q->orderBy('designation')->limit(12)
                ->get(['id', 'designation', 'article_number', 'packaging_unit', 'supplier_id'])
                ->map(fn ($a) => [
                    'id' => (int) $a->id,
                    'designation' => $a->designation,
                    'article_number' => $a->article_number,
                    'supplier' => $a->supplier?->name ?? '—',
                    'gp' => $a->structure?->gp?->name,
                ])->values();
        }

        $bedarfTreffer = collect();
        $bq = trim($this->bedarfSuche);
        if ($this->bedarfRecipeId === null && mb_strlen($bq) >= 2) {
            $bedarfTreffer = FoodAlchemistRecipe::visibleToTeam($team)
                ->where('name', 'like', '%' . $bq . '%')
                ->orderBy('name')->limit(12)->get(['id', 'name', 'is_sales_recipe'])
                ->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'name' => (string) $r->name,
                    'is_sales_recipe' => (bool) $r->is_sales_recipe,
                ])->values();
        }

        $gpTreffer = collect();
        $gq = trim($this->gpSuche);
        if (mb_strlen($gq) >= 2) {
            $gpQuery = FoodAlchemistGp::visibleToTeam($team);
            foreach (Suche::tokens($gq) as $token) {
                $needle = mb_strtolower($token);
                $gpQuery->whereRaw('LOWER(name) LIKE ?', ['%' . $needle . '%']);
            }
            $gpTreffer = $gpQuery->orderBy('name')->limit(12)->get(['id', 'name'])
                ->map(fn ($gp) => ['id' => (int) $gp->id, 'name' => (string) $gp->name])
                ->values();
        }

        $produktionTreffer = collect();
        $pq = trim($this->produktionSuche);
        if (mb_strlen($pq) >= 2) {
            $produktionTreffer = FoodAlchemistProductionOrder::visibleToTeam($team)
                ->where('name', 'like', '%' . $pq . '%')
                ->orderByDesc('production_date')->limit(12)
                ->get(['id', 'name', 'production_date'])
                ->map(fn ($p) => [
                    'id' => (int) $p->id,
                    'name' => (string) ($p->name ?: 'Produktion #' . $p->id),
                    'date' => $p->production_date?->format('d.m.Y'),
                ])->values();
        }

        $alternativen = [];
        if ($this->altLineId !== null && $detail !== null && $detail['editierbar']) {
            try {
                $alternativen = $orders->lineAlternativen($team, $this->altLineId);
            } catch (\Throwable) {
                $this->altLineId = null;
            }
        }

        return view('foodalchemist::livewire.orders.editor', [
            'detail' => $detail,
            'erlaubteStatus' => $erlaubteStatus,
            'mailto' => $mailto,
            'alternativen' => $alternativen,
            'cockpitAlternativen' => $this->cockpitAlternativen,
            'cockpitAltKey' => $this->cockpitAltKey,
            'artikelTreffer' => $artikelTreffer,
            'bedarfTreffer' => $bedarfTreffer,
            'gpTreffer' => $gpTreffer,
            'produktionTreffer' => $produktionTreffer,
            'strategieOptionen' => LeadLaStrategie::cases(),
        ]);
    }
}
