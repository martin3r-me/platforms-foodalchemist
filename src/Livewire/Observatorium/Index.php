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
use Platform\FoodAlchemist\Services\ObservatoriumBriefingService;
use Platform\FoodAlchemist\Services\ObservatoriumService;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Support\FaShell;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Support\ObservatoriumInhalt as Inhalt;
use Platform\FoodAlchemist\Support\ObservatoriumSicht;

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

    /** Lese-Detail eines Radar-Trends (für alle, auch Mandanten). */
    #[Url(as: 'trend')]
    public ?int $trendId = null;

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

    #[Url]
    public int $wettbewerber = 0;

    #[Url]
    public string $briefingBasis = 'entdecken';

    #[Url]
    public string $bewegung = '';

    public ?string $meldung = null;

    /** Team-Pinnwand: Formular „Eigene Idee“ */
    public string $ideeTitel = '';

    public string $ideeNotiz = '';

    public string $ideeLink = '';

    /** „Mein Briefing“-Einstellung (persönlich): Formular offen + Entwurf */
    public bool $profilOffen = false;

    public array $profil = ['branchen' => [], 'region' => '', 'tage' => '7', 'themen' => ''];

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
        return ['ansichtSetzen', 'oeffnen', 'schliessen', 'merken', 'trendPruefen', 'anpinnen', 'ideeAnpinnen', 'pinNotiz', 'pinEntfernen', 'filterZuruecksetzen', 'radarFilterZuruecksetzen', 'profilBearbeiten', 'profilSpeichern', 'profilAbbrechen', 'profilLoeschen', 'setPage', 'nextPage', 'previousPage', 'gotoPage'];
    }

    public function ansichtSetzen(string $ansicht): void
    {
        if (! isset(Inhalt::ANSICHTEN[$ansicht]) && $ansicht !== 'briefing') {
            return;
        }
        if ($ansicht === 'briefing' && isset(Inhalt::ANSICHTEN[$this->ansicht])) {
            $this->briefingBasis = $this->ansicht;
        }
        $this->ansicht = $ansicht;
        $this->artikelId = null;
        $this->trendId = null;
        $this->meldung = null;
        $this->resetPage();
    }

    public function updated($name): void
    {
        if (in_array($name, ['suche', 'branche', 'region', 'tage', 'markt', 'pruefen', 'ansicht', 'wettbewerber', 'bewegung'], true)) {
            $this->resetPage();
            $this->artikelId = null;
        }
    }

    public function profilBearbeiten(): void
    {
        $p = app(ObservatoriumBriefingService::class)->profil($this->team(), (int) Auth::id());
        $this->profil = ['branchen' => $p?->branchen ?? [], 'region' => (string) ($p?->region ?? ''), 'tage' => (string) ($p?->tage ?? '7'),
            'themen' => implode(', ', $p?->themen ?? [])];
        $this->profilOffen = true;
    }

    public function profilAbbrechen(): void
    {
        $this->profilOffen = false;
    }

    public function profilSpeichern(): void
    {
        try {
            app(ObservatoriumBriefingService::class)->speichern($this->team(), (int) Auth::id(), $this->profil);
        } catch (\RuntimeException $e) {
            $this->meldung = $e->getMessage();

            return;
        }
        $this->profilOffen = false;
        $this->meldung = 'Dein Briefing ist eingestellt.';
        $this->resetPage();
    }

    public function profilLoeschen(): void
    {
        app(ObservatoriumBriefingService::class)->loeschen($this->team(), (int) Auth::id());
        $this->profilOffen = false;
        $this->meldung = 'Einstellung gelöscht. Das Briefing zeigt wieder deine aktuellen Filter.';
        $this->resetPage();
    }

    public function filterZuruecksetzen(): void
    {
        $this->reset('suche', 'branche', 'region', 'tage', 'markt', 'pruefen', 'artikelId', 'wettbewerber', 'bewegung');
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

    public function trendOeffnen(int $id): void
    {
        app(ObservatoriumService::class)->radarTrend($this->team(), $id);   // Sichtbarkeit + Radar-Status prüfen, sonst 404
        $this->trendId = $id;
    }

    public function trendSchliessen(): void
    {
        $this->trendId = null;
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

    /** Team-Pinnwand: Meldung für alle im Team anheften (eigenes Team, nicht beim Master). */
    public function anpinnen(int $id): void
    {
        $this->pinnwandAktion(fn ($svc, $team) => $svc->meldungAnpinnen($team, $id, null, (int) Auth::id()), 'An die Team-Pinnwand geheftet. Alle im Team sehen es.');
    }

    public function ideeAnpinnen(): void
    {
        $this->pinnwandAktion(function ($svc, $team) {
            $svc->ideeAnpinnen($team, $this->ideeTitel, $this->ideeNotiz, $this->ideeLink, (int) Auth::id());
            $this->reset('ideeTitel', 'ideeNotiz', 'ideeLink');
        }, 'Deine Idee hängt an der Team-Pinnwand.');
    }

    public function pinNotiz(int $pinId, string $notiz): void
    {
        $this->pinnwandAktion(fn ($svc, $team) => $svc->notizSetzen($team, $pinId, $notiz), 'Notiz gespeichert.');
    }

    public function pinEntfernen(int $pinId): void
    {
        $this->pinnwandAktion(fn ($svc, $team) => $svc->entfernen($team, $pinId), 'Von der Team-Pinnwand genommen.');
    }

    private function pinnwandAktion(\Closure $tun, string $ok): void
    {
        try {
            $tun(app(\Platform\FoodAlchemist\Services\ObservatoriumPinnwandService::class), $this->team());
            $this->meldung = $ok;
        } catch (\RuntimeException $e) {
            $this->meldung = $e->getMessage();
        }
    }

    /** Vertiefung anstoßen (Kuratieren, eigenes Team): Kernaussagen mit Beleg aus der verlinkten Seite. Plattform-KI im Hintergrund. */
    public function vertiefen(int $id): void
    {
        $this->nurKuratoren();
        try {
            $ids = app(\Platform\FoodAlchemist\Services\TrendVertiefungService::class)->anstossen($this->team(), [$id], (int) Auth::id(), true);
            $this->meldung = $ids === [] ? 'Die Vertiefung läuft bereits.' : 'Vertiefung gestartet: Die Quelle wird gelesen, die Kernaussagen erscheinen hier in Kürze.';
        } catch (\RuntimeException $e) {
            $this->meldung = $e->getMessage();
        }
    }

    /** Knopf „Einordnung erzeugen“: KI-Einordnung aus den gespeicherten Marktdaten (Kuratieren, Plattform-KI im Hintergrund). */
    public function einordnungErzeugen(): void
    {
        $this->nurKuratoren();
        try {
            $r = app(\Platform\FoodAlchemist\Services\MarktEinordnungService::class)->einordnen($this->team(), (int) Auth::id());
            $this->meldung = $r['angestossen'] ? 'Einordnung wird erstellt. Sie erscheint nach dem Neuladen.' : $r['grund'];
        } catch (\RuntimeException $e) {
            $this->meldung = $e->getMessage();
        }
    }

    public function aufPinnwand(int $id): void
    {
        $this->nurKuratoren();
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
        $filter = ['ansicht' => $this->ansicht === 'briefing' && isset(Inhalt::ANSICHTEN[$this->briefingBasis]) ? $this->briefingBasis : $this->ansicht, 'suche' => $this->suche, 'branche' => $this->branche,
            'region' => $this->region, 'tage' => $this->tage, 'markt' => $this->markt, 'pruefen' => $this->pruefen, 'wettbewerber' => $this->wettbewerber, 'bewegung' => $this->bewegung];
        // „Mein Briefing“ mit gespeicherter Einstellung: das Profil ersetzt die gerade offenen Filter
        $briefingProfil = $this->ansicht === 'briefing' ? app(ObservatoriumBriefingService::class)->profil($team, (int) Auth::id()) : null;
        if ($briefingProfil !== null) {
            $filter = ObservatoriumBriefingService::filter($briefingProfil);
        }
        $eintraege = $svc->gruppiert($team, $filter)->paginate(12);
        $gewaehlt = $this->artikelId ? $svc->detail($team, $this->artikelId) : null;
        $alleMerker = $svc->merker($team)->where(fn ($w) => $w->where('gemerkt', true)->orWhere('trend_pruefen', true))->get();
        // Observatorium zentral: Trends, Quellen-Zähler und Lagebild kommen vom Master (Rechte am eigenen Team geprüft)
        $inhalt = ObservatoriumSicht::inhaltsTeam($team);
        $alleRadarTrends = app(TrendService::class)->liste($inhalt, ['nur_radar' => true]);
        $trends = app(TrendService::class)->liste($inhalt, ['nur_radar' => true,
            'suche' => $this->radarSuche, 'kategorie' => $this->kategorien, 'sparte' => $this->sparten,
            'typ' => $this->typen, 'ebene' => $this->ebenen, 'nur_befragung' => $this->nurBefragung]);

        return view('foodalchemist::livewire.observatorium.index', [
            'marktdaten' => $this->ansicht === 'zahlen' ? app(\Platform\FoodAlchemist\Services\MarktdatenService::class)->lesen($team) : null,
            'istHandy' => HandyLesemodus::anfrageVomHandy(),
            'briefingProfil' => $briefingProfil,
            'eintraege' => $eintraege, 'gewaehlt' => $gewaehlt,
            'weitereQuellen' => $gewaehlt ? $svc->weitereQuellen($team, $gewaehlt) : collect(),
            'merker' => $alleMerker->keyBy('eingang_id'), 'merkerAnzahl' => $alleMerker->count(),
            'darfKuratieren' => $this->darfKuratieren($team), 'teamId' => $team->id, 'kuratiertHier' => ObservatoriumSicht::kuratiertHier($team),
            'lagebild' => $this->ansicht === 'entdecken' ? app(\Platform\FoodAlchemist\Services\LagebildService::class)->aktuelles($team) : null,
            'pins' => $this->ansicht === 'pinnwand' ? app(\Platform\FoodAlchemist\Services\ObservatoriumPinnwandService::class)->liste($team) : collect(),
            'angepinnt' => app(\Platform\FoodAlchemist\Services\ObservatoriumPinnwandService::class)->angepinnteMeldungen($team),
            'darfPinnen' => app(FaRechte::class)->darf(Auth::user(), $team, FaRolle::Kuratieren),
            'lagebilder' => $this->ansicht === 'lagebild' ? app(\Platform\FoodAlchemist\Services\LagebildService::class)->liste($team, 4)->all() : [],
            'wettbewerbVergleich' => [],
            'wettbewerberProfile' => collect(),
            'radarGesamt' => $alleRadarTrends->count(),
            'radarFilterAktiv' => $this->radarSuche !== '' || $this->kategorien !== [] || $this->sparten !== [] || $this->typen !== [] || $this->ebenen !== [] || $this->nurBefragung,
            'radar' => app(TrendService::class)->radarPositionen($trends, 260, 260, 220), 'trends' => $trends,
            'trendDetail' => $this->ansicht === 'radar' && $this->trendId ? $svc->radarTrend($team, $this->trendId) : null,
            'quellenAnzahl' => FoodAlchemistTrendQuelle::visibleToTeam($inhalt)->count(),
            'themenAnzahl' => FoodAlchemistTrendQuelle::visibleToTeam($inhalt)->where('url', 'like', 'https://news.google.com/rss/search%')->count(),
        ])->layout(FaShell::layout());
    }

    /** Kuratieren nur im Master-Team (Observatorium zentral) und mit Rolle Kuratieren. */
    private function darfKuratieren(Team $team): bool
    {
        return ObservatoriumSicht::kuratiertHier($team) && app(FaRechte::class)->darf(Auth::user(), $team, FaRolle::Kuratieren);
    }

    private function nurKuratoren(): void
    {
        abort_unless($this->darfKuratieren($this->team()), 403);
    }

    private function team(): Team
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403);
        app(FaRechte::class)->pruefe(Auth::user(), $team, FaRolle::Lesen, 'Observatorium lesen');

        return $team;
    }
}
