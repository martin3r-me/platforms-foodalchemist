<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/**
 * #1003 Teil 8 · Trendradar am Handy (Trendradar\Index). Reiter Trends (Karten, gruppiert nach Ebene oder Kategorie) ·
 * Pinnwand (Fundstücke mit Bild). Antippen öffnet den Trend lesbar über die ganze Breite. Das Radar bleibt am Computer:
 * bei 375 px liegen schon 25 Punkte nur 17,6 px auseinander — keine eindeutige Tippfläche (Befund 30.10.).
 * Dieselbe Sicht wie am Computer (TrendService::liste / inspirationen, gewaehlt() über visibleToTeam).
 *
 * Bewusste Ausnahme vom Lesemodus (Dominique 30.10., wie die Planung): „Fundstück ablegen“ ist am Handy bedienbar — über
 * GENAU den vorhandenen Weg (fundstueckAblegen mit fund.* und fundDatei, gleiche Prüfung, Ablage und Rechte). Dazu laufen
 * die Livewire-Upload-Methoden (sie legen nur in den Zwischenspeicher; gespeichert wird nur über fundstueckAblegen).
 * Löschen, Einordnen, Status, Messen, Belege, Zuordnen, Zusammenführen und Erfassen bleiben am Computer.
 * Die Komponente ruft handyTrends() am Anfang von render().
 */
trait HandyTrendsLesen
{
    use HandyLesebereich;

    /** Reiter am Handy: trends | pinnwand. */
    public string $handyReiter = 'trends';

    /** Gruppierung der Trend-Karten: ebene | kategorie. */
    public string $handyGruppe = 'ebene';

    /** Ablege-Formular in der Pinnwand aufgeklappt. */
    public bool $handyAblegenOffen = false;

    /**
     * Vorbelegung per URL (Vorbereitung „Teilen an Food Alchemist“, Kurator 30.10.): /trendradar?ablegen=1&link=…&text=…&titel=…
     * öffnet Pinnwand + Formular und füllt nur die Felder — gespeichert wird erst mit „Ablegen“. Link nur http/https, Text und
     * Titel ohne Tags/Steuerzeichen und gekürzt (Ausgabe escaped Blade). Läuft nach Index::mount() (das das Formular leert).
     */
    public function mountHandyTrendsLesen(): void
    {
        $r = request();
        if (! $r->boolean('ablegen') || ! HandyLesemodus::anfrageVomHandy()) {
            return;
        }
        $this->handyReiter = 'pinnwand';
        $this->handyAblegenOffen = true;
        $sauber = fn ($v, int $max) => mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', strip_tags((string) $v)) ?? ''), 0, $max);
        $link = $sauber($r->query('link', ''), 1000);
        if ($link !== '' && preg_match('~^https?://\S+$~i', $link) === 1) {
            $this->fund['url'] = $link;
        }
        if (($titel = $sauber($r->query('titel', ''), 255)) !== '') {
            $this->fund['titel'] = $titel;
        }
        if (($text = $sauber($r->query('text', ''), 2000)) !== '') {
            $this->fund['notiz'] = $text;
        }
    }

    public function handyLeseMethoden(): array
    {
        return ['select', 'deselect', 'resetFilter',
            'fundstueckAblegen', '_startUpload', '_finishUpload', '_uploadErrored', '_removeUpload'];
    }

    private function handyTrendsAktiv(): bool
    {
        return HandyLesemodus::anfrageVomHandy();
    }

    private function handyTrends(TrendService $svc)
    {
        $team = $this->team();
        $gewaehlt = $this->gewaehlt();
        $reiter = in_array($this->handyReiter, ['trends', 'pinnwand'], true) ? $this->handyReiter : 'trends';
        $gruppe = $this->handyGruppe === 'kategorie' ? 'kategorie' : 'ebene';
        $trends = $gewaehlt !== null || $reiter === 'pinnwand' ? collect()
            : $svc->liste($team, ['suche' => $this->suche, 'status' => $this->statusFilter !== '' ? [$this->statusFilter] : []]);
        $labels = $gruppe === 'kategorie' ? V::KATEGORIEN : V::EBENEN;

        return view('foodalchemist::livewire.handy.trends', [
            'reiter' => $reiter,
            'gruppe' => $gruppe,
            'gewaehlt' => $gewaehlt,
            'trendFehlt' => $this->selectedId !== null && $gewaehlt === null,
            'trends' => $trends,
            // Karten-Gruppen in der Reihenfolge des Vokabulars (Ebene innen → außen), „nicht eingeordnet“ zuletzt
            'gruppen' => collect($labels)->map(fn ($label, $key) => ['label' => $label, 'trends' => $trends->filter(fn ($t) => $t->{$gruppe} === $key)->values()])
                ->put('', ['label' => 'Noch nicht eingeordnet', 'trends' => $trends->filter(fn ($t) => ! isset($labels[$t->{$gruppe}]))->values()])
                ->filter(fn ($g) => $g['trends']->isNotEmpty()),
            'inspirationen' => $reiter === 'pinnwand' && $gewaehlt === null
                ? $svc->inspirationen($team, $this->pinnAnsicht, $this->suche, $this->pinnSchlagwort ?: null)
                    ->map(fn ($i) => ['i' => $i, 'bild' => ($t = $svc->titelbild($i)) ? $svc->dateiUrl($t) : null,
                        'quellen' => $i->quellen->map(fn ($b) => ['b' => $b, 'url' => $svc->dateiUrl($b)])])
                : collect(),
            'eigeneInspirationen' => $reiter === 'pinnwand' ? \Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration::where('team_id', $team->id)
                ->whereNull('trend_id')->orderByDesc('updated_at')->limit(100)->pluck('titel', 'id')->all() : [],
            'bewertung' => $gewaehlt ? $svc->bewertung($gewaehlt) : null,
            'belege' => $gewaehlt ? $gewaehlt->belege()->get()->map(fn ($b) => ['b' => $b, 'url' => $svc->dateiUrl($b)]) : collect(),
            'messungen' => $gewaehlt ? $gewaehlt->signale()->limit(3)->get() : collect(),
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }
}
