<?php

namespace Platform\FoodAlchemist\Livewire\Angebote;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\FoodAlchemistMediaService;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;

/**
 * Spec 94 · Reiter „Branding und Präsentation“ des Konfigurator-Editors: Design aus den Präsentations-Designs (Ausgabeform
 * konfigurator), Branding wie beim Angebot, „Branding übernehmen von …“ (Foodbook, Angebot, Betrieb — einmalige Kopie),
 * Vorschau, Link veröffentlichen (Ablauf = Gültigkeit) und zurückziehen. Veröffentlichen über PresentationService.
 * Aufbau wie im Foodbook (Präsentation für den Kunden, Logo/Titelbild über x-foodalchemist::branding-bilder); „Gültig bis“ ist
 * im Editor Pflicht. Ohne Kontakt-Knopf (die Anfrage ersetzt ihn) und ohne Preis-Schutz (der Snapshot rechnet stets neu).
 */
class KonfiguratorPraesentation extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;   // Spec 65: dasselbe Ziel wie der Editor
    use WithFileUploads;

    public int $konfiguratorId;

    /** Design und Betrieb (Logo-Rückfall). Farben kommen aus dem Design, keine Farbwähler (Dominique 2026-11-03). */
    public array $branding = ['presentation_design' => 'konfigurator', 'outlet_id' => ''];

    public string $slug = '';

    /** Ablauf des Kundenlinks (Y-m-d), Pflicht zum Veröffentlichen; gespeichert als gueltig_bis am Konfigurator. */
    public string $gueltigBis = '';

    public $logoUpload = null;

    public $coverUpload = null;

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
        $this->branding = ['presentation_design' => (string) ($k->presentation_design ?: 'konfigurator'), 'outlet_id' => (string) ($k->outlet_id ?? '')];
        $this->slug = (string) $k->presentation_slug;
        $this->gueltigBis = (string) $k->gueltig_bis?->format('Y-m-d');
    }

    /** Der Editor meldet einen Wechsel der Bearbeitungssperre; das Neu-Rendern setzt `$gesperrt` aus sperrZustand(). */
    #[On('konfigurator-sperre')]
    public function sperreGeaendert(): void {}

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

    public function updatedLogoUpload(KonfiguratorService $svc): void
    {
        $this->bildHochladen($svc, 'logo');
    }

    public function updatedCoverUpload(KonfiguratorService $svc): void
    {
        $this->bildHochladen($svc, 'cover');
    }

    public function brandingLogoEntfernen(KonfiguratorService $svc): void
    {
        $this->tue(fn () => $svc->bildEntfernen($this->team(), $this->konfiguratorId, 'logo'), 'Logo entfernt.');
    }

    public function brandingCoverEntfernen(KonfiguratorService $svc): void
    {
        $this->tue(fn () => $svc->bildEntfernen($this->team(), $this->konfiguratorId, 'cover'), 'Titelbild entfernt.');
    }

    /** Auto-Upload bei Dateiwahl wie im Foodbook: prüfen, Service (ersetzt das alte Bild), Feld leeren. */
    private function bildHochladen(KonfiguratorService $svc, string $art): void
    {
        $prop = $art === 'logo' ? 'logoUpload' : 'coverUpload';
        if ($this->uploadGesperrt($prop) || $this->{$prop} === null) {
            return;
        }
        $this->validate([$prop => 'image|max:8192'], [], [$prop => $art === 'logo' ? 'Logo' : 'Titelbild']);
        $this->tue(fn () => $svc->bildSpeichern($this->team(), $this->konfiguratorId, $art, $this->{$prop}), $art === 'logo' ? 'Logo gespeichert.' : 'Titelbild gespeichert.');
        $this->reset($prop);
    }

    /** Spec 65: Datei-Uploads laufen nicht über den call-Haken, darum hier selbst prüfen (wie Angebots-Editor). */
    private function uploadGesperrt(string $prop): bool
    {
        if (self::sperreAktiv() && ! $this->darfSchreiben()) {
            $this->reset($prop);
            $this->sperreAbgewiesen('updated'.ucfirst($prop));

            return true;
        }

        return false;
    }

    public function veroeffentlichen(KonfiguratorService $svc): void
    {
        if ($this->gueltigBis === '') {
            $this->fehler = 'Zum Veröffentlichen ein Datum bei „Gültig bis“ setzen.';
            $this->hinweis = null;

            return;
        }
        $ergebnis = null;
        if ($this->tue(function () use ($svc, &$ergebnis) {
            $svc->branding($this->team(), $this->konfiguratorId, $this->branding);
            $ergebnis = $svc->veroeffentlichen($this->team(), $this->konfiguratorId,
                ['design' => $this->branding['presentation_design'], 'slug' => $this->slug, 'gueltig_bis' => $this->gueltigBis]);
        }, null)) {
            $this->hinweis = 'Veröffentlicht bis ' . \Illuminate\Support\Carbon::parse($ergebnis['expires_at'])->format('d.m.Y') . '.';
            $this->dispatch('konfigurator-gespeichert');
        }
    }

    public function zurueckziehen(KonfiguratorService $svc): void
    {
        $this->tue(fn () => $svc->zurueckziehen($this->team(), $this->konfiguratorId), 'Link zurückgezogen.');
    }

    public function render(PresentationDesignService $designs, PresentationService $praes, FoodAlchemistMediaService $medien)
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
            'logoUrl' => $k?->logo_path ? $medien->url($k->logo_context_file_id, $k->logo_path) : null,
            'betriebsLogo' => $k !== null && ($w = $k->wirksamesLogo())['aus_betrieb'] ? $medien->url($w['context_file_id'], $w['path']) : null,
            'betriebe' => FoodAlchemistOutlet::visibleToTeam($team)->orderBy('name')->pluck('name', 'id')->all(),
            'coverUrl' => $k?->cover_image_path ? $medien->url($k->cover_context_file_id, $k->cover_image_path) : null,
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
