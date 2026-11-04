<?php

namespace Platform\FoodAlchemist\Livewire\Observatorium;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\ObservatoriumService;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Support\FaShell;
use Platform\FoodAlchemist\Support\ObservatoriumInhalt as Inhalt;

class Index extends Component
{
    use HandyLesebereich;
    use WithPagination;

    #[Url(as: 'blick')]
    public string $ansicht = 'entdecken';

    #[Url(as: 'q')]
    public string $suche = '';

    #[Url]
    public string $branche = '';

    #[Url]
    public string $region = '';

    #[Url]
    public string $tage = '30';

    #[Url]
    public string $markt = '';

    #[Url]
    public bool $pruefen = false;

    #[Url(as: 'artikel')]
    public ?int $artikelId = null;

    #[Url(as: 'radar_q')]
    public string $radarSuche = '';

    #[Url(as: 'kat')]
    public array $kategorien = [];

    #[Url(as: 'sparte')]
    public array $sparten = [];

    #[Url(as: 'typ')]
    public array $typen = [];

    #[Url(as: 'ebene')]
    public array $ebenen = [];

    #[Url(as: 'befragung')]
    public bool $nurBefragung = false;

    public ?string $meldung = null;

    public function mount(): void
    {
        $this->team();
        if (! isset(Inhalt::ANSICHTEN[$this->ansicht]) && $this->ansicht !== 'briefing') {
            $this->ansicht = 'entdecken';
        }
    }

    public function handyLeseMethoden(): array
    {
        // Merken/Prüfliste sind persönliche Präferenzen. Pinnwand-Redaktion bleibt am Handy gesperrt.
        return ['ansichtSetzen', 'oeffnen', 'schliessen', 'merken', 'trendPruefen', 'filterZuruecksetzen', 'radarFilterZuruecksetzen', 'setPage', 'nextPage', 'previousPage', 'gotoPage'];
    }

    public function ansichtSetzen(string $ansicht): void
    {
        if (! isset(Inhalt::ANSICHTEN[$ansicht]) && $ansicht !== 'briefing') {
            return;
        }
        $this->ansicht = $ansicht;
        $this->artikelId = null;
        $this->meldung = null;
        $this->resetPage();
    }

    public function updated($name): void
    {
        if (in_array($name, ['suche', 'branche', 'region', 'tage', 'markt', 'pruefen', 'ansicht'], true)) {
            $this->resetPage();
            $this->artikelId = null;
        }
    }

    public function filterZuruecksetzen(): void
    {
        $this->reset('suche', 'branche', 'region', 'tage', 'markt', 'pruefen', 'artikelId');
        $this->resetPage();
    }

    public function radarFilterZuruecksetzen(): void
    {
        $this->reset('radarSuche', 'kategorien', 'sparten', 'typen', 'ebenen', 'nurBefragung');
    }

    public function oeffnen(int $id): void
    {
        app(ObservatoriumService::class)->detail($this->team(), $id);
        $this->artikelId = $id;
        $this->meldung = null;
    }

    public function schliessen(): void
    {
        $this->artikelId = null;
        $this->meldung = null;
    }

    public function merken(int $id): void
    {
        $m = app(ObservatoriumService::class)->merken($this->team(), $id);
        $this->meldung = $m->gemerkt ? 'In deiner Merkliste gespeichert.' : 'Aus deiner Merkliste entfernt.';
    }

    public function trendPruefen(int $id): void
    {
        $m = app(ObservatoriumService::class)->merken($this->team(), $id, true);
        $this->meldung = $m->trend_pruefen ? 'In deiner persönlichen Trend-Prüfliste vorgemerkt. Noch kein Trendbeleg.' : 'Aus deiner Trend-Prüfliste entfernt.';
    }

    public function aufPinnwand(int $id): void
    {
        abort_unless(app(FaRechte::class)->darf(Auth::user(), $this->team(), FaRolle::Kuratieren), 403);
        try {
            app(ObservatoriumService::class)->pinnwand($this->team(), $id);
            $this->meldung = 'Als Inspiration auf die Pinnwand gelegt.';
        } catch (\RuntimeException $e) {
            $this->meldung = $e->getMessage();
        }
    }

    public function render()
    {
        $team = $this->team();
        $svc = app(ObservatoriumService::class);
        $filter = ['ansicht' => $this->ansicht, 'suche' => $this->suche, 'branche' => $this->branche,
            'region' => $this->region, 'tage' => $this->tage, 'markt' => $this->markt, 'pruefen' => $this->pruefen];
        $eintraege = $svc->gruppiert($team, $filter)->paginate(12);
        $gewaehlt = $this->artikelId ? $svc->detail($team, $this->artikelId) : null;
        $alleMerker = $svc->merker($team)->where(fn ($w) => $w->where('gemerkt', true)->orWhere('trend_pruefen', true))->get();
        $alleRadarTrends = app(TrendService::class)->liste($team, ['nur_radar' => true]);
        $trends = app(TrendService::class)->liste($team, ['nur_radar' => true,
            'suche' => $this->radarSuche, 'kategorie' => $this->kategorien, 'sparte' => $this->sparten,
            'typ' => $this->typen, 'ebene' => $this->ebenen, 'nur_befragung' => $this->nurBefragung]);

        return view('foodalchemist::livewire.observatorium.index', [
            'eintraege' => $eintraege, 'gewaehlt' => $gewaehlt,
            'weitereQuellen' => $gewaehlt ? $svc->weitereQuellen($team, $gewaehlt) : collect(),
            'merker' => $alleMerker->keyBy('eingang_id'), 'merkerAnzahl' => $alleMerker->count(),
            'darfKuratieren' => app(FaRechte::class)->darf(Auth::user(), $team, FaRolle::Kuratieren), 'teamId' => $team->id,
            'radarGesamt' => $alleRadarTrends->count(),
            'radarFilterAktiv' => $this->radarSuche !== '' || $this->kategorien !== [] || $this->sparten !== [] || $this->typen !== [] || $this->ebenen !== [] || $this->nurBefragung,
            'radar' => app(TrendService::class)->radarPositionen($trends, 260, 260, 220), 'trends' => $trends,
            'quellenAnzahl' => FoodAlchemistTrendQuelle::visibleToTeam($team)->count(),
            'themenAnzahl' => FoodAlchemistTrendQuelle::visibleToTeam($team)->where('url', 'like', 'https://news.google.com/rss/search%')->count(),
        ])->layout(FaShell::layout());
    }

    private function team(): Team
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403);
        app(FaRechte::class)->pruefe(Auth::user(), $team, FaRolle::Lesen, 'Observatorium lesen');

        return $team;
    }
}
