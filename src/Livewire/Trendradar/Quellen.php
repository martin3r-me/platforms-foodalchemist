<?php

namespace Platform\FoodAlchemist\Livewire\Trendradar;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\TrendQuellenService;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/**
 * Spec 93 A · Reiter „Quellen“ im Trendradar (Kuratieren): Quelle anlegen (das System wählt Feed, Übersicht oder MCP),
 * Rhythmus und Stichworte je Quelle pflegen, neu prüfen, Probelauf „Was käme durch?“ (speichert nichts), entfernen.
 * Die Fachlogik liegt im TrendQuellenService.
 */
class Quellen extends Component
{
    /** Neue Quelle */
    public array $neu = ['url' => '', 'name' => '', 'art' => 'presse'];

    /** Bearbeitung je Quelle: id → [rhythmus, stichworte_ein, stichworte_aus] */
    public array $bearbeiten = [];

    /** Probelauf-Ergebnis: id → [durch, gefiltert, fehler] */
    public array $probe = [];

    public ?string $meldung = null;

    public ?string $fehler = null;

    public function anlegen(): void
    {
        $this->ausfuehren(function ($svc, $team) {
            $r = $svc->anlegen($team, array_filter($this->neu, fn ($v) => $v !== ''), Auth::id());
            $this->neu = ['url' => '', 'name' => '', 'art' => 'presse'];
            $this->meldung = 'Quelle angelegt: '.$r['quelle']->name.($r['quelle']->pruef_hinweis ? ' · '.$r['quelle']->pruef_hinweis : '');
        });
    }

    public function oeffnen(int $id): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $q = app(TrendQuellenService::class)->detail($team, $id);
        $this->bearbeiten[$id] = [
            'rhythmus' => $q->rhythmus,
            'stichworte_ein' => implode(', ', $q->stichworte_ein ?? []),
            'stichworte_aus' => implode(', ', $q->stichworte_aus ?? []),
        ];
    }

    public function speichern(int $id): void
    {
        $this->ausfuehren(function ($svc, $team) use ($id) {
            $q = $svc->aendern($team, $id, $this->bearbeiten[$id] ?? [], false, Auth::id())['quelle'];
            unset($this->bearbeiten[$id]);
            $this->meldung = 'Gespeichert: '.$q->name;
        });
    }

    public function umschalten(int $id): void
    {
        $this->ausfuehren(function ($svc, $team) use ($id) {
            $q = $svc->detail($team, $id);
            $q = $svc->aendern($team, $id, ['aktiv' => ! $q->aktiv], false, Auth::id())['quelle'];
            $this->meldung = $q->name.($q->aktiv ? ' ist wieder aktiv.' : ' ist pausiert.');
        });
    }

    public function neuPruefen(int $id): void
    {
        $this->ausfuehren(function ($svc, $team) use ($id) {
            $q = $svc->aendern($team, $id, [], true, Auth::id())['quelle'];
            $this->meldung = $q->name.': '.$q->pruef_hinweis;
        });
    }

    public function probelauf(int $id): void
    {
        $this->ausfuehren(function ($svc, $team) use ($id) {
            $this->probe[$id] = $svc->probelauf($team, $id);
        });
    }

    public function entfernen(int $id): void
    {
        $this->ausfuehren(function ($svc, $team) use ($id) {
            $svc->loeschen($team, $id, Auth::id());
            unset($this->bearbeiten[$id], $this->probe[$id]);
            $this->meldung = 'Quelle entfernt. Bereits eingegangene Artikel bleiben im Eingang.';
        });
    }

    private function ausfuehren(\Closure $schritt): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $this->meldung = $this->fehler = null;
        try {
            $schritt(app(TrendQuellenService::class), $team);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Quelle nicht gefunden oder gehört einem anderen Team.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $user = Auth::user();
        $team = $user?->currentTeamRelation;
        $svc = app(TrendQuellenService::class);

        return view('foodalchemist::livewire.trendradar.quellen', [
            'quellen' => $team ? $svc->liste($team) : collect(),
            'svc' => $svc,
            'darfKuratieren' => $team ? app(FaRechte::class)->darf($user, $team, FaRolle::Kuratieren) : false,
            'teamId' => (int) ($team?->id ?? 0),
            'arten' => V::QUELLEN,
            'rhythmen' => V::RHYTHMEN,
        ]);
    }
}
