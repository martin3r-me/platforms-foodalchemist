<?php

namespace Platform\FoodAlchemist\Livewire\Trendradar;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendQuellenService;
use Platform\FoodAlchemist\Services\Trends\QuellenAbruf;
use Platform\FoodAlchemist\Support\GoogleNewsSuche;
use Platform\FoodAlchemist\Support\StichwortFilter;

class Beobachten extends Component
{
    public array $form = ['name' => '', 'begriffe' => '', 'ausschliessen' => '', 'ausdruck' => '', 'markt' => 'DE', 'zeitraum' => '30d', 'rhythmus' => 'woechentlich'];

    #[Locked]
    public ?int $quelleId = null;

    public ?array $vorschau = null;

    public ?string $meldung = null;

    public ?string $fehler = null;

    public function updatedForm(): void
    {
        $this->vorschau = null;
    }

    public function neu(): void
    {
        $this->reset('form', 'quelleId', 'vorschau', 'meldung', 'fehler');
        $this->resetValidation();
        $this->dispatch('modal.open', name: 'suchthema-editor');
    }

    #[On('modal.closed')]
    public function editorGeschlossen(string $name = ''): void
    {
        if ($name === 'suchthema-editor') {
            $this->reset('form', 'quelleId', 'vorschau', 'fehler');
            $this->resetValidation();
        }
    }

    public function bearbeiten(int $id): void
    {
        $team = $this->team();
        $this->pruefen($team);
        $q = $this->eigene($team, $id);
        $this->neu();
        $this->quelleId = $q->id;
        $this->form = array_merge($this->form, GoogleNewsSuche::lesen($q->url), ['name' => $q->name, 'rhythmus' => $q->rhythmus]);
    }

    public function trefferAnsehen(): void
    {
        $team = $this->team();
        $this->pruefen($team);
        $this->validieren();
        $this->vorschau = null;
        $this->fehler = null;
        try {
            $q = new FoodAlchemistTrendQuelle(['url' => GoogleNewsSuche::url($this->form), 'feed_typ' => 'rss']);
            $items = app(QuellenAbruf::class)->lesen($q);
            // Bei bestehenden Quellen gelten deren Filter; bei neuen Quellen die Team-Standards.
            $svc = app(TrendQuellenService::class);
            $words = $this->quelleId ? $svc->stichworte($this->eigene($team, $this->quelleId))
                : app(TeamSettingsService::class)->trendStichworte($team);
            $kept = array_values(array_filter($items, fn ($i) => StichwortFilter::pruefen($i['titel'].' '.($i['teaser'] ?? ''), $words['ein'], $words['aus'])['durch']));
            $this->vorschau = ['anzahl' => count($items), 'gefiltert' => count($items) - count($kept), 'treffer' => array_slice($kept, 0, 10)];
        } catch (\RuntimeException $e) {
            $this->fehler = 'Vorschau fehlgeschlagen: '.$e->getMessage();
        }
    }

    public function speichern(): void
    {
        $team = $this->team();
        $this->pruefen($team);
        $this->validieren();
        $this->fehler = null;
        try {
            DB::transaction(function () use ($team) {
                $url = GoogleNewsSuche::url($this->form);
                if (strlen($url) > 2048) {
                    throw new \RuntimeException('Die Suche ist zu lang. Bitte weniger oder kürzere Begriffe verwenden.');
                }
                $data = ['name' => $this->form['name'], 'url' => $url, 'rhythmus' => $this->form['rhythmus'], 'feed_typ' => 'rss', 'abruf_weg' => 'plattform'];
                $svc = app(TrendQuellenService::class);
                if ($this->quelleId) {
                    $this->eigene($team, $this->quelleId);
                    $q = $svc->aendern($team, $this->quelleId, $data, false, Auth::id())['quelle'];
                } else {
                    $q = $svc->anlegen($team, $data + ['art' => 'presse', 'aktiv' => true], Auth::id())['quelle'];
                }
                // Bei URL-Änderung auch die tatsächlich abgerufene Feed-URL erneuern.
                $q->update(['feed_url' => $url]);
                $this->quelleId = $q->id;
            });
            $this->dispatch('modal.close', name: 'suchthema-editor');
            $this->meldung = 'Beobachtung gespeichert. Treffer werden nach dem Quellen-Zeitplan in den Eingang übernommen.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function pausieren(int $id): void
    {
        $team = $this->team();
        $this->pruefen($team);
        $q = $this->eigene($team, $id);
        app(TrendQuellenService::class)->aendern($team, $id, ['aktiv' => ! $q->aktiv], false, Auth::id());
    }

    private function eigene(Team $team, int $id): FoodAlchemistTrendQuelle
    {
        $q = FoodAlchemistTrendQuelle::where('team_id', $team->id)->findOrFail($id);
        abort_unless(GoogleNewsSuche::lesen($q->url) !== null, 404);

        return $q;
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }

    private function pruefen(Team $team): void
    {
        app(FaRechte::class)->pruefe(Auth::user(), $team, FaRolle::Kuratieren, 'Themen beobachten');
    }

    private function validieren(): void
    {
        $this->validate([
            'form.name' => 'required|string|max:190',
            'form.begriffe' => 'required_without:form.ausdruck|string|max:1200',
            'form.ausdruck' => 'nullable|string|max:1200',
            'form.ausschliessen' => 'nullable|string|max:500',
            'form.markt' => 'required|in:DE,AT,CH',
            'form.zeitraum' => 'required|in:1d,7d,30d,90d',
            'form.rhythmus' => 'required|in:taeglich,woechentlich,monatlich,aus',
        ], [], ['form.name' => 'Thema', 'form.begriffe' => 'Suchbegriffe']);
        if (trim($this->form['ausdruck']) === '' && GoogleNewsSuche::begriffe($this->form['begriffe']) === []) {
            throw ValidationException::withMessages(['form.begriffe' => 'Bitte mindestens einen Suchbegriff eingeben.']);
        }
    }

    public function render()
    {
        $team = $this->team();
        $svc = app(TrendQuellenService::class);

        return view('foodalchemist::livewire.trendradar.beobachten', [
            'auftraege' => $svc->liste($team)->filter(fn ($q) => GoogleNewsSuche::lesen($q->url) !== null),
            'teamId' => $team->id,
            'darf' => app(FaRechte::class)->darf(Auth::user(), $team, FaRolle::Kuratieren),
            'hostAktiv' => (bool) config('foodalchemist.scheduler.trend_quellen_enabled', false),
        ]);
    }
}
