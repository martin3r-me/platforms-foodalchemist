<?php

namespace Platform\FoodAlchemist\Livewire\Orders;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Services\ProductionOrderService;
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
    use \Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;   // Spec 65

    /**
     * Spec 65: Sperre gilt der Bestellschiene (Ziel order). Die Bestellrunde/Direktbestellung ohne Beleg
     * (orderId null) erzeugt erst neue Schienen und braucht keine Sperre.
     */
    protected function sperrZiel(): ?array
    {
        return $this->orderId !== null ? ['order', $this->orderId] : null;
    }

    protected function sperrModalName(): ?string
    {
        return 'orders-editor';
    }

    /** Spec 65: Editor geschlossen → eigene Sperre frei (einziger modal.closed-Handler dieser Komponente). */
    #[On('modal.closed')]
    public function beiModalGeschlossen(?string $name = null): void
    {
        $this->sperreBeiSchliessen($name);
    }

    /**
     * Lesend (ohne Sperre): Öffnen (alle Einstiege), Ausweichquellen auf-/zuklappen, Lieferantenwechsel-Vorschau
     * (persistiert nichts) und deren Schließen, Alternativen der Runde nachschlagen, Modal-Schließen.
     */
    protected function sperrFreiExtra(): array
    {
        return [
            'oeffnenBearbeiten', 'oeffnenNeu', 'oeffnenProduktion', 'oeffnenRunde', 'oeffnenProduktionen',
            'alternativenUmschalten', 'neuQuellenVorschau', 'neuQuellenAbbrechen', 'cockpitAlternativenUmschalten',
            'beiModalGeschlossen',
            // Spec 68: Vorlage öffnen / Bestellung als Vorlage speichern ändert die Bestellung nicht
            'oeffnenVorlage', 'bestellungAlsVorlage', 'rundeBearbeiten',
        ];
    }

    /** Abbrechen bzw. „Fertig": Kopf-Formular frisch aus dem Beleg laden. */
    protected function nachAbbrechen(): void
    {
        $this->resourceVorschau = null;
        $this->altLineId = null;
        $this->ladeKopf();
    }

    /**
     * Spec 65: Eine Schreibaktion hat den Editor auf eine ANDERE Schiene geführt (Artikel-Tausch mit
     * Lieferantenwechsel, Direktbestellung legt Schienen an). Die Sperre folgt: alte freigeben, neue
     * holen — sonst stünde der Editor nach dem eigenen Schreiben im Lesemodus und die alte Sperre bliebe liegen.
     */
    private function sperreFolgtBeleg(?int $alteId): void
    {
        if ($alteId === $this->orderId || $this->orderId === null) {
            return;
        }
        if ($alteId !== null && Auth::id() !== null) {
            app(\Platform\FoodAlchemist\Services\BearbeitungssperreService::class)->freigeben('order', $alteId, (int) Auth::id());
        }
        if (self::sperreAktiv()) {
            $this->bearbeitenStarten();
        }
    }

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

    /** Spec 68: Vorlage in die Bestellrunde einfügen / Runde oder Bestellung als Vorlage speichern. */
    public $vorlageWahl = '';

    public string $vorlageName = '';

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

    public int $cockpitSeq = 0;

    public ?int $roundId = null;

    /** @var list<int> Produktionen, die beim Öffnen bereits Teil der Runde waren. */
    public array $roundProductionIds = [];

    public ?array $cockpitPreview = null;

    public string $cockpitStrategy = '';

    /** override_key => lead_la_id für einzelne Cockpit-Zutaten vor dem Speichern. */
    public array $cockpitOverrides = [];

    /** Spec 71: Positionen auslassen / Gebinde von Hand / Lagerbestand vom Bedarf abziehen. */
    public array $cockpitSkip = [];

    public array $cockpitMengen = [];

    public bool $cockpitLagerAbgleich = false;

    /** Spec 71: Lager je Position abziehen (Schlüssel → bool), schlägt den Gesamt-Knopf. */
    public array $cockpitLagerPos = [];

    /** Gespeicherte Runde = Lesemodus (wie ein gespeichertes Rezept); „Bearbeiten" öffnet wieder. */
    public bool $rundeGesperrt = false;

    /** Zeile, deren Ausweichquellen-Dropdown offen ist (nur eine gleichzeitig). */
    public ?int $altLineId = null;

    /** Vorschau-Zutat, deren Ausweichquellen-Dropdown offen ist. */
    public ?string $cockpitAltKey = null;

    public array $cockpitAlternativen = [];

    #[On('orders-editor.bearbeiten')]
    public function oeffnenBearbeiten(int $id): void
    {
        if ($this->orderId !== null && $this->orderId !== $id) {
            $this->bearbeitenBeenden();   // Spec 65: Sperre der vorher offenen Schiene freigeben
        }
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
    public function oeffnenNeu(?string $deliveryDate = null, ?string $strategy = null, ?int $productionId = null): void
    {
        $this->bearbeitenBeenden();   // Spec 65: Sperre der vorher offenen Schiene freigeben
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
        $this->rundeGesperrt = false;
        $this->cockpitSkip = [];
        $this->cockpitMengen = [];
        $this->cockpitLagerAbgleich = false;
        $this->cockpitLagerPos = [];
        if ($productionId !== null) {
            $this->cockpitProduktionEinfuegen($productionId);
        }
        $this->dispatch('modal.open', name: 'orders-editor');
    }

    /** Spec 68: Bestellrunde öffnen, mit einer Vorlage vorbefüllt (aus Einkauf → Bestellvorlagen). */
    #[On('orders-editor.vorlage')]
    public function oeffnenVorlage(int $templateId, ?string $deliveryDate = null): void
    {
        $this->oeffnenNeu($deliveryDate);
        $this->cockpitVorlageEinfuegen($templateId);
    }

    #[On('orders-editor.production')]
    public function oeffnenProduktion(int $id, ?int $roundId = null): void
    {
        $this->oeffnenNeu();
        $ids = [$id];
        if ($roundId !== null) {
            try {
                $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
                $round = app(OrderService::class)->roundDetail($team, $roundId);
                $ids = array_values(array_unique(array_merge($round['production_ids'] ?? [], [$id])));
                $this->roundId = $roundId;
            } catch (\Throwable) {
                $this->roundId = null;
            }
        }
        foreach ($ids as $productionId) {
            $this->cockpitProduktionEinfuegen((int) $productionId);
        }
        if ($this->cockpitSources !== []) {
            $this->cockpitVorschau(app(OrderService::class));
        }
    }

    #[On('orders-editor.round')]
    public function oeffnenRunde(int $id, OrderService $orders): void
    {
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $round = $orders->roundDetail($team, $id);

            $this->oeffnenNeu(
                $round['desired_delivery_date'] ?? null,
                $round['sourcing_strategy'] ?? null,
            );
            $this->roundId = $id;
            $this->formReference = (string) ($round['label'] ?? '');
            $this->formNote = (string) ($round['note'] ?? '');
            $this->roundProductionIds = array_values(array_unique(array_map('intval', $round['production_ids'] ?? [])));

            foreach ($this->roundProductionIds as $productionId) {
                $this->cockpitProduktionEinfuegen($productionId);
            }
            if ($this->cockpitSources !== []) {
                $this->cockpitVorschau($orders);
            } else {
                $this->hinweis = 'Die Runde enthält keine rekonstruierbare Produktionsquelle. Lieferantenbelege können weiterhin einzeln bearbeitet werden.';
            }
            $this->rundeGesperrt = true;   // bestehende Runde öffnet im Lesemodus
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    #[On('orders-editor.productions')]
    public function oeffnenProduktionen(array $ids, OrderService $orders): void
    {
        $this->oeffnenNeu();
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            if ($id > 0) {
                $this->cockpitProduktionEinfuegen($id);
            }
        }
        if ($this->cockpitSources !== []) {
            $this->cockpitVorschau($orders);
        }
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

    /** Spec 63: fehlgeschlagene Bestell-/Storno-Mail erneut senden. */
    public function mailErneutSenden(int $mailId): void
    {
        $this->fuehreAus(
            fn ($team) => app(\Platform\FoodAlchemist\Services\OrderMailService::class)->erneutSenden($team, $mailId),
            'Mail wird erneut gesendet.'
        );
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
                $alt = $this->orderId;
                $this->orderId = (int) $res['target_order_id'];
                $this->sperreFolgtBeleg($alt);   // Spec 65
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
        $this->cockpitSeq = 0;
        $this->roundId = null;
        $this->roundProductionIds = [];
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
            'uid' => $this->neueCockpitUid(),
            'type' => 'supplier_item',
            'id' => (int) $la->id,
            'label' => trim(($la->designation ?: 'Artikel #'.$la->id).($la->supplier?->name ? ' · '.$la->supplier->name : '')),
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
            'uid' => $this->neueCockpitUid(),
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
            'uid' => $this->neueCockpitUid(),
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
            'uid' => $this->neueCockpitUid(),
            'type' => 'production',
            'id' => (int) $production->id,
            'label' => $production->name ?: ('Produktion #'.$production->id),
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

    public function cockpitQuelleEntfernen(string $uid): void
    {
        $index = collect($this->cockpitSources)->search(fn (array $source) => ($source['uid'] ?? null) === $uid);
        if ($index === false) {
            return;
        }
        array_splice($this->cockpitSources, $index, 1);
        $this->cockpitPreview = null;
        $this->cockpitOverrides = [];
        $this->cockpitAlternativenSchliessen();
    }

    /** Spec 68: Positionen einer Vorlage an den Arbeitsstand anhängen (mehrere Vorlagen kombinierbar). */
    public function cockpitVorlageEinfuegen($templateId = null): void
    {
        $templateId = (int) ($templateId ?: $this->vorlageWahl);
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $templateId <= 0) {
            return;
        }
        $svc = app(\Platform\FoodAlchemist\Services\OrderTemplateService::class);
        try {
            $v = $svc->detail($team, $templateId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Vorlage nicht gefunden.';

            return;
        }
        foreach ($v->lines as $l) {
            $q = $l->alsQuelle();
            if ($q['id'] <= 0 || $q['qty'] <= 0) {
                continue;
            }
            $this->cockpitSources[] = $q + [
                'uid' => $this->neueCockpitUid(),
                'label' => $l->bezeichnung(),
                'delivery_date' => $this->formDeliveryDate ?: null,
                'reference' => $this->formReference ?: ('Vorlage ' . $v->name),
            ];
        }
        $this->vorlageWahl = '';
        $this->cockpitPreview = null;
        $this->cockpitOverrides = [];
        $this->hinweis = 'Vorlage „' . $v->name . '" eingefügt (' . $v->lines->count() . ' Positionen).';
    }

    /** Spec 68: aktueller Arbeitsstand der Bestellrunde → neue Vorlage. */
    public function cockpitAlsVorlage(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        try {
            $v = app(\Platform\FoodAlchemist\Services\OrderTemplateService::class)->ausQuellen($team, $this->vorlageName, $this->cockpitSources, Auth::id());
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->vorlageName = '';
        $this->fehler = null;
        $this->hinweis = 'Als Vorlage „' . $v->name . '" gespeichert.';
    }

    /** Spec 68: bestehende Bestellung → neue Vorlage (GP-Zeilen als Grundprodukt, sonst fester Artikel). */
    public function bestellungAlsVorlage(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->orderId === null) {
            return;
        }
        try {
            $v = app(\Platform\FoodAlchemist\Services\OrderTemplateService::class)->ausBestellung($team, $this->orderId, $this->vorlageName, Auth::id());
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->vorlageName = '';
        $this->fehler = null;
        $this->hinweis = 'Als Vorlage „' . $v->name . '" gespeichert.';
    }

    private function neueCockpitUid(): string
    {
        $this->cockpitSeq++;

        return 'source-'.$this->cockpitSeq.'-'.Str::lower(Str::random(8));
    }

    public function cockpitVorschau(OrderService $orders): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        $this->cockpitAlternativenSchliessen();
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $this->cockpitPreview = $orders->previewFromSources($team, $this->cockpitSources, $this->cockpitStrategieAusForm(), $this->cockpitAlleOverrides());
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

    /** Artikel-Wahl + Spec 71 (auslassen, Menge, Lager) als ein Override-Paket — Vorschau und Speichern rechnen gleich. */
    private function cockpitAlleOverrides(): array
    {
        return $this->cockpitOverrides + ['skip' => $this->cockpitSkip, 'menge' => $this->cockpitMengen, 'lager_abgleich' => $this->cockpitLagerAbgleich, 'lager_pos' => $this->cockpitLagerPos];
    }

    public function positionAuslassen(string $key, OrderService $orders): void
    {
        $this->cockpitSkip[$key] = true;
        $this->cockpitVorschau($orders);
    }

    public function positionWiederherstellen(string $key, OrderService $orders): void
    {
        unset($this->cockpitSkip[$key]);
        $this->cockpitVorschau($orders);
    }

    /** Gebinde von Hand setzen (+/− oder Eingabe); leer = Automatik. */
    public function positionMenge(string $key, $menge, OrderService $orders): void
    {
        $roh = trim(str_replace(',', '.', (string) ($menge ?? '')));
        if ($roh === '') {
            unset($this->cockpitMengen[$key]);
        } elseif (is_numeric($roh) && (float) $roh >= 0) {
            $this->cockpitMengen[$key] = round((float) $roh, 3);
        } else {
            $this->fehler = 'Menge braucht eine Zahl ≥ 0.';

            return;
        }
        $this->cockpitVorschau($orders);
    }

    /** Knopf „Alle aus dem Lager abziehen“ / „Lager nicht abziehen“ — setzt Einzelentscheidungen zurück. */
    public function lagerAlleAbziehen(bool $an, OrderService $orders): void
    {
        $this->cockpitLagerAbgleich = $an;
        $this->cockpitLagerPos = [];
        $this->cockpitVorschau($orders);
    }

    /** Lager nur für diese Position abziehen bzw. nicht abziehen. */
    public function lagerPosition(string $key, bool $an, OrderService $orders): void
    {
        $this->cockpitLagerPos[$key] = $an;
        $this->cockpitVorschau($orders);
    }

    public function updatedCockpitStrategy(): void
    {
        // Strategie gewechselt → Vorschau sofort neu (sonst wirkt der Wechsel erst nach „Vorschau berechnen")
        if ($this->cockpitSources !== []) {
            $this->cockpitVorschau(app(OrderService::class));
        }
    }

    public function rundeBearbeiten(): void
    {
        $this->rundeGesperrt = false;
        $this->hinweis = null;
    }

    public function cockpitSpeichern(OrderService $orders): void
    {
        if ($this->rundeGesperrt) {
            return;
        }
        $this->hinweis = null;
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $res = $orders->generateDraftsFromSources(
                $team,
                $this->cockpitSources,
                $this->cockpitStrategieAusForm(),
                Auth::id(),
                $this->cockpitAlleOverrides(),
                [
                    'id' => $this->roundId,
                    'label' => $this->formReference ?: null,
                    'desired_delivery_date' => $this->formDeliveryDate ?: null,
                    'note' => $this->formNote ?: null,
                    'replace_production_ids' => $this->roundProductionIds,
                ]
            );
            $this->cockpitPreview = $res['preview'] ?? null;
            $this->roundId = isset($res['round']['id']) ? (int) $res['round']['id'] : $this->roundId;
            $this->hinweis = count($res['orders'] ?? []).' Bestellschiene(n) gespeichert'
                .(count($res['unresolved'] ?? []) > 0 ? ' · '.count($res['unresolved']).' Klärpunkt(e)' : '').'.';
            $this->rundeGesperrt = true;   // gespeichert → Lesemodus
            $this->dispatch('orders-geaendert');
            $this->dispatch('modal.open', name: 'orders-editor');
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** „＋ Artikel": manuelle Zeile (Menge 1) an die Draft-Schiene des Lieferanten hängen. */
    public function artikelHinzufuegen(int $supplierItemId, OrderService $orders): void
    {
        $this->fuehreAus(function ($team) use ($orders, $supplierItemId) {
            $line = $orders->addManualLine($team, $supplierItemId, 1.0, null, Auth::id(), $this->formDeliveryDate ?: null);
            $alt = $this->orderId;
            $this->orderId = (int) $line->order_id;
            $this->sperreFolgtBeleg($alt);   // Spec 65
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
        $sourceRef = 'recipe:'.$this->bedarfRecipeId.'@'.uniqid();

        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $res = $orders->addNeedFromTarget($team, $ziel, $sourceRef, Auth::id(), null, $this->formDeliveryDate ?: null);
            $this->kopfNachStartSpeichern($orders, array_map('intval', $res['orders'] ?? []));
            if (! empty($res['orders'])) {
                $alt = $this->orderId;
                $this->orderId = (int) $res['orders'][0];
                $this->sperreFolgtBeleg($alt);   // Spec 65
                $this->ladeKopf();
            }
            if (empty($res['orders']) && empty($res['skipped_ohne_la'])) {
                $this->fehler = 'Kein bestellbarer Bedarf (Rezept ohne Zutaten mit Lead-LA?).';

                return;
            }
            $teile = [count($res['orders']).' Schiene(n) aktualisiert'];
            if (! empty($res['skipped_ohne_la'])) {
                $teile[] = 'ohne Lead-LA übersprungen: '.implode(', ', $res['skipped_ohne_la']);
            }
            $this->hinweis = 'Bedarf übernommen — '.implode(' · ', $teile).'.';
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
        $cancellationMailto = null;
        $mailDienst = app(\Platform\FoodAlchemist\Services\OrderMailService::class);
        $serverVersand = $mailDienst->istServerVersand($team);
        $mailProtokoll = collect();
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
                    $mailto = 'mailto:'.$m['to'].'?subject='.rawurlencode($m['subject']).'&body='.rawurlencode($m['body']);
                }
                $mailProtokoll = $mailDienst->protokoll($team, $this->orderId);
                $cancelMail = $orders->cancellationMailtoData($team, $this->orderId);
                if (($cancelMail['to'] ?? '') !== '') {
                    $cancellationMailto = 'mailto:'.$cancelMail['to'].'?subject='.rawurlencode($cancelMail['subject']).'&body='.rawurlencode($cancelMail['body']);
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
                    ->whereRaw('LOWER(designation) LIKE ?', ['%'.$needle.'%'])
                    ->orWhere('article_number', 'like', $token.'%')
                    ->orWhereHas('supplier', fn ($s) => $s->whereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%']))
                    ->orWhereHas('structure.gp', fn ($gp) => $gp->whereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%'])));
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
                ->where('name', 'like', '%'.$bq.'%')
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
                $gpQuery->whereRaw('LOWER(name) LIKE ?', ['%'.$needle.'%']);
            }
            $gpTreffer = $gpQuery->orderBy('name')->limit(12)->get(['id', 'name'])
                ->map(fn ($gp) => ['id' => (int) $gp->id, 'name' => (string) $gp->name])
                ->values();
        }

        $produktionTreffer = collect();
        $pq = trim($this->produktionSuche);
        $selectedProductions = collect($this->cockpitSources)
            ->where('type', 'production')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $produktionQuery = FoodAlchemistProductionOrder::visibleToTeam($team)
            ->whereNotNull('procurement_released_at')
            ->when($selectedProductions !== [], fn ($query) => $query->whereNotIn('id', $selectedProductions));
        if ($pq !== '') {
            $produktionQuery->where('name', 'like', '%'.$pq.'%');
        }
        $produktionTreffer = $produktionQuery
            ->orderByDesc('production_date')->limit(12)
            ->get(['id', 'name', 'production_date', 'targets', 'procurement_targets_hash'])
            ->filter(fn ($p) => $p->procurement_targets_hash === ProductionOrderService::targetsHash($p->targets))
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => (string) ($p->name ?: 'Produktion #'.$p->id),
                'date' => $p->production_date?->format('d.m.Y'),
            ])->values();

        $alternativen = [];
        if ($this->altLineId !== null && $detail !== null && $detail['editierbar']) {
            try {
                $alternativen = $orders->lineAlternativen($team, $this->altLineId);
            } catch (\Throwable) {
                $this->altLineId = null;
            }
        }

        $roundDetail = null;
        if ($this->roundId !== null) {
            try {
                $roundDetail = $orders->roundDetail($team, $this->roundId);
            } catch (\Throwable) {
                $this->roundId = null;
            }
        }

        return view('foodalchemist::livewire.orders.editor', [
            'sperr' => $this->sperrZustand(),   // Spec 65
            'vorlagen' => \Platform\FoodAlchemist\Models\FoodAlchemistOrderTemplate::where('team_id', $team->id)->orderBy('name')->pluck('name', 'id'),
            'detail' => $detail,
            'erlaubteStatus' => $erlaubteStatus,
            'mailto' => $mailto,
            'cancellationMailto' => $cancellationMailto,
            'serverVersand' => $serverVersand,
            'mailProtokoll' => $mailProtokoll,
            'alternativen' => $alternativen,
            'cockpitAlternativen' => $this->cockpitAlternativen,
            'cockpitAltKey' => $this->cockpitAltKey,
            'roundDetail' => $roundDetail,
            'artikelTreffer' => $artikelTreffer,
            'bedarfTreffer' => $bedarfTreffer,
            'gpTreffer' => $gpTreffer,
            'produktionTreffer' => $produktionTreffer,
            'strategieOptionen' => LeadLaStrategie::cases(),
        ]);
    }
}
