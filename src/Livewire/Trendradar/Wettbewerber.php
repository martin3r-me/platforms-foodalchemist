<?php

namespace Platform\FoodAlchemist\Livewire\Trendradar;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Models\FoodAlchemistWettbewerbFund;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\WettbewerbService;
use Platform\FoodAlchemist\Support\GoogleNewsSuche;
use Platform\FoodAlchemist\Support\HandyLesemodus;

class Wettbewerber extends Component
{
    use HandyLesebereich;
    use WithPagination;

    public int $profilId = 0;

    #[Locked]
    public ?int $editId = null;

    public bool $editorOffen = false;

    public array $form = [];

    public array $bewertung = [];

    public string $status = 'vorgeschlagen';

    public string $artikelSuche = '';

    public ?string $meldung = null;

    public function handyLeseMethoden(): array
    {
        return ['waehlen', 'setPage', 'nextPage', 'previousPage', 'gotoPage'];
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }

    private function kuratieren(): void
    {
        app(FaRechte::class)->pruefe(Auth::user(), $this->team(), FaRolle::Kuratieren, 'Wettbewerber bearbeiten');
    }

    public function updatedStatus(): void
    {
        $this->resetPage('wettbewerbSeite');
    }

    public function waehlen(int $id): void
    {
        app(WettbewerbService::class)->detail($this->team(), $id);
        $this->profilId = $id;
        $this->bewertung = [];
        $this->artikelSuche = '';
        $this->meldung = null;
        $this->editorOffen = false;
        $this->resetPage('wettbewerbSeite');
    }

    public function neu(): void
    {
        $this->kuratieren();
        $this->editId = null;
        $this->form = ['name' => '', 'website' => '', 'aliases' => '', 'suchbegriffe' => '', 'ausschliessen' => '', 'branchen' => [],
            'region' => 'DE', 'gebiet' => '', 'notiz' => '', 'aktiv' => true, 'rss_aktiv' => false, 'rhythmus' => 'woechentlich', 'presse_feed' => '', 'quellen_ids' => []];
        $this->editorOffen = true;
        $this->resetValidation();
    }

    public function bearbeiten(int $id): void
    {
        $this->kuratieren();
        $p = app(WettbewerbService::class)->detail($this->team(), $id);
        abort_unless((int) $p->team_id === (int) $this->team()->id, 403);
        $this->neu();
        $this->editId = $id;
        foreach (array_keys($this->form) as $feld) {
            if ($feld !== 'presse_feed') {
                $this->form[$feld] = $p->$feld ?? $this->form[$feld];
            }
        }
        foreach (['aliases', 'suchbegriffe', 'ausschliessen'] as $feld) {
            $this->form[$feld] = implode(', ', $p->$feld ?? []);
        }
        $this->form['presse_feed'] = $p->presse_quelle_id ? (FoodAlchemistTrendQuelle::where('team_id', $this->team()->id)->find($p->presse_quelle_id)?->url ?? '') : '';
    }

    public function speichern(): void
    {
        $this->kuratieren();
        $daten = $this->form;
        foreach (['aliases', 'suchbegriffe', 'ausschliessen'] as $feld) {
            $daten[$feld] = GoogleNewsSuche::begriffe((string) ($daten[$feld] ?? ''));
        }
        try {
            $p = app(WettbewerbService::class)->speichern($this->team(), $daten, $this->editId);
            $this->waehlen($p->id);
            $this->meldung = 'Profil gespeichert. Bestehende Eingänge können jetzt kostenlos auf Firmenbezug geprüft werden.';
        } catch (\RuntimeException $e) {
            $this->addError('profil', $e->getMessage());
        }
    }

    public function bestandPruefen(): void
    {
        $this->kuratieren();
        $n = app(WettbewerbService::class)->bestandPruefen($this->team(), $this->profilId);
        $this->meldung = $n.' neue Zuordnungsvorschläge. Keine KI-Auswertung gestartet.';
    }

    public function entscheiden(int $eingangId, string $status): void
    {
        $this->kuratieren();
        $f = FoodAlchemistWettbewerbFund::visibleToTeam($this->team())->where('wettbewerber_id', $this->profilId)->where('eingang_id', $eingangId)->first();
        $daten = $this->bewertung[$eingangId] ?? [];
        app(WettbewerbService::class)->entscheiden($this->team(), $this->profilId, $eingangId, $status, $daten['bewegung'] ?? $f?->bewegung ?? 'meldung', $daten['einordnung'] ?? $f?->einordnung);
        $this->meldung = $status === 'bestaetigt' ? 'Firmenbezug bestätigt. Die Meldung ist im Observatorium sichtbar.' : 'Zuordnung aktualisiert.';
    }

    public function render()
    {
        $team = $this->team();
        $svc = app(WettbewerbService::class);
        $profile = $svc->profile($team);
        $p = $this->profilId ? $svc->detail($team, $this->profilId) : null;

        $funde = $p ? $svc->funde($team, $p->id, $this->status) : null;
        foreach ($funde ?? [] as $fund) {
            $this->bewertung[$fund->eingang_id] ??= ['bewegung' => $fund->bewegung, 'einordnung' => $fund->einordnung ?? ''];
        }

        return view('foodalchemist::livewire.trendradar.wettbewerber', [
            'profile' => $profile, 'profil' => $p, 'funde' => $funde,
            'darf' => app(FaRechte::class)->darf(Auth::user(), $team, FaRolle::Kuratieren), 'istHandy' => HandyLesemodus::anfrageVomHandy(),
            'teamId' => $team->id, 'quellen' => FoodAlchemistTrendQuelle::where('team_id', $team->id)->orderBy('name')->get(),
            'manuelleTreffer' => $p && mb_strlen(trim($this->artikelSuche)) >= 3
                ? FoodAlchemistTrendEingang::where('team_id', $team->id)->where('titel', 'like', '%'.mb_substr(trim($this->artikelSuche), 0, 200).'%')->orderByDesc('id')->limit(15)->get() : collect(),
        ]);
    }
}
