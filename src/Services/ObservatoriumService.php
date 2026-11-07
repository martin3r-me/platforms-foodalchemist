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
use Platform\FoodAlchemist\Models\FoodAlchemistWettbewerber;
use Platform\FoodAlchemist\Support\ObservatoriumSicht;
use Platform\FoodAlchemist\Support\ObservatoriumInhalt as Inhalt;

/** Leseansichten und persönliche Merker; bestehende Trend-Freigaben werden hier nie geändert. */
class ObservatoriumService
{
    public function __construct(private readonly FaRechte $rechte) {}

    public function abfrage(Team $team, array $filter = []): Builder
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Lesen, 'Observatorium lesen');
        $wettbewerb = ObservatoriumSicht::WETTBEWERB_AKTIV && ($filter['ansicht'] ?? '') === 'wettbewerb';
        // Observatorium zentral: gelesen wird beim Master (Rechte oben am eigenen Team), Merker unten bleiben am eigenen Team
        $q = Artikel::visibleToTeam(ObservatoriumSicht::inhaltsTeam($team));
        if ($wettbewerb) {
            $q->whereIn('id', app(WettbewerbService::class)->bestaetigteArtikelIds($team, (int) ($filter['wettbewerber'] ?? 0) ?: null,
                (string) ($filter['branche'] ?? ''), (string) ($filter['region'] ?? ''), (string) ($filter['bewegung'] ?? '')));
        } else {
            $q->whereNotNull('observatorium')->where('observatorium->verwendungen', '!=', '[]');
        }
        if (! $wettbewerb && isset(Inhalt::BRANCHEN[$filter['branche'] ?? ''])) {
            $q->whereJsonContains('observatorium->branchen', $filter['branche']);
        }
        if (! $wettbewerb && in_array($filter['region'] ?? '', ['DE', 'AT', 'CH'], true)) {
            $q->where('observatorium->region', $filter['region']);
        }
        if (! $wettbewerb && ($filter['region'] ?? '') === 'offen') {
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
        $team = ObservatoriumSicht::inhaltsTeam($team);

        return Artikel::visibleToTeam($team)->whereIn('id', $ids)->with(['quelle:id,name,art', 'wettbewerbFunde' => fn ($q) => $q->visibleToTeam($team)->where('status', 'bestaetigt')->whereIn('wettbewerber_id', FoodAlchemistWettbewerber::visibleToTeam($team)->select('id'))->with('wettbewerber')])->orderByRaw('COALESCE(veroeffentlicht_am, created_at) DESC')->orderByDesc('id');
    }

    public function detail(Team $team, int $id): Artikel
    {
        $this->rechte->pruefeAngemeldet($team, FaRolle::Lesen, 'Observatorium lesen');
        $team = ObservatoriumSicht::inhaltsTeam($team);

        return Artikel::visibleToTeam($team)->with(['quelle', 'wettbewerbFunde' => fn ($q) => $q->visibleToTeam($team)->where('status', 'bestaetigt')->whereIn('wettbewerber_id', FoodAlchemistWettbewerber::visibleToTeam($team)->select('id'))->with('wettbewerber')])->find($id) ?? abort(404);
    }

    public function weitereQuellen(Team $team, Artikel $artikel): Collection
    {
        // Vorher den übergebenen Datensatz gegen den Sichtbereich auflösen, auch bei direkten Service-Aufrufen.
        $e = $this->detail($team, (int) $artikel->id);
        if (! $e->inhalt_hash) {
            return collect();
        }

        return Artikel::visibleToTeam(ObservatoriumSicht::inhaltsTeam($team))->where('inhalt_hash', $e->inhalt_hash)->where('id', '!=', $e->id)->with('quelle')->limit(8)->get();
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
        abort_unless(ObservatoriumSicht::kuratiertHier($team), 403);
        $e = $this->detail($team, $artikelId);
        abort_unless((int) $e->team_id === (int) $team->id, 403);

        return app(TrendEingangService::class)->entscheiden($team, $artikelId, 'pinnwand', null, Auth::id());
    }
}
