<?php

namespace Platform\FoodAlchemist\Livewire\Bestellvorlagen;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderTemplate;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderTemplateLine;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\OrderTemplateService;

/**
 * Spec 68 · Einkauf → Bestellvorlagen. Liste links, Vorlage rechts: Positionen (Grundprodukt,
 * Rezept/Gericht, fester Artikel), Vorschau je Lieferant, „Bestellen" öffnet die Bestellrunde
 * vorbefüllt (derselbe Editor wie unter Bestellungen).
 */
class Index extends Component
{
    #[Url(as: 'vorlage')]
    public ?int $vorlageId = null;

    public string $neuName = '';

    public string $suchArt = 'gp';          // gp | recipe | supplier_item

    public string $suche = '';

    public string $liefertag = '';

    public bool $vorschauAn = false;

    public ?string $fehler = null;

    public ?string $hinweis = null;

    public function mount(): void
    {
        $this->liefertag = now()->addDay()->toDateString();
    }

    public function waehlen(int $id): void
    {
        $this->vorlageId = $id;
        $this->vorschauAn = false;
        $this->fehler = null;
        $this->hinweis = null;
    }

    public function anlegen(OrderTemplateService $svc): void
    {
        $this->aktion(function () use ($svc) {
            $v = $svc->anlegen($this->team(), ['name' => $this->neuName], Auth::id());
            $this->neuName = '';
            $this->vorlageId = $v->id;
        });
    }

    public function feldSetzen(string $feld, $wert, OrderTemplateService $svc): void
    {
        if ($this->vorlageId === null || ! in_array($feld, ['name', 'note', 'weekday'], true)) {
            return;
        }
        $this->aktion(fn () => $svc->aendern($this->team(), $this->vorlageId, [$feld => $wert]));
    }

    public function loeschen(OrderTemplateService $svc): void
    {
        if ($this->vorlageId === null) {
            return;
        }
        $this->aktion(function () use ($svc) {
            $svc->loeschen($this->team(), $this->vorlageId);
            $this->vorlageId = null;
            $this->hinweis = 'Vorlage gelöscht.';
        });
    }

    public function positionHinzu(int $bezugId, OrderTemplateService $svc): void
    {
        if ($this->vorlageId === null) {
            return;
        }
        $this->aktion(function () use ($bezugId, $svc) {
            $svc->positionSetzen($this->team(), $this->vorlageId, $this->suchArt, $bezugId, 1);
            $this->suche = '';
            $this->vorschauAn = false;
        });
    }

    public function positionMenge(int $lineId, $menge, OrderTemplateService $svc): void
    {
        $this->aktion(fn () => $svc->positionAendern($this->team(), $lineId, $menge));
        $this->vorschauAn = false;
    }

    public function positionEinheit(int $lineId, string $einheit, OrderTemplateService $svc): void
    {
        $this->aktion(fn () => $svc->positionAendern($this->team(), $lineId, null, $einheit));
        $this->vorschauAn = false;
    }

    public function positionEntfernen(int $lineId, OrderTemplateService $svc): void
    {
        $this->aktion(fn () => $svc->positionEntfernen($this->team(), $lineId));
        $this->vorschauAn = false;
    }

    public function vorschau(): void
    {
        $this->vorschauAn = true;
    }

    /** Bestellrunde öffnen, mit dieser Vorlage vorbefüllt — prüfen, anpassen, speichern. */
    public function bestellen(): void
    {
        if ($this->vorlageId === null) {
            return;
        }
        $this->dispatch('orders-editor.vorlage', templateId: $this->vorlageId, deliveryDate: $this->liefertag ?: null);
    }

    /** Direkt anlegen (ohne Bestellrunde): Entwürfe je Lieferant zum Liefertag. */
    public function direktAnlegen(OrderTemplateService $svc): void
    {
        if ($this->vorlageId === null) {
            return;
        }
        $this->aktion(function () use ($svc) {
            $r = $svc->anwenden($this->team(), $this->vorlageId, $this->liefertag ?: null, [], null, Auth::id());
            $offen = count($r['unresolved']);
            $this->hinweis = count($r['orders']) . ' Bestell-Entwurf/-Entwürfe angelegt'
                . ($offen > 0 ? ' — ' . $offen . ' Position(en) ohne bestellbaren Artikel, siehe Vorschau.' : '.');
        });
    }

    public function render(OrderTemplateService $svc)
    {
        $team = $this->team();
        $vorlagen = $svc->liste($team);
        $vorlage = null;
        if ($this->vorlageId !== null) {
            try {
                $vorlage = $svc->detail($team, $this->vorlageId);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->vorlageId = null;
            }
        }
        $treffer = collect();
        $q = trim($this->suche);
        if ($vorlage !== null && mb_strlen($q) >= 2) {
            $treffer = match ($this->suchArt) {
                'recipe' => FoodAlchemistRecipe::visibleToTeam($team)->where('name', 'like', '%' . $q . '%')->orderBy('name')->limit(10)
                    ->get(['id', 'name', 'is_sales_recipe'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'zusatz' => $r->is_sales_recipe ? 'Gericht' : 'Basisrezept']),
                'supplier_item' => FoodAlchemistSupplierItem::visibleToTeam($team)->with('supplier:id,name')
                    ->where(fn ($w) => $w->where('designation', 'like', '%' . $q . '%')->orWhere('article_number', 'like', $q . '%'))
                    ->orderBy('designation')->limit(10)->get(['id', 'designation', 'supplier_id', 'article_number'])
                    ->map(fn ($a) => ['id' => $a->id, 'name' => $a->designation, 'zusatz' => trim(($a->supplier?->name ?? '') . ($a->article_number ? ' · Art. ' . $a->article_number : ''))]),
                default => FoodAlchemistGp::visibleToTeam($team)->where('name', 'like', '%' . $q . '%')->whereNotIn('status', ['merged', 'rejected'])
                    ->orderBy('name')->limit(10)->get(['id', 'name'])->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'zusatz' => null]),
            };
        }
        $vorschau = null;
        if ($vorlage !== null && $this->vorschauAn) {
            try {
                $vorschau = $svc->vorschau($team, $vorlage->id, $this->liefertag ?: null);
            } catch (\Throwable $e) {
                $this->fehler = $e->getMessage();
            }
        }

        return view('foodalchemist::livewire.bestellvorlagen.index', [
            'vorlagen' => $vorlagen,
            'vorlage' => $vorlage,
            'treffer' => $treffer,
            'vorschau' => $vorschau,
            'einheiten' => FoodAlchemistOrderTemplateLine::EINHEITEN,
            'wochentage' => FoodAlchemistOrderTemplate::WOCHENTAGE,
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }

    private function aktion(callable $tu): void
    {
        $this->fehler = null;
        try {
            $tu();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Nicht gefunden — bitte Seite neu laden.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
