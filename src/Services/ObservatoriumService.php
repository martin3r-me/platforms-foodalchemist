<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistObservatoriumMerker as Merker;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang as Artikel;
use Platform\FoodAlchemist\Support\ObservatoriumInhalt as Inhalt;

/** Leseansichten und persönliche Merker; bestehende Trend-Freigaben werden hier nie geändert. */
class ObservatoriumService
{
    public function __construct(private readonly FaRechte $rechte) {}

    public function abfrage(Team $team, array $filter = []): Builder
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Lesen, 'Observatorium lesen');
        $q = Artikel::visibleToTeam($team)->whereNotNull('observatorium')->where('observatorium->verwendungen', '!=', '[]');
        if (isset(Inhalt::BRANCHEN[$filter['branche'] ?? ''])) {
            $q->whereJsonContains('observatorium->branchen', $filter['branche']);
        }
        if (in_array($filter['region'] ?? '', ['DE', 'AT', 'CH'], true)) {
            $q->where('observatorium->region', $filter['region']);
        }
        if (($filter['region'] ?? '') === 'offen') {
            $q->whereNull('observatorium->region');
        }
        $eingabe = (string) ($filter['tage'] ?? '30');
        $tage = $eingabe === 'alle' ? null : (in_array($eingabe, ['7', '30', '90'], true) ? (int) $eingabe : 30);
        $q->where(fn ($w) => $w->whereNull('veroeffentlicht_am')->orWhere('veroeffentlicht_am', '<=', now()->addDay()));
        if ($tage !== null) {
            $ab = now()->subDays($tage)->startOfDay();
            $q->where(fn ($w) => $w->where('veroeffentlicht_am', '>=', $ab)
                ->orWhere(fn ($u) => $u->whereNull('veroeffentlicht_am')->where('created_at', '>=', $ab)));
        }
        $suche = mb_substr(trim((string) ($filter['suche'] ?? '')), 0, 200);
        if ($suche !== '') {
            $q->where(fn ($w) => $w->where('titel', 'like', '%'.$suche.'%')->orWhere('teaser', 'like', '%'.$suche.'%')->orWhere('herausgeber', 'like', '%'.$suche.'%')->orWhere('thema', 'like', '%'.$suche.'%'));
        }
        $ansicht = $filter['ansicht'] ?? 'entdecken';
        if (in_array($ansicht, ['news', 'ideen', 'markt'], true)) {
            if ($ansicht === 'markt') {
                $q->where(fn ($w) => $w->whereJsonContains('observatorium->verwendungen', 'markt')->orWhereJsonContains('observatorium->verwendungen', 'chance'));
                if (($filter['markt'] ?? '') === 'chance') {
                    $q->whereJsonContains('observatorium->verwendungen', 'chance');
                }
            } else {
                $q->whereJsonContains('observatorium->verwendungen', $ansicht === 'ideen' ? 'idee' : 'news');
            }
        }
        if ($ansicht === 'merkliste') {
            $q->whereIn('id', $this->merker($team)->where(fn ($w) => $w->where('gemerkt', true)->orWhere('trend_pruefen', true))->select('eingang_id'));
            if (($filter['pruefen'] ?? false) === true) {
                $q->whereIn('id', $this->merker($team)->where('trend_pruefen', true)->select('eingang_id'));
            }
        }

        return $q;
    }

    /** Ein gleicher langer Titel ist eine Meldungsgruppe, keine Behauptung semantischer Gleichheit. */
    public function gruppiert(Team $team, array $filter = []): Builder
    {
        $basis = $this->abfrage($team, $filter);
        $ids = (clone $basis)->selectRaw('MAX(id)')->groupBy(DB::raw('COALESCE(inhalt_hash, url_hash)'));

        return Artikel::visibleToTeam($team)->whereIn('id', $ids)->with('quelle:id,name,art')->orderByRaw('COALESCE(veroeffentlicht_am, created_at) DESC')->orderByDesc('id');
    }

    public function detail(Team $team, int $id): Artikel
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Lesen, 'Observatorium lesen');

        return Artikel::visibleToTeam($team)->with('quelle')->find($id) ?? abort(404);
    }

    public function weitereQuellen(Team $team, Artikel $artikel): Collection
    {
        // Vorher den übergebenen Datensatz gegen den Sichtbereich auflösen, auch bei direkten Service-Aufrufen.
        $e = $this->detail($team, (int) $artikel->id);
        if (! $e->inhalt_hash) {
            return collect();
        }

        return Artikel::visibleToTeam($team)->where('inhalt_hash', $e->inhalt_hash)->where('id', '!=', $e->id)->with('quelle')->limit(8)->get();
    }

    public function merker(Team $team): Builder
    {
        abort_unless(Auth::check(), 403);

        return Merker::where('team_id', $team->id)->where('user_id', Auth::id());
    }

    public function merken(Team $team, int $artikelId, bool $zurPruefung = false): Merker
    {
        $this->detail($team, $artikelId);
        // Ein persönlicher Merker ist eine Lese-Präferenz und keine Redaktion am geteilten Artikel.
        $m = $this->merker($team)->firstOrNew(['eingang_id' => $artikelId], ['team_id' => $team->id, 'user_id' => Auth::id()]);
        if ($zurPruefung) {
            $m->trend_pruefen = ! $m->trend_pruefen;
        } else {
            $m->gemerkt = ! $m->gemerkt;
        }
        $m->save();

        return $m;
    }

    public function pinnwand(Team $team, int $artikelId): Artikel
    {
        $e = $this->detail($team, $artikelId);
        abort_unless((int) $e->team_id === (int) $team->id, 403);

        return app(TrendEingangService::class)->entscheiden($team, $artikelId, 'pinnwand', null, Auth::id());
    }
}
