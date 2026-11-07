<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\TrendQuellenService;
use Platform\FoodAlchemist\Services\Trends\TrendAutomatikService;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Support\FaShell;
use Platform\FoodAlchemist\Support\GoogleNewsSuche;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Support\TrendUrl;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/**
 * #1003 Teil 8 · Trendradar am Handy (Trendradar\Index). Reiter Trends (Karten, gruppiert nach Ebene oder Kategorie) ·
 * Entwicklungen (automatische Themen und Pinnwand-Karten mit Belegen) · Pinnwand (Fundstücke mit Bild).
 * Zusätzlich lesend: Suchthemen, Quellen, Eingang und Auswertungsstatus. Antippen öffnet den Trend lesbar über die ganze Breite. Das Radar bleibt am Computer:
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

    /** Reiter am Handy: trends | themen | pinnwand | beobachten | quellen | eingang | automatik. */
    public string $handyReiter = 'trends';

    /** Spec 93 · Thema (Inspiration) im Reiter „Themen“, lesbar über die ganze Breite; null = Liste. */
    public ?int $handyThemaId = null;

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
        if (HandyLesemodus::anfrageVomHandy() && in_array($r->query('ansicht'), ['beobachten', 'quellen', 'eingang', 'automatik'], true)) {
            $this->handyReiter = $r->query('ansicht');
        }
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
        $reiter = in_array($this->handyReiter, ['trends', 'themen', 'pinnwand', 'beobachten', 'quellen', 'eingang', 'automatik'], true) ? $this->handyReiter : 'trends';
        $gruppe = $this->handyGruppe === 'kategorie' ? 'kategorie' : 'ebene';
        // Spec 93 · Reiter „Themen“: automatische Themen aus dem Eingang und Pinnwand-Karten des Teams, nur lesen (Sicht über visibleToTeam
        // wie am Computer). Jüngste Sichtung zuerst; Belege-Links ohne das interne #signal=-Fragment.
        $themaBasis = fn () => FoodAlchemistTrendInspiration::visibleToTeam($team)->whereIn('herkunft', ['automatisch', 'manuell']);
        $thema = $gewaehlt === null && $reiter === 'themen' && $this->handyThemaId !== null
            ? $themaBasis()->with(['quellen', 'trend:id,name'])->find($this->handyThemaId) : null;
        $themen = $gewaehlt === null && $reiter === 'themen' && $this->handyThemaId === null
            ? $themaBasis()->with('quellen:id,inspiration_id,quelle,url,beobachtet_am')->withMax('quellen', 'beobachtet_am')
                ->when(trim((string) $this->suche) !== '', fn ($q) => $q->where('titel', 'like', '%'.trim((string) $this->suche).'%'))
                ->orderByDesc('quellen_max_beobachtet_am')->orderByDesc('updated_at')->limit(100)->get()
                ->map(fn ($i) => ['i' => $i, 'belege' => $i->quellen->count(),
                    'beitraege' => $i->quellen->map(fn ($b) => $b->url ? TrendUrl::ohneSignal($b->url) : 'beleg:'.$b->id)->unique()->count(),
                    'arten' => $i->quellen->pluck('quelle')->unique()->sort()->values()->all()])
            : collect();
        $trends = $gewaehlt !== null || $reiter !== 'trends' ? collect()
            : $svc->liste($team, ['suche' => $this->suche, 'status' => $this->statusFilter !== '' ? [$this->statusFilter] : []]);
        $labels = $gruppe === 'kategorie' ? V::KATEGORIEN : V::EBENEN;

        return view('foodalchemist::livewire.handy.trends', [
            'reiter' => $reiter,
            'handyQuellen' => in_array($reiter, ['quellen', 'beobachten'], true)
                ? app(TrendQuellenService::class)->liste($team, ['suche' => $this->suche])
                    ->filter(fn ($q) => $reiter !== 'beobachten' || GoogleNewsSuche::lesen($q->url) !== null)
                : collect(),
            'handyEingang' => $reiter === 'eingang'
                ? app(TrendEingangService::class)->liste($team, ['suche' => $this->suche], 50) : collect(),
            'handyAutomatik' => $reiter === 'automatik'
                ? app(TrendAutomatikService::class)->einstellungen($team) : null,

            'gruppe' => $gruppe,
            'gewaehlt' => $gewaehlt,
            'trendFehlt' => $this->selectedId !== null && $gewaehlt === null,
            'themen' => $themen,
            'thema' => $thema,
            'themaFehlt' => $gewaehlt === null && $reiter === 'themen' && $this->handyThemaId !== null && $thema === null,
            'themaBelege' => $thema ? $thema->quellen->map(fn ($b) => ['b' => $b, 'link' => $b->url ? TrendUrl::ohneSignal($b->url) : null, 'url' => $svc->dateiUrl($b)]) : collect(),
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
            // Spec 93: automatische Themen aus dem Eingang sind nie Ziel eines Fundstücks (nur herkunft = manuell)
            'eigeneInspirationen' => $reiter === 'pinnwand' ? FoodAlchemistTrendInspiration::where('team_id', $team->id)
                ->where('herkunft', 'manuell')->whereNull('trend_id')->orderByDesc('updated_at')->limit(100)->pluck('titel', 'id')->all() : [],
            'bewertung' => $gewaehlt ? $svc->bewertung($gewaehlt) : null,
            'belege' => $gewaehlt ? $gewaehlt->belege()->get()->map(fn ($b) => ['b' => $b, 'url' => $svc->dateiUrl($b)]) : collect(),
            'messungen' => $gewaehlt ? $gewaehlt->signale()->limit(3)->get() : collect(),
        ])->layout(FaShell::layout());
    }
}
