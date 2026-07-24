<?php

namespace Platform\FoodAlchemist\Livewire\Orders;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Support\Suche;

/**
 * Spec 17/S2 + Spec 20/E1 — „Bestellungen" als 3-Panel-Cockpit (Muster: Produktion):
 *   Panel 1 Schienen-Browser (Status-/Lieferant-Filter, Suche, Entwürfe zuerst)
 *   Panel 2 Positionen (Artikel · Gebinde · Anzahl Auto/Manuell · Bedarf · Preis · Summe · Herkunft · Notiz · ✕)
 *   Panel 3 Detail/Aktionen (Kopf-Edit, Status-Buttons, MOQ-Ampel, Herkunft, Export)
 * Nur der `draft` ist editierbar; Schreiben geht durch den D1-gescopten OrderService
 * (isOwnedBy + Status-Guard).
 */
class Index extends Component
{
    #[Url(as: 'o')]
    public ?int $selectedId = null;

    #[Url(as: 's')]
    public string $statusFilter = '';

    #[Url(as: 'lief')]
    public ?int $supplierFilter = null;

    #[Url(as: 'q')]
    public string $suche = '';

    // Kopf-Edit-Form (Panel 3) — beim Wählen aus dem Detail befüllt.
    public string $formReference = '';

    public string $formDeliveryDate = '';

    public string $formNote = '';

    public ?string $hinweis = null;

    public ?string $fehler = null;

    // ── E2 · Direktbestellung (manuelle Artikel · neue Schiene · Bedarf-Schnellerfassung) ──
    /** Panel-1-Sektion „＋ Direktbestellung" auf-/zuklappen. */
    public bool $direktOffen = false;

    /** „Neue Bestellung": leere Draft-Schiene je Lieferant (createDraft). */
    public ?int $neuerLieferant = null;

    /** „＋ Artikel": globale LA-Livesearch (unabhängig von jeder Produktion). */
    public string $artikelSuche = '';

    /** Bedarf-Schnellerfassung: Gericht/Basisrezept → addNeedFromTarget (P1-Zieltypen). */
    public string $bedarfSuche = '';

    public ?int $bedarfRecipeId = null;

    public string $bedarfRecipeName = '';

    /** true = VK-Gericht (Portionen), false = Basisrezept (Ansätze/kg). Aus is_sales_recipe. */
    public bool $bedarfRecipeVk = true;

    /** portions (VK) | ansaetze | kg — Doppel-Bedeutung wie P1 (portions=VK-Port. / Basis-Ansätze). */
    public string $bedarfEinheit = 'portions';

    public string $bedarfMenge = '';

    public function select(int $id): void
    {
        $this->selectedId = $id;
        $this->hinweis = null;
        $this->fehler = null;
        $this->ladeKopf();
    }

    /** Kopf-Felder des aktiven Belegs in die Form spiegeln (für Panel 3). */
    private function ladeKopf(): void
    {
        $this->formReference = '';
        $this->formDeliveryDate = '';
        $this->formNote = '';
        if ($this->selectedId === null) {
            return;
        }
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $d = app(OrderService::class)->detail($team, $this->selectedId);
            $this->formReference = (string) ($d['reference'] ?? '');
            $this->formDeliveryDate = (string) ($d['desired_delivery_date'] ?? '');
            $this->formNote = (string) ($d['note'] ?? '');
        } catch (\Throwable $e) {
            // Detail wird in render() ohnehin defensiv behandelt.
        }
    }

    public function saveHeader(OrderService $orders): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $orders->updateHeader($team, $this->selectedId, [
            'reference' => $this->formReference,
            'desired_delivery_date' => $this->formDeliveryDate,
            'note' => $this->formNote,
        ]), 'Kopf gespeichert.');
    }

    public function setStatus(string $status, OrderService $orders): void
    {
        $ziel = OrderStatus::tryFrom($status);
        if ($ziel === null || $this->selectedId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $orders->setStatus($team, $this->selectedId, $ziel), 'Status gesetzt.');
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

    public function removeLine(int $lineId, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->removeLine($team, $lineId), 'Position entfernt.');
    }

    private function fuehreAus(callable $fn, string $ok): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $fn($team);
            $this->hinweis = $ok;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    // ── E2 · Direktbestellung ─────────────────────────────────────────────

    /** „Neue Bestellung": leere Draft-Schiene für den gewählten Lieferanten (findOrCreate). */
    public function neueBestellung(OrderService $orders): void
    {
        if ($this->neuerLieferant === null) {
            $this->fehler = 'Erst einen Lieferanten wählen.';

            return;
        }
        $this->fuehreAus(function ($team) use ($orders) {
            $draft = $orders->createDraft($team, (int) $this->neuerLieferant, [], Auth::id());
            $this->selectedId = (int) $draft->id;
            $this->ladeKopf();
        }, 'Bestellung angelegt.');
        $this->neuerLieferant = null;
    }

    /** „＋ Artikel": manuelle Zeile (Menge 1) an die Draft-Schiene des Lieferanten hängen. */
    public function artikelHinzufuegen(int $supplierItemId, OrderService $orders): void
    {
        $this->fuehreAus(function ($team) use ($orders, $supplierItemId) {
            $line = $orders->addManualLine($team, $supplierItemId, 1.0, null, Auth::id());
            $this->selectedId = (int) $line->order_id;
            $this->ladeKopf();
        }, 'Artikel hinzugefügt.');
        $this->artikelSuche = '';
    }

    /** Bedarf-Schnellerfassung: gewähltes Gericht/Basisrezept in den Picker laden. */
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
     * Bedarf des gewählten Ziels direkt in die Lieferanten-Schienen übernehmen (E2). Nutzt die
     * P1-Zieltypen: VK-Gericht = Portionen, Basisrezept = Ansätze ODER kg (amount_kg). Quelle
     * `recipe:{id}@…` spiegelt den Produktions-Editor (parseHerkunft → „Gericht").
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
            $ziel['portions'] = $menge;   // VK = Portionen, Basis = Ansätze (P1-Doppelbedeutung)
        }
        $sourceRef = 'recipe:' . $this->bedarfRecipeId . '@' . uniqid();

        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $res = $orders->addNeedFromTarget($team, $ziel, $sourceRef, Auth::id());
            if (! empty($res['orders'])) {
                $this->selectedId = (int) $res['orders'][0];
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
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(OrderService $orders)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        $roh = $orders->listForTeam($team, $this->statusFilter !== '' ? $this->statusFilter : null);

        // Lieferanten-Optionen aus dem (nur status-gefilterten) Bestand — für Panel-1-Filter.
        $lieferanten = $roh
            ->filter(fn ($o) => $o->supplier !== null)
            ->map(fn ($o) => ['id' => (int) $o->supplier->id, 'name' => (string) $o->supplier->name])
            ->unique('id')->sortBy('name')->values();

        $suche = trim(mb_strtolower($this->suche));
        $liste = $roh
            ->when($this->supplierFilter !== null, fn ($c) => $c->filter(fn ($o) => (int) $o->supplier_id === $this->supplierFilter))
            ->when($suche !== '', fn ($c) => $c->filter(function ($o) use ($suche) {
                $hay = mb_strtolower(($o->supplier?->name ?? '') . ' ' . ($o->reference ?? ''));

                return str_contains($hay, $suche);
            }))
            ->map(fn ($o) => [
                'id' => (int) $o->id,
                'supplier' => $o->supplier?->name ?? '—',
                'status' => $o->status instanceof OrderStatus ? $o->status : OrderStatus::from((string) $o->status),
                'total_net' => (float) $o->total_net,
                'reference' => $o->reference,
            ])->values();

        $detail = null;
        $erlaubteStatus = [];
        $mailto = null;
        if ($this->selectedId !== null) {
            try {
                $detail = $orders->detail($team, $this->selectedId);
                $aktuell = OrderStatus::from($detail['status']);
                foreach ([OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered, OrderStatus::Cancelled] as $z) {
                    if ($aktuell->darfWechselnZu($z)) {
                        $erlaubteStatus[] = $z;
                    }
                }
                // S3: vorbefüllte E-Mail an den Lieferanten (Bestellweg email_order).
                $m = $orders->mailtoData($team, $this->selectedId);
                if (($m['to'] ?? '') !== '') {
                    $mailto = 'mailto:' . $m['to'] . '?subject=' . rawurlencode($m['subject']) . '&body=' . rawurlencode($m['body']);
                }
            } catch (\Throwable $e) {
                $this->selectedId = null;
            }
        }

        // ── E2 · Direktbestellung: Lieferanten-Vollliste + Livesearch-Treffer ──
        $alleLieferanten = FoodAlchemistSupplier::visibleToTeam($team)
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => (string) $s->name])->values();

        $artikelTreffer = collect();
        $aq = trim($this->artikelSuche);
        if (mb_strlen($aq) >= 2) {
            $q = FoodAlchemistSupplierItem::visibleToTeam($team)
                ->where('is_discontinued', false)
                ->whereNotNull('supplier_id')
                ->with('supplier:id,name');
            foreach (Suche::tokens($aq) as $token) {
                $q->where(fn ($x) => $x
                    ->whereRaw('LOWER(designation) LIKE ?', ['%' . $token . '%'])
                    ->orWhere('article_number', 'like', $token . '%'));
            }
            $artikelTreffer = $q->orderBy('designation')->limit(12)
                ->get(['id', 'designation', 'article_number', 'packaging_unit', 'supplier_id'])
                ->map(fn ($a) => [
                    'id' => (int) $a->id,
                    'designation' => $a->designation,
                    'article_number' => $a->article_number,
                    'supplier' => $a->supplier?->name ?? '—',
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

        return view('foodalchemist::livewire.orders.index', [
            'liste' => $liste,
            'lieferanten' => $lieferanten,
            'alleLieferanten' => $alleLieferanten,
            'artikelTreffer' => $artikelTreffer,
            'bedarfTreffer' => $bedarfTreffer,
            'detail' => $detail,
            'erlaubteStatus' => $erlaubteStatus,
            'mailto' => $mailto,
        ])->layout('platform::layouts.app');
    }
}
