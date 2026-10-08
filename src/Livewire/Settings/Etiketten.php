<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\EtikettService;
use Platform\FoodAlchemist\Services\PresentationDesignService;

/**
 * Spec 70 · Einstellungen → Etiketten: Vorlagen gestalten (Format, Typ, Felder + Reihenfolge,
 * Datums-Modus vorbelegt/leer, Allergen-Darstellung, Schrift, Logo/Design über Betrieb), mit
 * Live-Vorschau an einem echten Rezept. Pflichtfelder des Typs sind gesperrt.
 */
class Etiketten extends Component
{
    public ?int $vorlageId = null;

    public array $form = [];

    public ?string $fehler = null;

    public ?string $hinweis = null;

    public function mount(EtikettService $svc): void
    {
        $this->laden($svc, $svc->vorlage($this->team(), null)->id);
    }

    public function waehlen(int $id, EtikettService $svc): void
    {
        $this->laden($svc, $id);
    }

    public function neu(EtikettService $svc): void
    {
        $this->aktion(function () use ($svc) {
            $v = $svc->speichern($this->team(), null, ['name' => 'Neue Vorlage']);
            $this->laden($svc, $v->id);
        });
    }

    public function duplizieren(EtikettService $svc): void
    {
        $this->aktion(function () use ($svc) {
            $v = $svc->speichern($this->team(), null, ['name' => $this->form['name'] . ' (Kopie)', 'is_default' => false, 'is_kitchen_default' => false] + array_diff_key($this->form, ['name' => 1, 'is_default' => 1, 'is_kitchen_default' => 1]));
            $this->laden($svc, $v->id);
        });
    }

    public function speichern(EtikettService $svc): void
    {
        $this->aktion(function () use ($svc) {
            $svc->speichern($this->team(), $this->vorlageId, $this->form);
            $this->laden($svc, (int) $this->vorlageId);
            $this->hinweis = 'Vorlage gespeichert.';
        });
    }

    public function loeschen(EtikettService $svc): void
    {
        $this->aktion(function () use ($svc) {
            $svc->loeschen($this->team(), (int) $this->vorlageId);
            $this->laden($svc, $svc->vorlage($this->team(), null)->id);
            $this->hinweis = 'Vorlage gelöscht.';
        });
    }

    public function feldVerschieben(int $i, int $richtung): void
    {
        $j = $i + ($richtung < 0 ? -1 : 1);
        if (! isset($this->form['felder'][$i], $this->form['felder'][$j])) {
            return;
        }
        [$this->form['felder'][$i], $this->form['felder'][$j]] = [$this->form['felder'][$j], $this->form['felder'][$i]];
    }

    /** Typ-Wechsel setzt die Pflichtfelder sofort sichtbar (Speichern normalisiert endgültig). */
    public function updatedFormTyp(): void
    {
        $this->form['felder'] = app(EtikettService::class)->normalisiereFelder((string) $this->form['typ'], $this->form['felder']);
    }

    public function render(EtikettService $svc, PresentationDesignService $designs)
    {
        $team = $this->team();
        $vorlagen = $svc->vorlagen($team);
        // Vorschau an einem echten Rezept: eigenes Basisrezept mit Zutaten, sonst irgendein sichtbares
        $beispiel = FoodAlchemistRecipe::where('team_id', $team->id)->where('is_sales_recipe', false)->whereHas('ingredients')->orderByDesc('id')->value('id')
            ?? FoodAlchemistRecipe::visibleToTeam($team)->whereHas('ingredients')->orderByDesc('id')->value('id');

        return view('foodalchemist::livewire.settings.etiketten', [
            'vorlagen' => $vorlagen,
            'formate' => array_map(fn ($f) => $f['label'], EtikettService::FORMATE),
            'feldKatalog' => EtikettService::FELDER,
            'betriebe' => FoodAlchemistOutlet::where('team_id', $team->id)->orderBy('name')->pluck('name', 'id'),
            'designs' => collect($designs->pickerOptions($team, 'etikett'))->pluck('label', 'value'),
            'vorschauUrl' => $beispiel !== null && $this->vorlageId !== null
                // Version = gespeicherter Stand (updated_at), nicht das Formular — sonst lädt der iframe nach dem Speichern nicht neu
                ? $svc->druckUrl('recipe', (int) $beispiel, (int) $this->vorlageId, [], 3) . '&vorschau=1&v=' . optional($vorlagen->firstWhere('id', $this->vorlageId)?->updated_at)->format('U.u')
                : null,
        ]);
    }

    private function laden(EtikettService $svc, int $id): void
    {
        $v = $svc->vorlage($this->team(), $id);
        $this->vorlageId = $v->id;
        $this->form = [
            'name' => $v->name, 'typ' => $v->typ, 'format' => $v->format, 'outlet_id' => $v->outlet_id !== null ? (string) $v->outlet_id : '',
            'presentation_design' => (string) ($v->presentation_design ?? ''), 'felder' => $svc->normalisiereFelder((string) $v->typ, $v->felder),
            'allergen_darstellung' => $v->allergen_darstellung, 'schriftgroesse' => $v->schriftgroesse,
            'datum_gross' => (bool) $v->datum_gross, 'zeige_logo' => (bool) $v->zeige_logo, 'fusstext' => (string) ($v->fusstext ?? ''), 'is_default' => (bool) $v->is_default, 'is_kitchen_default' => (bool) $v->is_kitchen_default,
        ];
        $this->fehler = null;
    }

    private function aktion(callable $tu): void
    {
        $this->fehler = null;
        $this->hinweis = null;
        try {
            $tu();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Vorlage nicht gefunden.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
