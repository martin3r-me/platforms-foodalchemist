<?php

namespace Platform\FoodAlchemist\Livewire\Trendradar;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Services\TrendSignalService;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/**
 * Trendradar (Spec 79) nach Sarah Spork — Inspiration → Hype → Trend: Radar (Ringe = Trendhierarchie,
 * Sektoren = Kategorie, Hype gestrichelt, Gold-Ring = von der Mitarbeiterbefragung bestätigt), Liste mit
 * Prüf-Queue, Inspirations-Pinnwand (Fundstücke des Teams), Detail mit Belegen. Fundstücke ablegen darf jedes
 * Teammitglied; Trends anlegen, zuordnen, einordnen und Status setzen braucht Kuratieren.
 * Geschrieben wird ausschließlich über {@see TrendService}.
 */
class Index extends Component
{
    use WithFileUploads;

    #[Url(as: 'ansicht')]
    public string $ansicht = 'radar';

    #[Url(as: 'q')]
    public string $suche = '';

    /** @var list<string> */
    #[Url(as: 'kat')]
    public array $kategorien = [];

    /** @var list<string> Spec 79 Nachtrag: Sparte (für wen) */
    #[Url(as: 'sparte')]
    public array $sparten = [];

    /** @var list<string> */
    #[Url(as: 'typ')]
    public array $typen = [];

    /** @var list<string> */
    #[Url(as: 'ebene')]
    public array $ebenen = [];

    #[Url(as: 'befragung')]
    public bool $nurBefragung = false;

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'trend')]
    public ?int $selectedId = null;

    /** Pinnwand: offen | zugeordnet | alle */
    #[Url(as: 'pinnwand')]
    public string $pinnAnsicht = 'offen';

    #[Url(as: 'schlagwort')]
    public string $pinnSchlagwort = '';

    /** Fundstück-Dialog */
    public array $fund = [];

    public $fundDatei = null;

    /** Pinnwand: gewählter Ziel-Trend je Inspiration */
    public array $zuordnung = [];

    /** Pinnwand: zum Zusammenführen markierte Inspirationen */
    public array $mergeWahl = [];

    /** „Trend daraus machen": diese Inspirationen gehen beim Anlegen mit */
    public array $ausInspirationen = [];

    /** Erfassen-Dialog */
    public array $neu = [];

    public $neuDatei = null;

    /** Beleg am gewählten Trend */
    public array $beleg = [];

    public $belegDatei = null;

    /** Einordnung des gewählten Trends */
    public array $einordnung = [];

    public bool $einordnenOffen = false;

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function mount(): void
    {
        $this->neuLeeren();
        $this->belegLeeren();
        $this->fundLeeren();
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
        $this->einordnenOffen = false;
        $this->fehler = null;
        $this->meldung = null;
        $this->belegLeeren();
    }

    public function deselect(): void
    {
        $this->selectedId = null;
        $this->einordnenOffen = false;
    }

    public function ansichtSetzen(string $ansicht): void
    {
        $this->ansicht = in_array($ansicht, ['radar', 'liste', 'inspiration'], true) ? $ansicht : 'radar';
    }

    public function resetFilter(): void
    {
        $this->reset(['suche', 'kategorien', 'sparten', 'typen', 'ebenen', 'nurBefragung', 'statusFilter']);
    }

    // ── Fundstücke (Inspiration) ───────────────────────────────────────────

    /** Dialog „Fundstück ablegen“ — optional direkt als weitere Quelle einer bestehenden Inspiration. */
    public function fundstueckOeffnen(?int $inspirationId = null): void
    {
        $this->fundLeeren();
        $this->fund['inspiration_id'] = $inspirationId !== null ? (string) $inspirationId : '';
        $this->fehler = null;
        $this->dispatch('modal.open', name: 'fundstueck-ablegen');
    }

    public function fundstueckAblegen(TrendService $svc): void
    {
        if ($this->fundDatei !== null) {
            $this->validate(['fundDatei' => 'file|max:'.V::DATEI_MAX_KB]);
        }
        $this->aktion(function () use ($svc) {
            $daten = $this->fund;
            if (($daten['inspiration_id'] ?? '') === '') {
                unset($daten['inspiration_id']);
            }
            $b = $svc->fundstueckAblegen($this->team(), $daten, $this->fundDatei, Auth::id());
            $neu = empty($daten['inspiration_id']);
            $this->fundLeeren();
            $this->dispatch('modal.close', name: 'fundstueck-ablegen');
            $this->ansicht = 'inspiration';
            $this->meldung = $neu ? '„'.($b->inspiration?->titel ?: 'Inspiration').'“ liegt in der Pinnwand.'
                : 'Quelle zu „'.($b->inspiration?->titel ?: 'Inspiration').'“ hinzugefügt.';
        });
    }

    public function inspirationZuordnen(int $inspirationId, TrendService $svc): void
    {
        $trendId = (int) ($this->zuordnung[$inspirationId] ?? 0);
        if ($trendId <= 0) {
            $this->fehler = 'Bitte zuerst einen Trend wählen.';

            return;
        }
        $this->aktion(function () use ($svc, $inspirationId, $trendId) {
            $svc->inspirationZuordnen($this->team(), $inspirationId, $trendId, Auth::id());
            unset($this->zuordnung[$inspirationId]);
            $this->meldung = 'Inspiration dem Trend zugeordnet, alle Quellen sind jetzt Belege.';
        });
    }

    public function inspirationLoesen(int $inspirationId, TrendService $svc): void
    {
        $this->aktion(fn () => $svc->inspirationLoesen($this->team(), $inspirationId, Auth::id()));
    }

    public function inspirationLoeschen(int $inspirationId, TrendService $svc): void
    {
        $this->aktion(function () use ($svc, $inspirationId) {
            $svc->inspirationLoeschen($this->team(), $inspirationId, Auth::id());
            $this->meldung = 'Inspiration gelöscht.';
        });
    }

    /** Markierte Inspirationen zusammenführen — Ziel ist die älteste, alle Quellen wandern dorthin. */
    public function zusammenfuehren(TrendService $svc): void
    {
        $ids = array_values(array_unique(array_map('intval', $this->mergeWahl)));
        if (count($ids) < 2) {
            $this->fehler = 'Bitte mindestens zwei Inspirationen markieren.';

            return;
        }
        sort($ids);
        $this->aktion(function () use ($svc, $ids) {
            $ziel = $svc->inspirationenZusammenfuehren($this->team(), $ids[0], array_slice($ids, 1), Auth::id());
            $this->mergeWahl = [];
            $this->meldung = 'Zusammengeführt zu „'.$ziel->titel.'“.';
        });
    }

    /** „Trend daraus machen": Dialog mit dem Titel der Inspiration vorbelegt, alle Quellen gehen beim Anlegen mit. */
    public function trendAusInspiration(int $inspirationId): void
    {
        $i = \Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration::where('team_id', $this->team()->id)->with('quellen')->find($inspirationId);
        if ($i === null) {
            return;
        }
        $this->neuLeeren();
        $this->neu['name'] = (string) $i->titel;
        $this->neu['definition'] = (string) ($i->quellen->pluck('notiz')->filter()->first() ?? '');
        $this->ausInspirationen = [$i->id];
        $this->fehler = null;
        $this->dispatch('modal.open', name: 'trend-erfassen');
    }

    // ── Trend anlegen (Kuratieren) ─────────────────────────────────────────

    public function erfassenOeffnen(): void
    {
        $this->neuLeeren();
        $this->fehler = null;
        $this->dispatch('modal.open', name: 'trend-erfassen');
    }

    public function erfassen(TrendService $svc): void
    {
        $this->fehler = null;
        if ($this->neuDatei !== null) {
            $this->validate(['neuDatei' => 'file|max:'.V::DATEI_MAX_KB]);
        }
        $this->aktion(function () use ($svc) {
            $n = $this->neu;
            $trend = $svc->anlegen($this->team(), [
                'name' => $n['name'] ?? '',
                'definition' => $n['definition'] ?? null,
                'typ' => $n['typ'] ?: null,
                'ebene' => $n['ebene'] ?: null,
                'kategorie' => $n['kategorie'] ?: null,
                'inspiration_ids' => $this->ausInspirationen,
                'beleg' => $this->ausInspirationen !== [] ? null : [
                    'quelle' => $n['quelle'] ?: 'beobachtung',
                    'url' => $n['url'] ?? null,
                    'notiz' => $n['notiz'] ?? null,
                    'fundort' => $n['fundort'] ?? null,
                ],
            ], Auth::id(), $this->ausInspirationen !== [] ? null : $this->neuDatei);
            $this->neuLeeren();
            $this->dispatch('modal.close', name: 'trend-erfassen');
            $this->selectedId = $trend->id;
            $this->meldung = "Trend „{$trend->name}“ angelegt (gesichtet).";
        });
    }

    // ── Detail-Aktionen ────────────────────────────────────────────────────

    public function belegHinzufuegen(TrendService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        if ($this->belegDatei !== null) {
            $this->validate(['belegDatei' => 'file|max:'.V::DATEI_MAX_KB]);
        }
        $this->aktion(function () use ($svc) {
            $svc->belegAnhaengen($this->team(), $this->selectedId, $this->beleg, $this->belegDatei, Auth::id());
            $this->belegLeeren();
            $this->meldung = 'Beleg angehängt.';
        });
    }

    public function belegEntfernen(int $belegId, TrendService $svc): void
    {
        $this->aktion(fn () => $svc->belegEntfernen($this->team(), $belegId, Auth::id()));
    }

    public function einordnenStarten(): void
    {
        $t = $this->gewaehlt();
        if ($t === null) {
            return;
        }
        $this->einordnung = [
            'name' => $t->name, 'definition' => (string) $t->definition,
            'typ' => (string) $t->typ, 'ebene' => (string) $t->ebene, 'kategorie' => (string) $t->kategorie,
            'food_cluster' => (string) $t->food_cluster, 'sicht' => (string) $t->sicht,
            'gartner_phase' => (string) $t->gartner_phase, 'konfidenz_manuell' => (string) $t->konfidenz_manuell,
            'historische_einordnung' => (string) $t->historische_einordnung,
            'suchbegriffe' => implode(', ', $t->suchbegriffe ?? []), 'hashtags' => implode(', ', $t->hashtags ?? []),
            'sparten' => array_values($t->sparten ?? []),
        ];
        $this->einordnenOffen = true;
        $this->fehler = null;
    }

    public function einordnungSpeichern(TrendService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $this->aktion(function () use ($svc) {
            $e = $this->einordnung;
            if (($e['kategorie'] ?? '') !== 'food') {
                $e['food_cluster'] = '';
            }
            $svc->aendern($this->team(), $this->selectedId, $e, Auth::id());
            $this->einordnenOffen = false;
            $this->meldung = 'Einordnung gespeichert.';
        });
    }

    public function statusSetzen(string $status, TrendService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $this->aktion(function () use ($svc, $status) {
            $t = $svc->statusSetzen($this->team(), $this->selectedId, $status, Auth::id());
            $this->meldung = 'Status: '.V::STATUS[$t->status].'.';
        });
    }

    public function loeschen(TrendService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $this->aktion(function () use ($svc) {
            $svc->loeschen($this->team(), $this->selectedId, Auth::id());
            $this->selectedId = null;
            $this->meldung = 'Trend gelöscht.';
        });
    }

    public function messen(TrendSignalService $signale): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $this->aktion(function () use ($signale) {
            $ergebnis = $signale->messen($this->team(), $this->selectedId, Auth::user());
            $this->meldung = 'Google Trends gemessen ('.$ergebnis->count().' Suchbegriff'.($ergebnis->count() === 1 ? '' : 'e').').';
        });
    }

    /**
     * Spec 79 · in die Planung springen: Session aus diesem Trend/Hype oder dieser Inspiration anlegen und die Leitstelle
     * mit vorbefülltem Briefing öffnen. Weitere Impulse kombiniert man dort im Reiter „Trendradar".
     */
    public function inPlanungOeffnen(?int $trendId = null, ?int $inspirationId = null)
    {
        $trendId ??= $inspirationId === null ? $this->selectedId : null;
        try {
            $session = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)->ausTrendradar(
                $this->team(), $trendId !== null ? [$trendId] : [], $inspirationId !== null ? [$inspirationId] : []);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return null;
        }

        return redirect()->route('foodalchemist.planung.index', ['session' => $session->id, 'open' => 1]);
    }

    // ── Render ─────────────────────────────────────────────────────────────

    public function render(TrendService $svc, FaRechte $rechte, TrendSignalService $signale, TeamSettingsService $settings)
    {
        $team = $this->team();
        $alle = $svc->liste($team);
        $filter = ['suche' => $this->suche, 'kategorie' => $this->kategorien, 'sparte' => $this->sparten, 'typ' => $this->typen, 'ebene' => $this->ebenen,
            'nur_befragung' => $this->nurBefragung];
        $gefiltert = $svc->liste($team, $filter + ['status' => $this->statusFilter !== '' ? [$this->statusFilter] : []]);

        $radarTrends = $gefiltert->filter(fn ($t) => in_array($t->status, V::RADAR_STATUS, true) && $t->ebene && $t->kategorie && $t->typ)->values();
        $positionen = $svc->radarPositionen($radarTrends);   // ohne Überlappung je Kategorie × Ebene
        $radar = $radarTrends->map(fn (FoodAlchemistTrend $t) => ['trend' => $t] + $positionen[(int) $t->id])->values();
        $aufRadar = $alle->filter(fn ($t) => in_array($t->status, V::RADAR_STATUS, true));

        $gewaehlt = $this->gewaehlt();
        $user = Auth::user();

        return view('foodalchemist::livewire.trendradar.index', [
            'trends' => $gefiltert,
            'radar' => $radar,
            'zaehler' => [
                'radar' => $aufRadar->count(),
                'hypes' => $aufRadar->where('typ', 'hype')->count(),
                'befragung' => $aufRadar->where('befragung_bestaetigt', true)->count(),
                'zu_pruefen' => $alle->where('status', 'gesichtet')->count(),
                'je_ebene' => $aufRadar->countBy('ebene')->all(),
            ],
            'gewaehlt' => $gewaehlt,
            'bewertung' => $gewaehlt ? $svc->bewertung($gewaehlt) : null,
            'hindernis' => $gewaehlt ? $svc->radarHindernis($gewaehlt) : null,
            'belege' => $gewaehlt ? $gewaehlt->belege()->get()->map(fn ($b) => ['b' => $b, 'url' => $svc->dateiUrl($b)]) : collect(),
            'messungen' => $gewaehlt ? $gewaehlt->signale()->limit(3)->get() : collect(),
            'eigen' => $gewaehlt !== null && (int) $gewaehlt->team_id === (int) $team->id,
            'darfKuratieren' => $rechte->darf($user, $team, FaRolle::Kuratieren),
            'messenMoeglich' => $signale->anbindungVorhanden(),
            'budget' => $settings->trendDataForSeoBudget($team),
            'verbraucht' => $signale->verbrauchDiesenMonat($team),
            'inspirationen' => $this->ansicht === 'inspiration' ? $svc->inspirationen($team, $this->pinnAnsicht, $this->suche, $this->pinnSchlagwort ?: null)
                ->map(fn ($i) => ['i' => $i, 'bild' => ($t = $svc->titelbild($i)) ? $svc->dateiUrl($t) : null,
                    'quellen' => $i->quellen->map(fn ($b) => ['b' => $b, 'url' => $svc->dateiUrl($b)])]) : collect(),
            // Ablegen-Dialog: offene Inspirationen des eigenen Teams als Ziel für eine weitere Quelle
            'eigeneInspirationen' => \Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration::where('team_id', $team->id)
                ->whereNull('trend_id')->orderByDesc('updated_at')->limit(100)->pluck('titel', 'id')->all(),
            'haeufungen' => $svc->haeufungen($team),
            'teamNamen' => \Platform\Core\Models\Team::whereIn('id', $svc->fundstueckFamilie($team))->pluck('name', 'id')->all(),
            // Moderation: Teams der Familie, in denen der Benutzer FA-Admin ist (darf dort Fundstücke löschen)
            'adminTeams' => \Platform\Core\Models\Team::whereIn('id', $svc->fundstueckFamilie($team))->get()
                ->filter(fn ($t) => $rechte->darf($user, $t, FaRolle::Admin))->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'offeneFundstuecke' => \Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration::whereIn('team_id', $svc->fundstueckFamilie($team))->whereNull('trend_id')->count(),
            'trendOptionen' => $alle->where('team_id', $team->id)->whereNotIn('status', ['verworfen', 'archiviert'])->pluck('name', 'id')->all(),
            'filterAktiv' => $this->suche !== '' || $this->kategorien !== [] || $this->sparten !== [] || $this->typen !== [] || $this->ebenen !== [] || $this->nurBefragung || $this->statusFilter !== '',
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }

    // ── intern ─────────────────────────────────────────────────────────────

    private function gewaehlt(): ?FoodAlchemistTrend
    {
        if ($this->selectedId === null) {
            return null;
        }

        return FoodAlchemistTrend::visibleToTeam($this->team())->withCount('belege')->find($this->selectedId);
    }

    private function aktion(callable $fn): void
    {
        $this->fehler = null;
        $this->meldung = null;
        try {
            $fn();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Nicht gefunden oder gehört einem anderen Team.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403);
    }

    private function neuLeeren(): void
    {
        $this->neu = ['name' => '', 'definition' => '', 'typ' => '', 'ebene' => '', 'kategorie' => '',
            'quelle' => 'beobachtung', 'url' => '', 'notiz' => '', 'fundort' => ''];
        $this->neuDatei = null;
        $this->ausInspirationen = [];
    }

    private function fundLeeren(): void
    {
        $this->fund = ['titel' => '', 'quelle' => 'instagram', 'url' => '', 'notiz' => '', 'fundort' => '', 'schlagworte' => '', 'inspiration_id' => ''];
        $this->fundDatei = null;
    }

    private function belegLeeren(): void
    {
        $this->beleg = ['quelle' => 'beobachtung', 'titel' => '', 'url' => '', 'notiz' => '', 'fundort' => '', 'beobachtet_am' => '', 'anteil' => ''];
        $this->belegDatei = null;
    }
}
