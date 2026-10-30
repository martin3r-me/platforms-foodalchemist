<?php

namespace Platform\FoodAlchemist\Livewire\Trendradar;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\TrendThemenService;

/**
 * Spec 93 A · Reiter „Eingang“ im Trendradar: systematisch erfasste Artikel als Prüfliste (Titel, Quelle, Datum,
 * Teaser, Thema, Status). Aktionen Verwerfen, Wiederherstellen, Thema ändern, „Auf die Pinnwand“ — die einzige
 * Brücke zur Pinnwand. Die Fachlogik liegt im TrendEingangService.
 *
 * Spec 93 B: Block „Themen“ (automatische Inspirationen mit Artikelzahl), Einordnung je Artikel (Kategorie, Tiefe, Sparten)
 * und „Erschließen“ für offene Einträge — ein Klick hier ist Plattform-KI.
 */
class Eingang extends Component
{
    /** neu | verworfen | alle */
    #[Url(as: 'eingang')]
    public string $filter = 'neu';

    public string $suche = '';

    /** Thema-Bearbeitung: Eintrag-ID → Text */
    public array $thema = [];

    public ?string $meldung = null;

    public ?string $fehler = null;

    public function entscheiden(int $id, string $aktion): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $this->meldung = $this->fehler = null;
        try {
            $e = app(TrendEingangService::class)->entscheiden($team, $id, $aktion, $aktion === 'thema' ? ($this->thema[$id] ?? '') : null, Auth::id());
            $this->meldung = match ($aktion) {
                'verwerfen' => 'Verworfen: '.$e->titel,
                'wiederherstellen' => 'Wieder im Eingang: '.$e->titel,
                'thema' => 'Thema gesetzt: '.$e->thema,
                'pinnwand' => 'Als Inspiration auf die Pinnwand gelegt: '.$e->titel,
                default => null,
            };
            unset($this->thema[$id]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->fehler = 'Eintrag nicht gefunden oder gehört einem anderen Team.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function erschliessen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $this->meldung = $this->fehler = null;
        try {
            $n = count(app(TrendEingangService::class)->erschliessenAnstossen($team, null, Auth::id()));
            $this->meldung = $n > 0 ? $n.' Artikel werden erschlossen.' : 'Nichts offen zum Erschließen.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $user = Auth::user();
        $team = $user?->currentTeamRelation;
        $svc = app(TrendEingangService::class);
        $status = match ($this->filter) {
            'verworfen' => ['verworfen'],
            'alle' => [],
            default => ['neu', 'erschlossen', 'gebuendelt', 'fehler'],
        };
        $eintraege = $team ? $svc->liste($team, ['status' => $status, 'suche' => $this->suche]) : collect();

        return view('foodalchemist::livewire.trendradar.eingang', [
            'eintraege' => $eintraege,
            'zaehler' => $team ? $svc->zaehler($team) : [],
            'themen' => $team ? app(TrendThemenService::class)->themen($team, 30) : collect(),
            'darfKuratieren' => $team ? app(FaRechte::class)->darf($user, $team, FaRolle::Kuratieren) : false,
            'teamId' => (int) ($team?->id ?? 0),
        ]);
    }
}
