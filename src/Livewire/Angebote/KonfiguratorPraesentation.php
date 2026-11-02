<?php

namespace Platform\FoodAlchemist\Livewire\Angebote;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;

/**
 * Spec 94 · Reiter „Branding und Präsentation“ des Konfigurator-Editors: Design aus den Präsentations-Designs (Ausgabeform
 * konfigurator), Branding wie beim Angebot, „Branding übernehmen von …“ (Foodbook, Angebot, Betrieb — einmalige Kopie),
 * Vorschau, Link veröffentlichen (Ablauf = Gültigkeit) und zurückziehen. Veröffentlichen über PresentationService.
 */
class KonfiguratorPraesentation extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;   // Spec 65: dasselbe Ziel wie der Editor

    public int $konfiguratorId;

    public array $branding = ['brand_color' => '', 'band_color' => '', 'footer_text' => '', 'presentation_design' => 'konfigurator'];

    public string $slug = '';

    public string $quelle = 'foodbook';

    public string $quelleId = '';

    public ?string $fehler = null;

    public ?string $hinweis = null;

    protected function sperrZiel(): ?array
    {
        return ['konfigurator', $this->konfiguratorId];
    }

    public function mount(int $konfiguratorId): void
    {
        $this->konfiguratorId = $konfiguratorId;
        $this->lade();
    }

    #[On('konfigurator-gespeichert')]
    public function lade(): void
    {
        $k = $this->k();
        if ($k === null) {
            return;
        }
        $this->branding = ['brand_color' => (string) $k->brand_color, 'band_color' => (string) $k->band_color, 'footer_text' => (string) $k->footer_text,
            'presentation_design' => (string) ($k->presentation_design ?: 'konfigurator')];
        $this->slug = (string) $k->presentation_slug;
    }

    public function brandingSpeichern(KonfiguratorService $svc): void
    {
        $this->tue(fn () => $svc->branding($this->team(), $this->konfiguratorId, $this->branding), 'Branding gespeichert.');
    }

    public function brandingUebernehmen(KonfiguratorService $svc): void
    {
        if ($this->quelleId === '') {
            $this->fehler = 'Bitte eine Quelle wählen.';

            return;
        }
        if ($this->tue(fn () => $svc->brandingUebernehmen($this->team(), $this->konfiguratorId, $this->quelle, (int) $this->quelleId), 'Branding übernommen.')) {
            $this->lade();
        }
    }

    public function veroeffentlichen(KonfiguratorService $svc): void
    {
        $ergebnis = null;
        if ($this->tue(function () use ($svc, &$ergebnis) {
            $svc->branding($this->team(), $this->konfiguratorId, $this->branding);
            $ergebnis = $svc->veroeffentlichen($this->team(), $this->konfiguratorId, ['design' => $this->branding['presentation_design'], 'slug' => $this->slug]);
        }, null)) {
            $this->hinweis = 'Veröffentlicht bis ' . \Illuminate\Support\Carbon::parse($ergebnis['expires_at'])->format('d.m.Y') . '.';
            $this->dispatch('konfigurator-gespeichert');
        }
    }

    public function zurueckziehen(KonfiguratorService $svc): void
    {
        $this->tue(fn () => $svc->zurueckziehen($this->team(), $this->konfiguratorId), 'Link zurückgezogen.');
    }

    public function render(PresentationDesignService $designs, PresentationService $praes)
    {
        $team = $this->team();
        $k = $this->k();
        $quellen = match ($this->quelle) {
            'angebot' => FoodAlchemistAngebot::visibleToTeam($team)->orderByDesc('id')->limit(100)->pluck('name', 'id'),
            'betrieb' => FoodAlchemistOutlet::visibleToTeam($team)->orderBy('name')->pluck('name', 'id'),
            default => FoodAlchemistFoodbook::visibleToTeam($team)->orderByDesc('id')->limit(100)->pluck('label', 'id'),
        };

        return view('foodalchemist::livewire.angebote.konfigurator-praesentation', [
            'k' => $k,
            'sperr' => $this->sperrZustand(),
            'designOptionen' => collect($designs->pickerOptions($team, PresentationService::TYPE_KONFIGURATOR))->pluck('label', 'value')->all(),
            'quellen' => $quellen->all(),
            'link' => $k?->presentationPublicRef() ? route('foodalchemist.presentation.show', ['type' => 'konfigurator', 'token' => $k->presentationPublicRef()]) : null,
            'live' => $k?->isPresentationLive() ?? false,
            'designGeaendert' => $k !== null && $praes->designGeaendertSeitPublish($k),
        ]);
    }

    private function k(): ?FoodAlchemistKonfigurator
    {
        return FoodAlchemistKonfigurator::visibleToTeam($this->team())->find($this->konfiguratorId);
    }

    private function tue(callable $aktion, ?string $erfolg): bool
    {
        $this->fehler = null;
        $this->hinweis = null;
        try {
            $aktion();
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return false;
        }
        if ($erfolg !== null) {
            $this->hinweis = $erfolg;
        }

        return true;
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
