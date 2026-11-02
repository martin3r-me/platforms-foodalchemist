<?php

namespace Platform\FoodAlchemist\Livewire\Angebote;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Services\KonfiguratorService;

/**
 * Spec 94 · Angebote → Konfiguratoren. Liste der Konfiguratoren; ein Klick öffnet den Konfigurator-Editor im Vollbild
 * ({@see KonfiguratorEditor}), aufgebaut wie der Angebots-Editor.
 *
 * Stufe 6 · Am Handy (#1003, HandyLesemodus) eigene Ansicht: Liste mit Status, Link und offenen Anfragen; Detail mit
 * Kennzahlen, Preisen je Paket (nur lesen), Link teilen, veröffentlichen/zurückziehen samt „Anfragen gehen an“ und
 * „Gültig bis“, offene Anfragen. Matrix, Leistungen, Vorlagen und Branding bleiben am Computer.
 */
class Konfiguratoren extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;

    /** Handy-Formular „Veröffentlichen“. */
    public array $handyLink = ['verantwortlich_user_id' => '', 'gueltig_bis' => ''];

    public ?string $hinweis = null;

    /**
     * Am Handy erlaubt: blättern und Link veröffentlichen/zurückziehen (Dominique: Link am Handy teilen und schalten).
     * Bewusst hier je Komponente statt in config handy.aendern_frei — die Freigabe gilt nur für den Kundenlink.
     */
    public function handyLeseMethoden(): array
    {
        return ['waehle', 'handyZurListe', 'handyVeroeffentlichen', 'handyZurueckziehen'];
    }
    #[Url(as: 'q')]
    public string $suche = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'sel')]
    public ?int $selectedId = null;

    public ?string $fehler = null;

    public function mount(): void
    {
        if ($this->selectedId !== null) {
            $this->dispatch('konfigurator-editor.oeffnen', id: $this->selectedId)->to(KonfiguratorEditor::class);
        }
    }

    public function waehle(int $id): void
    {
        $this->selectedId = $id;
        $this->fehler = null;
        $this->hinweis = null;
        if (\Platform\FoodAlchemist\Support\HandyLesemodus::anfrageVomHandy()) {
            $k = FoodAlchemistKonfigurator::visibleToTeam($this->team())->find($id);
            $this->handyLink = ['verantwortlich_user_id' => (string) ($k?->verantwortlich_user_id ?? ''), 'gueltig_bis' => $k?->gueltig_bis?->format('Y-m-d') ?? ''];

            return;
        }
        $this->dispatch('konfigurator-editor.oeffnen', id: $id)->to(KonfiguratorEditor::class);
    }

    public function handyZurListe(): void
    {
        $this->selectedId = null;
        $this->fehler = null;
        $this->hinweis = null;
    }

    /** Am Handy freigeschaltet (handyLeseMethoden): derselbe Weg wie im Editor und per MCP. */
    public function handyVeroeffentlichen(KonfiguratorService $svc): void
    {
        $this->fehler = null;
        $this->hinweis = null;
        try {
            $erg = $svc->veroeffentlichen($this->team(), (int) $this->selectedId, [
                'verantwortlich_user_id' => $this->handyLink['verantwortlich_user_id'] !== '' ? (int) $this->handyLink['verantwortlich_user_id'] : null,
                'gueltig_bis' => $this->handyLink['gueltig_bis'] !== '' ? $this->handyLink['gueltig_bis'] : null,
            ]);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->hinweis = 'Veröffentlicht bis ' . \Illuminate\Support\Carbon::parse($erg['expires_at'])->format('d.m.Y') . '.';
    }

    public function handyZurueckziehen(KonfiguratorService $svc): void
    {
        $this->fehler = null;
        try {
            $svc->zurueckziehen($this->team(), (int) $this->selectedId);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->hinweis = 'Link zurückgezogen.';
    }

    public function neu(KonfiguratorService $svc): void
    {
        $this->fehler = null;
        try {
            $k = $svc->anlegen($this->team(), ['name' => 'Neuer Konfigurator']);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->waehle($k->id);
    }

    #[On('konfigurator-gespeichert')]
    public function aktualisiere(): void
    {
    }

    #[On('konfigurator-geloescht')]
    public function entfernt(?int $id = null): void
    {
        if ($id === $this->selectedId) {
            $this->selectedId = null;
        }
    }

    public function render()
    {
        if (\Platform\FoodAlchemist\Support\HandyLesemodus::anfrageVomHandy()) {
            return $this->handyAnsicht();
        }
        $team = $this->team();
        $q = trim($this->suche);
        $liste = FoodAlchemistKonfigurator::visibleToTeam($team)
            ->withCount(['konzepte as haupt_count' => fn ($x) => $x->where('rolle', 'haupt'), 'pakete'])
            ->when($this->statusFilter !== '', fn ($x) => $x->where('status', $this->statusFilter))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('kunden_titel', 'like', "%{$q}%")->orWhere('ort', 'like', "%{$q}%")))
            ->orderByDesc('updated_at')->get();

        return view('foodalchemist::livewire.angebote.konfiguratoren', [
            'liste' => $liste,
            'statusWerte' => ['entwurf' => 'Entwurf', 'live' => 'Live', 'archiv' => 'Archiv'],
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }

    /** Handy: Liste oder Detail des gewählten Konfigurators. */
    private function handyAnsicht()
    {
        $team = $this->team();
        $offen = fn () => \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::visibleToTeam($team)
            ->where('status', \Platform\FoodAlchemist\Enums\AngebotStatus::Anfrage->value)->whereNotNull('konfigurator_id');
        $k = $this->selectedId !== null ? FoodAlchemistKonfigurator::visibleToTeam($team)->find($this->selectedId) : null;
        $daten = ['k' => $k, 'statusWerte' => ['entwurf' => 'Entwurf', 'live' => 'Live', 'archiv' => 'Archiv']];

        if ($k === null) {
            $anzahl = $offen()->selectRaw('konfigurator_id, COUNT(*) AS n')->groupBy('konfigurator_id')->pluck('n', 'konfigurator_id');
            $q = trim($this->suche);
            $daten['liste'] = FoodAlchemistKonfigurator::visibleToTeam($team)
                ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('kunden_titel', 'like', "%{$q}%")))
                ->orderByDesc('updated_at')->get()
                ->map(fn ($x) => ['id' => (int) $x->id, 'name' => $x->name, 'titel' => $x->kunden_titel, 'status' => $x->status,
                    'link' => $x->isPresentationLive() ? 'live' : ($x->presentation_enabled && $x->presentation_expires_at?->isPast() ? 'abgelaufen' : 'aus'),
                    'anfragen' => (int) ($anzahl[$x->id] ?? 0)]);
        } else {
            $k->load(['konzepte', 'pakete']);
            $matrix = app(\Platform\FoodAlchemist\Services\KonfiguratorPricingService::class)->matrix($k);
            $ref = $k->presentationPublicRef();
            $daten += [
                'kennzahlen' => KonfiguratorEditor::kennzahlen($k, $matrix),
                'matrix' => $matrix,
                'url' => $ref ? route('foodalchemist.presentation.show', ['type' => 'konfigurator', 'token' => $ref]) : null,
                'live' => $k->isPresentationLive(),
                'anfragen' => $offen()->where('konfigurator_id', $k->id)->latest()->get(),
                'nutzer' => $team->users()->orderBy('name')->get(['users.id', 'users.name'])->pluck('name', 'id')->all(),
                'eigen' => $k->isOwnedBy($team),
            ];
        }

        return view('foodalchemist::livewire.handy.konfiguratoren', $daten)->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }
}
