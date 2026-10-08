<?php

namespace Platform\FoodAlchemist\Livewire\Bestellvorlagen;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderTemplate;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderTemplateLine;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\OrderTemplateService;

/**
 * Spec 73 · Bestellvorlage im Editor (Vollbild-Modal), wie ein Rezept: Lesen → „Bearbeiten" (Spec 65 Sperre)
 * → Kopf + Positionen ändern → „Speichern". Positionen: Grundprodukt, Rezept/Gericht, Konzept, Paket, fester
 * Artikel. Bestellen (Vorschau, In Bestellrunde öffnen, Direkt anlegen) geht auch im Lesemodus.
 */
class Editor extends Component
{
    use MitBearbeitungssperre;

    public ?int $vorlageId = null;

    /** Kopf (gespeichert per „Speichern"). */
    public array $form = ['name' => '', 'kategorie' => '', 'weekday' => '', 'note' => ''];

    public string $suchArt = 'gp';          // gp | recipe | concept | paket | supplier_item

    public string $suche = '';

    public string $liefertag = '';

    public bool $vorschauAn = false;

    public ?string $fehler = null;

    public ?string $hinweis = null;

    protected function sperrZiel(): ?array
    {
        return $this->vorlageId !== null ? ['order_template', $this->vorlageId] : null;
    }

    protected function sperrModalName(): ?string
    {
        return 'vorlage-editor';
    }

    protected function sperrFreiExtra(): array
    {
        return ['oeffnenVorlage', 'beiModalGeschlossen', 'vorschau', 'bestellen', 'direktAnlegen'];
    }

    #[On('vorlage-editor.oeffnen')]
    public function oeffnenVorlage(int $id, bool $bearbeiten = false): void
    {
        $this->vorlageId = $id;
        $this->laden();
        $this->suche = '';
        $this->vorschauAn = false;
        $this->fehler = null;
        $this->hinweis = null;
        $this->liefertag = now()->addDay()->toDateString();
        if ($bearbeiten) {
            $this->bearbeitenStarten();      // neu angelegt → gleich bearbeiten
        }
        $this->dispatch('modal.open', name: 'vorlage-editor');
    }

    #[On('modal.closed')]
    public function beiModalGeschlossen(?string $name = null): void
    {
        $this->sperreBeiSchliessen($name);
        if ($name === 'vorlage-editor') {
            $this->dispatch('vorlagen-geaendert');
        }
    }

    protected function nachAbbrechen(): void
    {
        $this->laden();
    }

    public function speichern(OrderTemplateService $svc): void
    {
        $this->aktion(function () use ($svc) {
            $svc->aendern($this->team(), (int) $this->vorlageId, $this->form);
            $this->bearbeitenBeenden();
            $this->hinweis = 'Vorlage gespeichert.';
            $this->dispatch('vorlagen-geaendert');
        });
    }

    public function loeschen(OrderTemplateService $svc): void
    {
        $this->aktion(function () use ($svc) {
            $svc->loeschen($this->team(), (int) $this->vorlageId);
            $this->bearbeitenBeenden();
            $this->vorlageId = null;
            $this->dispatch('modal.close', name: 'vorlage-editor');
            $this->dispatch('vorlagen-geaendert', hinweis: 'Vorlage gelöscht.');
        });
    }

    public function positionHinzu(int $bezugId, OrderTemplateService $svc): void
    {
        $this->aktion(function () use ($bezugId, $svc) {
            $menge = in_array($this->suchArt, ['concept', 'paket'], true) ? 50 : 1;
            $svc->positionSetzen($this->team(), (int) $this->vorlageId, $this->suchArt, $bezugId, $menge);
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

    /** Bestellrunde öffnen, mit dieser Vorlage vorbefüllt. */
    public function bestellen(): void
    {
        if ($this->vorlageId === null) {
            return;
        }
        $this->dispatch('modal.close', name: 'vorlage-editor');
        $this->dispatch('orders-editor.vorlage', templateId: $this->vorlageId, deliveryDate: $this->liefertag ?: null);
    }

    public function direktAnlegen(OrderTemplateService $svc): void
    {
        $this->aktion(function () use ($svc) {
            $r = $svc->anwenden($this->team(), (int) $this->vorlageId, $this->liefertag ?: null, [], null, Auth::id());
            $offen = count($r['unresolved']);
            $this->hinweis = count($r['orders']) . ' Bestell-Entwurf/-Entwürfe angelegt'
                . ($offen > 0 ? ' — ' . $offen . ' Position(en) ohne bestellbaren Artikel, siehe Vorschau.' : '.');
        });
    }

    public function render(OrderTemplateService $svc)
    {
        $team = $this->team();
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
            $like = '%' . $q . '%';
            $treffer = match ($this->suchArt) {
                'recipe' => FoodAlchemistRecipe::visibleToTeam($team)->where('name', 'like', $like)->orderBy('name')->limit(10)
                    ->get(['id', 'name', 'is_sales_recipe'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'zusatz' => $r->is_sales_recipe ? 'Gericht' : 'Basisrezept']),
                'concept' => FoodAlchemistConcept::visibleToTeam($team)->where('name', 'like', $like)->orderBy('name')->limit(10)
                    ->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'zusatz' => 'Konzept']),
                'paket' => FoodAlchemistPaket::visibleToTeam($team)->where('name', 'like', $like)->orderBy('name')->limit(10)
                    ->get(['id', 'name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'zusatz' => 'Paket']),
                'supplier_item' => FoodAlchemistSupplierItem::visibleToTeam($team)->with('supplier:id,name')
                    ->where(fn ($w) => $w->where('designation', 'like', $like)->orWhere('article_number', 'like', $q . '%'))
                    ->orderBy('designation')->limit(10)->get(['id', 'designation', 'supplier_id', 'article_number'])
                    ->map(fn ($a) => ['id' => $a->id, 'name' => $a->designation, 'zusatz' => trim(($a->supplier?->name ?? '') . ($a->article_number ? ' · Art. ' . $a->article_number : ''))]),
                default => FoodAlchemistGp::visibleToTeam($team)->where('name', 'like', $like)->whereNotIn('status', ['merged', 'rejected'])
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

        return view('foodalchemist::livewire.bestellvorlagen.editor', [
            'vorlage' => $vorlage,
            'treffer' => $treffer,
            'vorschau' => $vorschau,
            'einheiten' => FoodAlchemistOrderTemplateLine::EINHEITEN,
            'wochentage' => FoodAlchemistOrderTemplate::WOCHENTAGE,
            'kategorien' => $svc->kategorien($team),
            'sperr' => $this->sperrZustand(),
            'darf' => $vorlage !== null && in_array($this->sperrZustand()['modus'] ?? '', ['aus', 'neu', 'bearbeiten'], true),
        ]);
    }

    private function laden(): void
    {
        $v = FoodAlchemistOrderTemplate::where('team_id', $this->team()->id)->find($this->vorlageId);
        $this->form = $v === null ? ['name' => '', 'kategorie' => '', 'weekday' => '', 'note' => ''] : [
            'name' => (string) $v->name, 'kategorie' => (string) ($v->kategorie ?? ''),
            'weekday' => $v->weekday !== null ? (string) $v->weekday : '', 'note' => (string) ($v->note ?? ''),
        ];
    }

    private function aktion(callable $tu): void
    {
        $this->fehler = null;
        if ($this->vorlageId === null) {
            return;
        }
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
