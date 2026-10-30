<?php

namespace Platform\FoodAlchemist\Livewire\Trendradar;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\Trends\TrendAbleitungService;

/**
 * Spec 93 D · Block „Was heißt das für uns“ im Trend-Detail (eigene Komponente, damit der Trendradar-Index nicht wächst):
 * Anzeige, „Ableitung erzeugen/neu“ (Plattform-KI, Kuratieren, nur eigenes Team), Bearbeiten → gilt dauerhaft als „von Hand“.
 */
class Ableitung extends Component
{
    public int $trendId;

    public bool $bearbeiten = false;

    /** Bearbeitung: Kurzfazit und Impulse als Zeilen „Gericht: Titel — Beschreibung“ */
    public string $kurzfazit = '';

    public string $impulse = '';

    public ?string $meldung = null;

    public ?string $fehler = null;

    public function erzeugen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $this->meldung = $this->fehler = null;
        try {
            $this->meldung = app(TrendAbleitungService::class)->anstossen($team, $this->trendId, Auth::id())
                ? 'Die Ableitung wird erzeugt.' : 'Die Ableitung wurde von Hand bearbeitet und bleibt.';
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Nur am Trend des eigenen Teams.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function oeffnen(): void
    {
        $t = $this->trend();
        $ab = $t?->ableitung ?? [];
        $this->kurzfazit = (string) ($ab['kurzfazit'] ?? '');
        $this->impulse = implode("\n", array_map(fn ($i) => ($i['art'] === 'konzept' ? 'Konzept' : 'Gericht').': '.$i['titel'].($i['beschreibung'] ? ' — '.$i['beschreibung'] : ''), $ab['impulse'] ?? []));
        $this->bearbeiten = true;
    }

    public function speichern(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $impulse = [];
        foreach (preg_split('/\R/u', $this->impulse) ?: [] as $zeile) {
            if (! preg_match('/^\s*(gericht|konzept)?\s*:?\s*(.+?)(?:\s+—\s+(.+))?\s*$/iu', $zeile, $m) || trim($m[2]) === '') {
                continue;
            }
            $impulse[] = ['art' => strtolower($m[1] ?? '') === 'konzept' ? 'konzept' : 'gericht', 'titel' => trim($m[2]), 'beschreibung' => isset($m[3]) ? trim($m[3]) : null];
        }
        $this->meldung = $this->fehler = null;
        try {
            $alt = $this->trend()?->ableitung ?? [];
            app(TrendAbleitungService::class)->manuellSetzen($team, $this->trendId,
                ['kurzfazit' => $this->kurzfazit, 'bedeutung' => $alt['bedeutung'] ?? [], 'impulse' => $impulse], Auth::id());
            $this->bearbeiten = false;
            $this->meldung = 'Gespeichert. Die Ableitung gilt jetzt als „von Hand“; die KI überschreibt sie nicht.';
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Nur am Trend des eigenen Teams.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function trend(): ?FoodAlchemistTrend
    {
        $team = Auth::user()?->currentTeamRelation;

        return $team ? FoodAlchemistTrend::visibleToTeam($team)->find($this->trendId) : null;
    }

    public function render()
    {
        $user = Auth::user();
        $team = $user?->currentTeamRelation;
        $t = $this->trend();

        return view('foodalchemist::livewire.trendradar.ableitung', [
            'trend' => $t,
            'darf' => $t !== null && $team !== null && (int) $t->team_id === (int) $team->id && app(FaRechte::class)->darf($user, $team, FaRolle::Kuratieren),
        ]);
    }
}
