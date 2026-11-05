<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang as Artikel;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle as Quelle;
use Platform\FoodAlchemist\Models\FoodAlchemistWettbewerber as Profil;
use Platform\FoodAlchemist\Models\FoodAlchemistWettbewerbFund as Fund;
use Platform\FoodAlchemist\Support\GoogleNewsSuche;
use Platform\FoodAlchemist\Support\ObservatoriumInhalt;
use Platform\FoodAlchemist\Support\StichwortFilter;
use Platform\FoodAlchemist\Support\TrendUrl;

/** Firmenzuordnung ist unabhängig von Trendstatus. Ein Vorschlag ist nie eine Bestätigung. */
class WettbewerbService
{
    public const BEWEGUNGEN = ['meldung' => 'Unternehmensmeldung', 'angebot' => 'Angebote & Konzepte', 'standort' => 'Standorte & Expansion',
        'auftrag' => 'Aufträge & Vergaben', 'partnerschaft' => 'Partnerschaften', 'personal' => 'Personal & Führung'];

    public function __construct(private readonly FaRechte $rechte) {}

    private function recht(Team $team, bool $schreiben = false, ?int $userId = null): void
    {
        $this->rechte->pruefeId($userId ?? Auth::id(), $team, $schreiben ? FaRolle::Kuratieren : FaRolle::Lesen, 'Wettbewerberbeobachtung');
    }

    public function profile(Team $team, ?int $userId = null)
    {
        $this->recht($team, false, $userId);

        return Profil::visibleToTeam($team)->orderBy('name')->get();
    }

    public function detail(Team $team, int $id, ?int $userId = null): Profil
    {
        $this->recht($team, false, $userId);

        return Profil::visibleToTeam($team)->find($id) ?? abort(404);
    }

    public function speichern(Team $team, array $daten, ?int $id = null, ?int $userId = null): Profil
    {
        $this->recht($team, true, $userId);
        $userId ??= Auth::id();
        $p = $id ? $this->detail($team, $id, $userId) : new Profil(['team_id' => $team->id, 'created_by' => $userId]);
        abort_unless(! $id || (int) $p->team_id === (int) $team->id, 403);
        $werte = $id ? array_merge($p->only(['name', 'website', 'aliases', 'suchbegriffe', 'ausschliessen', 'branchen', 'region', 'gebiet', 'notiz', 'aktiv', 'rss_aktiv', 'rhythmus', 'quellen_ids']), $daten) : $daten;
        foreach (['aliases', 'suchbegriffe', 'ausschliessen', 'branchen', 'quellen_ids'] as $feld) {
            $werte[$feld] ??= [];
        }
        $werte += ['aktiv' => true, 'rss_aktiv' => false, 'rhythmus' => 'woechentlich'];
        $werte['name'] = trim((string) ($werte['name'] ?? ''));
        $werte['name_schluessel'] = hash('sha256', StichwortFilter::normalisieren($werte['name']));
        $werte['region'] = ($werte['region'] ?? '') === '' ? null : $werte['region'];
        $v = Validator::make($werte, [
            'name' => 'required|string|min:2|max:190',
            'name_schluessel' => ['required', Rule::unique('foodalchemist_wettbewerber')->where('team_id', $team->id)->ignore($id)],
            'website' => 'nullable|string|max:2048', 'aliases' => 'array|max:20', 'aliases.*' => 'string|min:2|max:190',
            'suchbegriffe' => 'array|max:20', 'suchbegriffe.*' => 'string|max:190', 'ausschliessen' => 'array|max:20', 'ausschliessen.*' => 'string|max:190',
            'branchen' => 'array|max:17', 'branchen.*' => [Rule::in(array_keys(ObservatoriumInhalt::BRANCHEN))],
            'region' => 'nullable|in:DE,AT,CH', 'gebiet' => 'nullable|string|max:190', 'notiz' => 'nullable|string|max:3000',
            'aktiv' => 'boolean', 'rss_aktiv' => 'boolean', 'rhythmus' => 'in:taeglich,woechentlich,monatlich,aus',
            'quellen_ids' => 'array|max:20', 'quellen_ids.*' => 'integer', 'presse_feed' => 'nullable|string|max:2048',
        ])->validate();
        foreach (['website', 'presse_feed'] as $feld) {
            if (! empty($v[$feld])) {
                $v[$feld] = TrendUrl::normalisieren($v[$feld]);
            }
        }
        foreach ($v['quellen_ids'] as $qid) {
            abort_unless(Quelle::where('team_id', $team->id)->whereKey($qid)->exists(), 422);
        }

        return DB::transaction(function () use ($p, $v, $team, $userId) {
            $p->fill(array_diff_key($v, ['presse_feed' => true]));
            $p->updated_by = $userId;
            $p->save();
            $quote = fn ($s) => '"'.str_replace('"', '', $s).'"';
            $ausdruck = '('.implode(' OR ', array_map($quote, array_unique(array_merge([$p->name], $p->aliases ?? [])))).')';
            if ($p->suchbegriffe) {
                $ausdruck .= ' ('.implode(' OR ', array_map($quote, $p->suchbegriffe)).')';
            }
            $url = GoogleNewsSuche::url(['begriffe' => '', 'ausdruck' => $ausdruck, 'ausschliessen' => implode(',', $p->ausschliessen ?? []), 'zeitraum' => '30d', 'markt' => $p->region ?: 'DE']);
            if (strlen($url) > 2048) {
                throw new \RuntimeException('Die Wettbewerbssuche ist zu lang. Bitte Begriffe kürzen.');
            }
            $p->google_quelle_id = $this->quelle($team, $p, $p->google_quelle_id, $url, 'Google News', $userId)->id;
            if (! empty($v['presse_feed'])) {
                $p->presse_quelle_id = $this->quelle($team, $p, $p->presse_quelle_id, $v['presse_feed'], 'Pressefeed', $userId)->id;
            } elseif ($p->presse_quelle_id) {
                if (array_key_exists('presse_feed', $v)) {
                    app(TrendQuellenService::class)->aendern($team, $p->presse_quelle_id, ['aktiv' => false], false, $userId);
                    $p->presse_quelle_id = null;
                } else {
                    $this->quelle($team, $p, $p->presse_quelle_id, null, 'Pressefeed', $userId);
                }
            }
            $p->save();

            return $p->refresh();
        });
    }

    private function quelle(Team $team, Profil $p, ?int $id, ?string $url, string $art, ?int $userId): Quelle
    {
        $q = $id ? Quelle::where('team_id', $team->id)->find($id) : null;
        if ($id && ! $q) {
            abort(422);
        }
        $svc = app(TrendQuellenService::class);
        if ($url === null) {
            return $svc->aendern($team, $q->id, ['aktiv' => $p->aktiv && $p->rss_aktiv, 'rhythmus' => $p->rhythmus], false, $userId)['quelle'];
        }
        $d = ['name' => mb_substr('Wettbewerb · '.$p->name.' · '.$art, 0, 190), 'url' => $url, 'feed_typ' => 'rss', 'abruf_weg' => 'plattform',
            'rhythmus' => $p->rhythmus ?: 'woechentlich', 'aktiv' => $p->aktiv && $p->rss_aktiv, 'art' => 'branchenquelle'];
        $q = $q ? $svc->aendern($team, $q->id, $d, false, $userId)['quelle'] : $svc->anlegen($team, $d, $userId)['quelle'];
        $q->update(['feed_url' => $url]);

        return $q;
    }

    /** Kostenlos, ohne KI. Systempfad darf ausschließlich neue Vorschläge erzeugen. */
    public function vorschlagen(Artikel $e, ?int $profilId = null): int
    {
        $team = Team::find($e->team_id);
        if (! $team) {
            return 0;
        }
        $text = StichwortFilter::normalisieren($e->titel.' '.$e->teaser);
        $host = strtolower((string) parse_url($e->link(), PHP_URL_HOST));
        $n = 0;
        foreach (Profil::visibleToTeam($team)->where('aktiv', true)->when($profilId, fn ($q) => $q->whereKey($profilId))->get() as $p) {
            if (! StichwortFilter::pruefen($e->titel.' '.$e->teaser, [], $p->ausschliessen ?? [])['durch']) {
                continue;
            }
            $grund = null;
            foreach (array_merge([$p->name], $p->aliases ?? []) as $name) {
                $name = StichwortFilter::normalisieren($name);
                if ($name !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $text)) {
                    $grund = 'Name/Marke im Titel oder Teaser: '.$name;
                    break;
                }
            }
            $domain = strtolower((string) parse_url($p->website ?? '', PHP_URL_HOST));
            $domain = preg_replace('/^www\./', '', $domain);
            if ($domain && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                $grund = 'Artikel auf Unternehmensdomain: '.$domain;
            }
            if ($e->quelle_id && in_array((int) $e->quelle_id, array_map('intval', array_merge($p->quellen_ids ?? [], [$p->google_quelle_id, $p->presse_quelle_id])), true)) {
                $grund ??= 'Treffer aus einer dem Profil zugeordneten Quelle; Firmenbezug prüfen.';
            }
            if ($grund === null) {
                continue;
            }
            $f = Fund::firstOrCreate(['wettbewerber_id' => $p->id, 'eingang_id' => $e->id], ['team_id' => $e->team_id, 'status' => 'vorgeschlagen', 'grund' => $grund, 'bewegung' => $this->bewegung($text)]);
            $n += (int) $f->wasRecentlyCreated;
        }

        return $n;
    }

    public function bestandPruefen(Team $team, int $profilId, ?int $userId = null): int
    {
        $this->recht($team, true, $userId);
        $p = $this->detail($team, $profilId, $userId);
        abort_unless((int) $p->team_id === (int) $team->id, 403);
        $n = 0;
        // Eigene Eingänge werden chunkweise geprüft; bestehende Entscheidungen bleiben unverändert.
        Artikel::where('team_id', $team->id)->orderBy('id')->chunkById(200, function ($rows) use (&$n, $profilId) {
            foreach ($rows as $e) {
                $n += $this->vorschlagen($e, $profilId);
            }
        });

        return $n;
    }

    public function funde(Team $team, int $profilId, string $status = '', ?int $userId = null, ?int $seite = null)
    {
        $this->detail($team, $profilId, $userId);

        return Fund::visibleToTeam($team)->where('wettbewerber_id', $profilId)->whereIn('eingang_id', Artikel::visibleToTeam($team)->select('id'))
            ->when(in_array($status, ['vorgeschlagen', 'bestaetigt', 'abgelehnt'], true), fn ($q) => $q->where('status', $status))->with('artikel.quelle')->orderByDesc('id')->paginate(20, ['*'], 'wettbewerbSeite', $seite);
    }

    public function entscheiden(Team $team, int $profilId, int $eingangId, string $status, ?string $bewegung = null, ?string $einordnung = null, ?int $userId = null): Fund
    {
        $this->recht($team, true, $userId);
        $this->detail($team, $profilId, $userId);
        $e = Artikel::visibleToTeam($team)->find($eingangId) ?? abort(404);
        abort_unless((int) $e->team_id === (int) $team->id, 403);
        $bisher = Fund::where('wettbewerber_id', $profilId)->where('eingang_id', $eingangId)->first();
        $bewegung ??= $bisher?->bewegung ?? 'meldung';
        $einordnung ??= $bisher?->einordnung;
        Validator::make(compact('status', 'bewegung', 'einordnung'), ['status' => 'required|in:vorgeschlagen,bestaetigt,abgelehnt', 'bewegung' => [Rule::in(array_keys(self::BEWEGUNGEN))], 'einordnung' => 'nullable|string|max:2000'])->validate();

        return Fund::updateOrCreate(['wettbewerber_id' => $profilId, 'eingang_id' => $eingangId], ['team_id' => $team->id, 'status' => $status,
            'grund' => Fund::where('wettbewerber_id', $profilId)->where('eingang_id', $eingangId)->value('grund') ?: 'Manuell zugeordnet; Firmenbezug redaktionell geprüft.',
            'bewegung' => $bewegung, 'einordnung' => $einordnung, 'geprueft_by' => $userId ?? Auth::id(), 'geprueft_at' => now()]);
    }

    public function bestaetigteArtikelIds(Team $team, ?int $profilId = null, string $branche = '', string $region = '', string $bewegung = '')
    {
        $this->recht($team);
        $profile = Profil::visibleToTeam($team)->when($profilId, fn ($q) => $q->whereKey($profilId))
            ->when(isset(ObservatoriumInhalt::BRANCHEN[$branche]), fn ($q) => $q->whereJsonContains('branchen', $branche))
            ->when(in_array($region, ['DE', 'AT', 'CH'], true), fn ($q) => $q->where('region', $region))
            ->when($region === 'offen', fn ($q) => $q->whereNull('region'));

        return Fund::visibleToTeam($team)->where('status', 'bestaetigt')->whereIn('wettbewerber_id', $profile->select('id'))
            ->when(isset(self::BEWEGUNGEN[$bewegung]), fn ($q) => $q->where('bewegung', $bewegung))->select('eingang_id');
    }

    public function vergleich(Team $team): array
    {
        $this->recht($team);
        $profile = $this->profile($team);
        $zahlen = Fund::visibleToTeam($team)->where('status', 'bestaetigt')->whereIn('wettbewerber_id', $profile->pluck('id'))
            ->whereIn('eingang_id', Artikel::visibleToTeam($team)->select('id'))
            ->selectRaw('wettbewerber_id, bewegung, COUNT(DISTINCT eingang_id) as anzahl')->groupBy('wettbewerber_id', 'bewegung')->get();

        return $profile->mapWithKeys(fn ($p) => [$p->id => $zahlen->where('wettbewerber_id', $p->id)->pluck('anzahl', 'bewegung')->map(fn ($n) => (int) $n)->all()])->all();
    }

    private function bewegung(string $text): string
    {
        foreach (['auftrag' => '/\b(auftrag|beauftragt|ausschreibung|vergabe)\w*/u', 'standort' => '/\b(eroeffn|expansion|standort)\w*/u',
            'partnerschaft' => '/\b(partnerschaft|kooperation|uebernahm)\w*/u', 'personal' => '/\b(geschaeftsfuehr|personal|ernannt)\w*/u',
            'angebot' => '/\b(konzept|menue|angebot|speisekarte)\w*/u'] as $key => $muster) {
            if (preg_match($muster, $text)) {
                return $key;
            }
        }

        return 'meldung';
    }
}
