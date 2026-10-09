<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\Core\Services\ContextFileService;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/**
 * Spec 79 · Trendradar nach Sarah Spork — Inspiration → Hype → Trend. Der eine Schreibpfad für UI, MCP und Seeder.
 *
 * Fundstücke (Instagram-Post, Foto, Link — eine Beobachtung, noch kein Trend) legt jedes Teammitglied in der
 * gemeinsamen Pinnwand ab. Trends anlegen, Fundstücke zuordnen, einordnen, Status setzen und löschen braucht
 * Kuratieren. Geschrieben wird nur im eigenen Team; Kind-Teams sehen die Trends der Elternkette.
 *
 * Konfidenz kommt deterministisch aus den Belegen (Kap. 3.3): Instagram, Social Media und Google
 * Trends entdecken und messen, bestätigen aber nie allein.
 */
class TrendService
{
    public function __construct(private readonly FaRechte $rechte) {}

    // ── Lesen ──────────────────────────────────────────────────────────────

    /**
     * @param  array{suche?:string, typ?:string[], ebene?:string[], kategorie?:string[], status?:string[], nur_befragung?:bool, nur_radar?:bool}  $filter
     * @return Collection<int, FoodAlchemistTrend>
     */
    public function liste(Team $team, array $filter = []): Collection
    {
        $q = FoodAlchemistTrend::visibleToTeam($team)->withCount('belege');
        $suche = trim((string) ($filter['suche'] ?? ''));
        if ($suche !== '') {
            $q->where(fn ($w) => $w->where('name', 'like', '%'.$suche.'%')->orWhere('definition', 'like', '%'.$suche.'%'));
        }
        foreach (['typ', 'ebene', 'kategorie', 'status'] as $feld) {
            $werte = array_values(array_filter((array) ($filter[$feld] ?? []), fn ($w) => $w !== '' && $w !== null));
            if ($werte !== []) {
                $q->whereIn($feld, $werte);
            }
        }
        // Sparten: Trend gilt für eine der gewählten Sparten ODER für alle (keine Sparte gesetzt)
        $sparten = array_values(array_filter((array) ($filter['sparte'] ?? []), fn ($w) => $w !== '' && $w !== null));
        if ($sparten !== []) {
            $q->where(function ($w) use ($sparten) {
                $w->whereNull('sparten')->orWhereJsonLength('sparten', 0);
                foreach ($sparten as $sp) {
                    $w->orWhereJsonContains('sparten', $sp);
                }
            });
        }
        if (! empty($filter['nur_befragung'])) {
            $q->where('befragung_bestaetigt', true);
        }
        if (! empty($filter['nur_radar'])) {
            $q->whereIn('status', V::RADAR_STATUS);
        }

        return $q->orderBy('name')->get();
    }

    public function detail(Team $team, int $id): FoodAlchemistTrend
    {
        return FoodAlchemistTrend::visibleToTeam($team)->with('belege')->findOrFail($id);
    }

    /** Darstellung für MCP und Exporte: Werte plus deutsche Beschriftung, auf Wunsch mit Belegen und Messungen. */
    public function alsArray(FoodAlchemistTrend $t, bool $mitBelegen = false): array
    {
        $out = [
            'id' => $t->id,
            'name' => $t->name,
            'definition' => $t->definition,
            'typ' => $t->typ, 'typ_label' => V::label(V::TYPEN, $t->typ),
            'ebene' => $t->ebene, 'ebene_label' => V::label(V::EBENEN, $t->ebene),
            'kategorie' => $t->kategorie, 'kategorie_label' => V::label(V::KATEGORIEN, $t->kategorie),
            'sparten' => $t->sparten ?? [], 'sparten_labels' => array_values(array_map(fn ($s) => V::label(V::SPARTEN, $s), $t->sparten ?? [])),
            'food_cluster' => $t->food_cluster,
            'sicht' => $t->sicht,
            'status' => $t->status, 'status_label' => V::label(V::STATUS, $t->status),
            'konfidenz' => $t->wirksameKonfidenz(),
            'konfidenz_manuell' => $t->konfidenz_manuell !== null,
            'befragung_bestaetigt' => (bool) $t->befragung_bestaetigt,
            'befragung_anteil' => $t->befragung_anteil,
            'suchbegriffe' => $t->suchbegriffe ?? [],
            'hashtags' => $t->hashtags ?? [],
            'einordnung_quelle' => $t->einordnung_quelle,
            'belege' => $t->belege_count ?? null,
            'eigenes_team' => $t->team_id,
        ];
        if (! $mitBelegen) {
            return $out;
        }
        $b = $this->bewertung($t);
        $out['konfidenz_begruendung'] = $b['begruendung'];
        $out['radar_hindernis'] = $this->radarHindernis($t);
        $out['historische_einordnung'] = $t->historische_einordnung;
        $out['gartner_phase'] = $t->gartner_phase;
        $out['einordnung_begruendung'] = $t->einordnung_begruendung;
        $out['belege'] = $t->belege()->get()->map(fn (FoodAlchemistTrendBeleg $x) => [
            'id' => $x->id, 'quelle' => $x->quelle, 'quelle_label' => V::label(V::QUELLEN, $x->quelle),
            'titel' => $x->titel, 'url' => $x->url, 'notiz' => $x->notiz, 'fundort' => $x->fundort,
            'beobachtet_am' => $x->beobachtet_am?->toDateString(), 'anteil' => $x->anteil,
            'datei' => $x->datei_name, 'datei_url' => $this->dateiUrl($x),
        ])->all();
        $out['messungen'] = $t->signale()->limit(6)->get()->map(fn ($s) => [
            'quelle' => $s->quelle, 'suchbegriff' => $s->suchbegriff, 'richtung' => $s->richtung,
            'veraenderung' => $s->veraenderung, 'durchschnitt' => $s->durchschnitt, 'spitze' => $s->spitze,
            'spitze_ohne_sockel' => (bool) $s->spitze_ohne_sockel, 'gemessen_am' => $s->created_at?->toDateString(),
        ])->all();

        return $out;
    }

    // ── Schreiben ──────────────────────────────────────────────────────────

    /**
     * Trend anlegen (Kuratieren). Ohne Status landet er als „gesichtet". Ein erster Beleg kann mitkommen;
     * `inspiration_ids` hängen Inspirationen der Pinnwand samt aller Quellen an („Trend daraus machen");
     * `fundstueck_ids` (einzelne Quellen, Altbestand) nehmen ihre ganze Inspiration mit.
     *
     * @param  array<string,mixed>  $daten  name, definition, typ, ebene, kategorie, food_cluster, sicht, suchbegriffe, hashtags, beleg{…}, inspiration_ids[], fundstueck_ids[]
     */
    public function anlegen(Team $team, array $daten, ?int $userId = null, ?UploadedFile $datei = null): FoodAlchemistTrend
    {
        $this->pruefe($userId, $team, FaRolle::Kuratieren, 'Trend anlegen');
        $name = trim((string) ($daten['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Bitte einen Namen für den Trend angeben.');
        }
        $slug = $this->slugFuer($name);
        $vorhanden = FoodAlchemistTrend::visibleToTeam($team)->where('slug', $slug)->first();
        if ($vorhanden !== null) {
            throw new \RuntimeException("Den Trend „{$vorhanden->name}\" gibt es schon (ID {$vorhanden->id}). Bitte dort einen Beleg anhängen.");
        }
        $felder = $this->einordnungsFelder($daten);
        $status = (string) ($daten['status'] ?? 'gesichtet');

        $trend = DB::transaction(function () use ($team, $name, $slug, $daten, $felder, $userId, $datei) {
            $trend = FoodAlchemistTrend::create(array_merge($felder, [
                'team_id' => $team->id,
                'name' => mb_substr($name, 0, 160),
                'slug' => $slug,
                'definition' => $this->text($daten['definition'] ?? null),
                'suchbegriffe' => $this->liste_($daten['suchbegriffe'] ?? null),
                'hashtags' => $this->liste_($daten['hashtags'] ?? null, true),
                'sparten' => $this->sparten($daten['sparten'] ?? null),
                'historische_einordnung' => $this->text($daten['historische_einordnung'] ?? null),
                'status' => 'gesichtet',
                'einordnung_quelle' => $this->hatEinordnung($felder) ? (string) ($daten['einordnung_quelle'] ?? 'manuell') : null,
                'einordnung_begruendung' => $this->text($daten['einordnung_begruendung'] ?? null),
                'created_by' => $userId,
            ]));
            $beleg = $daten['beleg'] ?? null;
            if (is_array($beleg) && ($this->belegHatInhalt($beleg) || $datei !== null)) {
                $this->belegSchreiben($team, $trend, $beleg, $datei, $userId);
            }
            foreach ($this->inspirationIdsAus($team, $daten) as $iid) {
                $this->inspirationAnTrend($this->eigeneInspiration($team, $iid), $trend->id);
            }

            return $this->neuBewerten($trend);
        });

        // Status über den Prüfpfad, damit die Radar-Regel auch beim Anlegen gilt
        if ($status !== 'gesichtet') {
            $trend = $this->statusSetzen($team, $trend->id, $status, $userId);
        }

        return $trend;
    }

    /** @param  array<string,mixed>  $daten */
    public function aendern(Team $team, int $id, array $daten, ?int $userId = null): FoodAlchemistTrend
    {
        $this->pruefe($userId, $team, FaRolle::Kuratieren, 'Trend bearbeiten');
        $trend = $this->eigener($team, $id);
        $update = $this->einordnungsFelder($daten, true);
        if (array_key_exists('name', $daten)) {
            $name = trim((string) $daten['name']);
            if ($name === '') {
                throw new \RuntimeException('Der Name darf nicht leer sein.');
            }
            $slug = $this->slugFuer($name);
            if ($slug !== $trend->slug && FoodAlchemistTrend::visibleToTeam($team)->where('slug', $slug)->exists()) {
                throw new \RuntimeException("Einen Trend „{$name}\" gibt es schon.");
            }
            $update['name'] = mb_substr($name, 0, 160);
            $update['slug'] = $slug;
        }
        foreach (['definition', 'historische_einordnung', 'einordnung_begruendung'] as $feld) {
            if (array_key_exists($feld, $daten)) {
                $update[$feld] = $this->text($daten[$feld]);
            }
        }
        foreach (['suchbegriffe' => false, 'hashtags' => true] as $feld => $hash) {
            if (array_key_exists($feld, $daten)) {
                $update[$feld] = $this->liste_($daten[$feld], $hash);
            }
        }
        if (array_key_exists('sparten', $daten)) {
            $update['sparten'] = $this->sparten($daten['sparten']);
        }
        if (array_key_exists('konfidenz_manuell', $daten)) {
            $wert = $daten['konfidenz_manuell'];
            $update['konfidenz_manuell'] = $this->enum($wert === '' ? null : $wert, V::KONFIDENZ, 'Konfidenz');
        }
        if ($this->hatEinordnung($update)) {
            $update['einordnung_quelle'] = (string) ($daten['einordnung_quelle'] ?? 'manuell');
        }
        $trend->update($update);
        $trend = $this->neuBewerten($trend->refresh());

        if (array_key_exists('status', $daten) && $daten['status'] !== $trend->status) {
            $trend = $this->statusSetzen($team, $trend->id, (string) $daten['status'], $userId);
        } elseif (in_array($trend->status, V::RADAR_STATUS, true) && ($grund = $this->radarHindernis($trend)) !== null) {
            // Einordnung entfernt, während der Trend auf dem Radar steht → nicht stillschweigend weiter zeigen
            throw new \RuntimeException('Gespeichert, aber der Trend erfüllt die Radar-Regel nicht mehr: '.$grund);
        }

        return $trend;
    }

    public function statusSetzen(Team $team, int $id, string $status, ?int $userId = null): FoodAlchemistTrend
    {
        $this->pruefe($userId, $team, FaRolle::Kuratieren, 'Trend-Status setzen');
        $status = (string) $this->enum($status, V::STATUS, 'Status');
        $trend = $this->eigener($team, $id);
        if (in_array($status, V::RADAR_STATUS, true) && ($grund = $this->radarHindernis($trend)) !== null) {
            throw new \RuntimeException('Noch nicht aufs Radar: '.$grund);
        }
        $update = ['status' => $status];
        if ($status !== 'gesichtet' && $trend->geprueft_at === null) {
            $update += ['geprueft_by' => $userId, 'geprueft_at' => now()];
        }
        $trend->update($update);

        return $trend->refresh();
    }

    public function loeschen(Team $team, int $id, ?int $userId = null): void
    {
        $this->pruefe($userId, $team, FaRolle::Kuratieren, 'Trend löschen');
        $trend = $this->eigener($team, $id);
        DB::transaction(function () use ($team, $trend) {
            // Inspirationen samt Quellen bleiben auf der Pinnwand, nur die Zuordnung fällt weg
            FoodAlchemistTrendInspiration::where('trend_id', $trend->id)->update(['trend_id' => null]);
            FoodAlchemistTrendBeleg::where('trend_id', $trend->id)->where('fundstueck', true)->update(['trend_id' => null]);
            foreach ($trend->belege()->get() as $beleg) {
                $this->dateiEntfernen($team, $beleg);
                $beleg->delete();
            }
            $trend->delete();
        });
    }

    // ── Belege ─────────────────────────────────────────────────────────────

    /** @param  array<string,mixed>  $daten  quelle, titel, url, notiz, fundort, beobachtet_am, anteil */
    public function belegAnhaengen(Team $team, int $trendId, array $daten, ?UploadedFile $datei = null, ?int $userId = null): FoodAlchemistTrendBeleg
    {
        $this->pruefe($userId, $team, FaRolle::Lesen, 'Beleg anhängen');
        $trend = $this->eigener($team, $trendId);
        if (! $this->belegHatInhalt($daten) && $datei === null) {
            throw new \RuntimeException('Ein Beleg braucht einen Link, eine Notiz oder eine Datei.');
        }
        $beleg = DB::transaction(fn () => $this->belegSchreiben($team, $trend, $daten, $datei, $userId));
        $this->neuBewerten($trend->refresh());

        return $beleg;
    }

    public function belegEntfernen(Team $team, int $belegId, ?int $userId = null): void
    {
        $beleg = FoodAlchemistTrendBeleg::where('team_id', $team->id)->find($belegId);
        if ($beleg === null) {
            // Moderation (Dominique 2026-10-08): der FA-Admin eines Oberteams löscht Fundstücke seiner Standorte.
            // Nur Fundstücke der eigenen Teamfamilie; die Rolle zählt im hochladenden Team (Kette nach oben).
            $beleg = FoodAlchemistTrendBeleg::where('fundstueck', true)->whereIn('team_id', $this->fundstueckFamilie($team))->findOrFail($belegId);
            $uploadTeam = Team::findOrFail($beleg->team_id);
            $this->pruefe($userId, $uploadTeam, FaRolle::Admin, 'Fundstück eines Standorts löschen');
            $this->dateiEntfernen($uploadTeam, $beleg);
            $beleg->delete();
            $this->leereInspirationEntfernen($beleg->inspiration_id);

            return;
        }
        // Fundstücke (Pinnwand der ganzen Teamfamilie) löscht nur, wer im hochladenden Team kuratiert (Dominique
        // 2026-10-08). Eigene Belege an einem Trend darf jeder zurücknehmen, fremde nur, wer kuratiert.
        if ($beleg->fundstueck || $userId === null || (int) $beleg->created_by !== $userId) {
            $this->pruefe($userId, $team, FaRolle::Kuratieren, 'Beleg entfernen');
        }
        $trend = $beleg->trend;
        $this->dateiEntfernen($team, $beleg);
        $beleg->delete();
        $this->leereInspirationEntfernen($beleg->inspiration_id);
        if ($trend !== null) {
            $this->neuBewerten($trend->refresh());
        }
    }

    public function dateiUrl(FoodAlchemistTrendBeleg $beleg): ?string
    {
        if ($beleg->context_file_id === null) {
            return null;
        }
        try {
            return app(ContextFileService::class)->getDownloadUrl((int) $beleg->context_file_id);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    // ── Inspirationen (Team-Pinnwand: ein Thema, viele Quellen) ────────────

    /**
     * Quelle ablegen (Instagram-Post, Video, Artikel, Foto) — darf jedes Teammitglied. Ohne `inspiration_id` entsteht
     * eine neue Inspiration (Titel = Titel der Quelle), mit `inspiration_id` kommt die Quelle zu einer bestehenden
     * Inspiration des eigenen Teams. Braucht einen Titel, einen Link oder eine Datei.
     *
     * @param  array<string,mixed>  $daten  titel, quelle (Standard instagram), url, notiz, fundort, beobachtet_am, schlagworte, inspiration_id, trend_id
     */
    public function fundstueckAblegen(Team $team, array $daten, ?UploadedFile $datei = null, ?int $userId = null): FoodAlchemistTrendBeleg
    {
        $this->pruefe($userId, $team, FaRolle::Lesen, 'Fundstück ablegen');
        if (trim((string) ($daten['titel'] ?? '')) === '' && trim((string) ($daten['url'] ?? '')) === '' && $datei === null) {
            throw new \RuntimeException('Ein Fundstück braucht einen Titel, einen Link oder ein Bild.');
        }
        $daten['quelle'] = $daten['quelle'] ?? 'instagram';
        $daten['fundstueck'] = true;

        return DB::transaction(function () use ($team, $daten, $datei, $userId) {
            if (! empty($daten['inspiration_id'])) {
                $insp = $this->eigeneInspiration($team, (int) $daten['inspiration_id']);
                $neueWorte = $this->liste_($daten['schlagworte'] ?? null, true) ?? [];
                if ($neueWorte !== []) {
                    $insp->update(['schlagworte' => $this->worteVereinen($insp->schlagworte ?? [], $neueWorte)]);
                }
            } else {
                $insp = FoodAlchemistTrendInspiration::create([
                    'team_id' => $team->id,
                    'titel' => $this->inspirationsTitel($daten),
                    'schlagworte' => $this->liste_($daten['schlagworte'] ?? null, true),
                    'created_by' => $userId,
                ]);
            }
            $daten['inspiration_id'] = $insp->id;
            $trend = null;
            $trendId = ! empty($daten['trend_id']) ? (int) $daten['trend_id'] : $insp->trend_id;
            if ($trendId !== null) {
                $trend = $this->eigener($team, $trendId);
                $insp->trend_id === null && $insp->update(['trend_id' => $trend->id]);
            }
            $beleg = $this->belegSchreiben($team, $trend, $daten, $datei, $userId);
            if ($trend !== null) {
                $this->neuBewerten($trend->refresh());
            }

            return $beleg;
        });
    }

    /**
     * Pinnwand: Inspirationen der ganzen Teamfamilie (Oberteam + alle Standorte) mit ihren Quellen, neueste zuerst.
     *
     * @param  string  $ansicht  offen (noch keinem Trend zugeordnet) | zugeordnet | alle
     * @return Collection<int, FoodAlchemistTrendInspiration>
     */
    public function inspirationen(Team $team, string $ansicht = 'offen', string $suche = '', ?string $schlagwort = null): Collection
    {
        $q = FoodAlchemistTrendInspiration::whereIn('team_id', $this->fundstueckFamilie($team))
            ->with(['quellen', 'trend:id,name,status,typ']);
        if ($ansicht === 'offen') {
            $q->whereNull('trend_id');
        } elseif ($ansicht === 'zugeordnet') {
            $q->whereNotNull('trend_id');
        }
        $suche = trim($suche);
        if ($suche !== '') {
            $q->where(fn ($w) => $w->where('titel', 'like', '%'.$suche.'%')
                ->orWhereHas('quellen', fn ($b) => $b->where('titel', 'like', '%'.$suche.'%')->orWhere('notiz', 'like', '%'.$suche.'%')->orWhere('fundort', 'like', '%'.$suche.'%')));
        }
        $liste = $q->orderByDesc('updated_at')->orderByDesc('id')->limit(200)->get();
        if ($schlagwort !== null && $schlagwort !== '') {
            $liste = $liste->filter(fn ($i) => in_array(mb_strtolower($schlagwort), array_map('mb_strtolower', $i->schlagworte ?? []), true))->values();
        }

        return $liste;
    }

    /**
     * Einzelne Quellen (Fundstück-Belege) der Pinnwand — für MCP/Altbestand; die Pinnwand zeigt Inspirationen.
     *
     * @return Collection<int, FoodAlchemistTrendBeleg>
     */
    public function fundstuecke(Team $team, string $ansicht = 'offen', string $suche = '', ?string $schlagwort = null): Collection
    {
        return $this->inspirationen($team, $ansicht, $suche, $schlagwort)->flatMap(fn ($i) => $i->quellen)->values();
    }

    /**
     * Häufungen offener Inspirationen je Schlagwort (ab 2): Kandidaten zum Zusammenführen oder für einen Hype/Trend.
     *
     * @return array<string,int>
     */
    public function haeufungen(Team $team): array
    {
        $zaehler = [];
        foreach ($this->inspirationen($team, 'offen') as $i) {
            foreach ($i->schlagworte ?? [] as $w) {
                $k = mb_strtolower($w);
                $zaehler[$k] = ($zaehler[$k] ?? 0) + 1;
            }
        }
        $zaehler = array_filter($zaehler, fn ($n) => $n >= 2);
        arsort($zaehler);

        return $zaehler;
    }

    /**
     * Inspirationen zusammenführen: alle Quellen wandern in die Ziel-Inspiration, Schlagworte werden vereint, die
     * übrigen Inspirationen verschwinden. Nur Inspirationen des eigenen Teams, braucht Kuratieren.
     *
     * @param  list<int>  $quellIds
     */
    public function inspirationenZusammenfuehren(Team $team, int $zielId, array $quellIds, ?int $userId = null): FoodAlchemistTrendInspiration
    {
        $this->pruefe($userId, $team, FaRolle::Kuratieren, 'Inspirationen zusammenführen');
        $ziel = $this->eigeneInspiration($team, $zielId);
        $quellIds = array_values(array_diff(array_unique(array_map('intval', $quellIds)), [$ziel->id]));
        if ($quellIds === []) {
            throw new \RuntimeException('Bitte mindestens eine weitere Inspiration zum Zusammenführen wählen.');
        }
        DB::transaction(function () use ($team, $ziel, $quellIds) {
            $worte = $ziel->schlagworte ?? [];
            foreach ($quellIds as $qid) {
                $quelle = $this->eigeneInspiration($team, $qid);
                $worte = $this->worteVereinen($worte, $quelle->schlagworte ?? []);
                FoodAlchemistTrendBeleg::where('inspiration_id', $quelle->id)->update([
                    'inspiration_id' => $ziel->id, 'trend_id' => $ziel->trend_id,
                ]);
                $quelle->delete();
            }
            $ziel->update(['schlagworte' => $worte === [] ? null : $worte]);
        });
        if ($ziel->trend_id !== null && ($t = FoodAlchemistTrend::find($ziel->trend_id)) !== null) {
            $this->neuBewerten($t);
        }

        return $ziel->refresh();
    }

    /** Inspiration einem Trend zuordnen — alle Quellen werden Belege und zählen in dessen Konfidenz. */
    public function inspirationZuordnen(Team $team, int $inspirationId, int $trendId, ?int $userId = null): FoodAlchemistTrendInspiration
    {
        $this->pruefe($userId, $team, FaRolle::Kuratieren, 'Inspiration zuordnen');
        $insp = $this->eigeneInspiration($team, $inspirationId);
        $alt = $insp->trend_id;
        $trend = $this->eigener($team, $trendId);
        $this->inspirationAnTrend($insp, $trend->id);
        $this->neuBewerten($trend->refresh());
        if ($alt !== null && $alt !== $trend->id && ($vorher = FoodAlchemistTrend::find($alt)) !== null) {
            $this->neuBewerten($vorher);
        }

        return $insp->refresh();
    }

    /** Zuordnung lösen — die Inspiration liegt wieder offen auf der Pinnwand. */
    public function inspirationLoesen(Team $team, int $inspirationId, ?int $userId = null): FoodAlchemistTrendInspiration
    {
        $this->pruefe($userId, $team, FaRolle::Kuratieren, 'Inspiration lösen');
        $insp = $this->eigeneInspiration($team, $inspirationId);
        $trend = $insp->trend;
        $this->inspirationAnTrend($insp, null);
        if ($trend !== null) {
            $this->neuBewerten($trend->refresh());
        }

        return $insp->refresh();
    }

    /**
     * Inspiration samt Quellen löschen: im eigenen Team mit Kuratieren; Moderation durch den FA-Admin des
     * Oberteams für Inspirationen seiner Standorte (wie bei einzelnen Fundstücken).
     */
    public function inspirationLoeschen(Team $team, int $inspirationId, ?int $userId = null): void
    {
        $insp = FoodAlchemistTrendInspiration::whereIn('team_id', $this->fundstueckFamilie($team))->findOrFail($inspirationId);
        $besitzer = Team::findOrFail($insp->team_id);
        $this->pruefe($userId, $besitzer, (int) $insp->team_id === (int) $team->id ? FaRolle::Kuratieren : FaRolle::Admin, 'Inspiration löschen');
        $trend = $insp->trend;
        DB::transaction(function () use ($besitzer, $insp) {
            foreach ($insp->quellen()->get() as $beleg) {
                $this->dateiEntfernen($besitzer, $beleg);
                $beleg->delete();
            }
            $insp->delete();
        });
        if ($trend !== null) {
            $this->neuBewerten($trend->refresh());
        }
    }

    /** Titelbild einer Inspiration: die erste Quelle mit Bilddatei. */
    public function titelbild(FoodAlchemistTrendInspiration $insp): ?FoodAlchemistTrendBeleg
    {
        return $insp->quellen->first(fn ($b) => $b->context_file_id !== null && str_starts_with((string) $b->datei_mime, 'image/'));
    }

    /**
     * Lesesicht der Inspirations-Pinnwand (Dominique 2026-10-08): Standorte laden auch hoch, und die ganze
     * Teamfamilie sieht sich gegenseitig — Hauptteam des Kunden ({@see FaRechte::kundenHauptTeam}, endet unter dem
     * Master-Team) plus alle Nachfahren, also auch
     * Geschwister-Standorte. Bewusst NICHT über visibleToTeam/TeamScope (die gelten nur nach oben und tragen
     * 130 Models). Schreiben bleibt beim besitzenden Team (eigeneInspiration).
     *
     * @return list<int>
     */
    public function fundstueckFamilie(Team $team): array
    {
        // Kundengrenze gemeinsam mit Spec 77: Hauptteam des Kunden (endet unter dem Master-Team, sonst oberstes Team)
        $wurzel = (int) $this->rechte->kundenHauptTeam($team)->id;

        return \Platform\FoodAlchemist\Jobs\RecomputeTeamRecipesJob::teamUndNachfahren($wurzel);
    }

    // ── Bewertung (Kap. 3.3) ───────────────────────────────────────────────

    /**
     * Konfidenz, Bestätigung und Radar-Fähigkeit aus den Belegen.
     *
     * hoch    = mindestens eine bestätigende Quelle (Marktforschung, Kaufverhalten, Befragung) UND eine weitere Quelle
     * mittel  = eine bestätigende Quelle allein, Literatur, oder zwei verschiedene sonstige Quellen
     * niedrig = sonst — z. B. nur Instagram, nur Google Trends, nur eine Branchenquelle
     *
     * @return array{konfidenz:string, begruendung:string, quellen:list<string>, bestaetigt:bool, befragung:bool, befragung_anteil:?float}
     */
    public function bewertung(FoodAlchemistTrend $trend): array
    {
        $belege = FoodAlchemistTrendBeleg::where('trend_id', $trend->id)->get(['quelle', 'anteil']);
        $quellen = $belege->pluck('quelle')->unique()->values()->all();
        $hart = array_values(array_intersect($quellen, V::HARTE_QUELLEN));
        $nichtSozial = array_values(array_diff($quellen, V::SOZIALE_QUELLEN));
        $befragung = in_array('befragung', $quellen, true);
        $anteil = $belege->where('quelle', 'befragung')->whereNotNull('anteil')->max('anteil');

        $namen = fn (array $q) => implode(', ', array_map(fn ($s) => V::QUELLEN[$s] ?? $s, $q));
        if ($hart !== [] && count($quellen) >= 2) {
            $konfidenz = 'hoch';
            $grund = 'Bestätigt durch '.$namen($hart).' und gestützt durch weitere Quellen.';
        } elseif ($hart !== []) {
            $konfidenz = 'mittel';
            $grund = 'Bestätigt durch '.$namen($hart).', aber noch keine zweite Quelle.';
        } elseif (in_array('literatur', $quellen, true) || count($nichtSozial) >= 2) {
            $konfidenz = 'mittel';
            $grund = 'Gestützt durch '.$namen($nichtSozial ?: $quellen).', ohne Bestätigung durch Marktforschung, Kaufverhalten oder Befragung.';
        } elseif ($quellen === []) {
            $konfidenz = 'niedrig';
            $grund = 'Noch kein Beleg.';
        } else {
            $konfidenz = 'niedrig';
            $grund = $nichtSozial === []
                ? 'Nur Social- oder Suchsignale ('.$namen($quellen).'). Die zählen erst mit Bestätigung.'
                : 'Nur eine Quelle ('.$namen($quellen).').';
        }

        return [
            'konfidenz' => $konfidenz,
            'begruendung' => $grund,
            'quellen' => $quellen,
            'bestaetigt' => $nichtSozial !== [],
            'befragung' => $befragung,
            'befragung_anteil' => $anteil !== null ? (float) $anteil : null,
        ];
    }

    /** Warum darf der Trend (noch) nicht aufs Radar? null = darf. */
    public function radarHindernis(FoodAlchemistTrend $trend): ?string
    {
        $fehlt = [];
        foreach (['typ' => 'Trend oder Hype', 'ebene' => 'Ebene', 'kategorie' => 'Kategorie'] as $feld => $label) {
            if (empty($trend->{$feld})) {
                $fehlt[] = $label;
            }
        }
        if ($fehlt !== []) {
            return 'Einordnung fehlt ('.implode(', ', $fehlt).').';
        }
        if (! $this->bewertung($trend)['bestaetigt']) {
            return 'Es gibt nur Social- oder Suchsignale. Ein Beleg aus Marktforschung, Kaufverhalten, Befragung, Literatur, Fachpresse, Branche oder eigener Beobachtung fehlt.';
        }

        return null;
    }

    public function neuBewerten(FoodAlchemistTrend $trend): FoodAlchemistTrend
    {
        $b = $this->bewertung($trend);
        $trend->forceFill([
            'konfidenz' => $b['konfidenz'],
            'befragung_bestaetigt' => $b['befragung'],
            'befragung_anteil' => $b['befragung_anteil'],
        ])->save();

        return $trend;
    }

    // ── Radar-Geometrie (Click Dummy 3) ────────────────────────────────────

    /**
     * Position je Trend im Radar: Ring = Ebene, Sektor = Kategorie (Food links, Getränke/Deko/Events rechts).
     * Die Lage im Ring kommt aus einem stabilen Hash des Slugs — sie trägt keine Bedeutung, bleibt aber gleich.
     *
     * @return array{x:float, y:float}
     */
    /**
     * Positionen für alle Radar-Trends ohne Überlappung (Dominique 09.10.: zwei Food-Megatrends lagen
     * übereinander). Je Zelle (Kategorie × Ebene) gleichmäßig über den Sektor verteilt; Lage im Sektor
     * und Abstand von der Mitte bleiben stabil am Namen (Hash), damit das Radar beim Neuladen nicht springt.
     *
     * @param  iterable<FoodAlchemistTrend>  $trends
     * @return array<int, array{x: float, y: float}> Trend-ID → Position
     */
    public function radarPositionen(iterable $trends, float $cx = 320, float $cy = 320, float $maxR = 268): array
    {
        $zellen = [];
        foreach ($trends as $t) {
            $zellen[($t->kategorie ?? 'food').'|'.($t->ebene ?? 'mode')][] = $t;
        }
        $out = [];
        foreach ($zellen as $schluessel => $liste) {
            usort($liste, fn ($a, $b) => strcmp((string) $a->slug, (string) $b->slug));
            [$kat, $eb] = explode('|', $schluessel);
            [$von, $bis] = self::SEKTOREN[$kat] ?? self::SEKTOREN['food'];
            [$innen, $aussen] = self::RINGE[$eb] ?? self::RINGE['mode'];
            $rand = ($bis - $von) * 0.06;               // nicht direkt auf die Sektorlinie
            $span = ($bis - $von) - 2 * $rand;
            $n = count($liste);
            $versatz = $this->hash01($schluessel);       // stabile Drehung je Zelle
            foreach ($liste as $i => $t) {
                $anteil = fmod($versatz + $i / $n, 1.0);
                $winkel = $von + $rand + $anteil * $span;
                // abwechselnd innen/außen im Ring, damit dichte Zellen auch radial Luft haben
                $tiefe = $n === 1 ? $this->hash01($t->slug.'b') : ($i % 2 === 0 ? 0.3 : 0.7);
                $r = ($innen + $tiefe * ($aussen - $innen)) * $maxR;
                $rad = deg2rad($winkel);
                $out[(int) $t->id] = ['x' => round($cx + $r * cos($rad), 1), 'y' => round($cy - $r * sin($rad), 1)];
            }
        }

        return $out;
    }

    public function radarPosition(FoodAlchemistTrend $trend, float $cx = 320, float $cy = 320, float $maxR = 268): array
    {
        [$von, $bis] = self::SEKTOREN[$trend->kategorie] ?? self::SEKTOREN['food'];
        [$innen, $aussen] = self::RINGE[$trend->ebene] ?? self::RINGE['mode'];
        $winkel = $von + $this->hash01($trend->slug.'a') * ($bis - $von);
        $r = ($innen + $this->hash01($trend->slug.'b') * ($aussen - $innen)) * $maxR;
        $rad = deg2rad($winkel);

        return ['x' => round($cx + $r * cos($rad), 1), 'y' => round($cy - $r * sin($rad), 1)];
    }

    /** Radiusband je Ebene als Anteil des Außenradius. */
    public const RINGE = ['mode' => [0.12, 0.32], 'konsum' => [0.35, 0.57], 'mega' => [0.60, 0.82], 'meta' => [0.85, 0.97]];

    /** Winkelbereich je Kategorie in Grad (0° = rechts, gegen den Uhrzeigersinn). */
    public const SEKTOREN = ['food' => [92, 268], 'getraenke' => [-88, -30], 'deko' => [-28, 28], 'format' => [30, 88]];

    private function hash01(string $s): float
    {
        $h = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $h = ($h * 31 + ord($s[$i])) % 4294967296;
        }

        return ($h % 10000) / 10000;
    }

    // ── intern ─────────────────────────────────────────────────────────────

    private function belegSchreiben(Team $team, ?FoodAlchemistTrend $trend, array $d, ?UploadedFile $datei, ?int $userId): FoodAlchemistTrendBeleg
    {
        $quelle = (string) $this->enum($d['quelle'] ?? 'beobachtung', V::QUELLEN, 'Quelle');
        $url = trim((string) ($d['url'] ?? ''));
        if ($url !== '' && ! preg_match('~^https?://~i', $url)) {
            throw new \RuntimeException('Der Link muss mit http:// oder https:// beginnen.');
        }
        $anteil = $d['anteil'] ?? null;
        if ($anteil !== null && $anteil !== '') {
            $anteil = (float) str_replace(',', '.', (string) $anteil);
            if ($anteil < 0 || $anteil > 100) {
                throw new \RuntimeException('Der Anteil der Befragten muss zwischen 0 und 100 Prozent liegen.');
            }
        } else {
            $anteil = null;
        }
        $beleg = FoodAlchemistTrendBeleg::create([
            'team_id' => $team->id,
            'trend_id' => $trend?->id,
            'quelle' => $quelle,
            'titel' => $this->text($d['titel'] ?? null, 255),
            'url' => $url !== '' ? mb_substr($url, 0, 1000) : null,
            'notiz' => $this->text($d['notiz'] ?? null),
            'schlagworte' => $this->liste_($d['schlagworte'] ?? null, true),
            'fundort' => $this->text($d['fundort'] ?? null, 160),
            'beobachtet_am' => ! empty($d['beobachtet_am']) ? $d['beobachtet_am'] : now()->toDateString(),
            'anteil' => $anteil,
            'signal_id' => $d['signal_id'] ?? null,
            'fundstueck' => (bool) ($d['fundstueck'] ?? false),
            'inspiration_id' => isset($d['inspiration_id']) ? (int) $d['inspiration_id'] : null,
            'created_by' => $userId,
        ]);
        if ($datei !== null) {
            $this->dateiSpeichern($team, $beleg, $datei, $userId);
        }

        return $beleg->refresh();
    }

    private function dateiSpeichern(Team $team, FoodAlchemistTrendBeleg $beleg, UploadedFile $datei, ?int $userId): void
    {
        $mime = strtolower((string) $datei->getMimeType());
        if (! in_array($mime, V::DATEI_MIMES, true)) {
            throw new \RuntimeException('Bitte ein Bild (JPG, PNG, WebP, HEIC) oder ein PDF anhängen.');
        }
        if ($datei->getSize() > V::DATEI_MAX_KB * 1024) {
            throw new \RuntimeException('Die Datei ist größer als 15 MB.');
        }
        $res = app(ContextFileService::class)->uploadForContext($datei, 'foodalchemist.trend_beleg', (int) $beleg->id, [
            'team_id' => $team->id, 'user_id' => $userId, 'folder' => 'foodalchemist/trends/'.($beleg->trend_id !== null ? (int) $beleg->trend_id : 'fundstuecke'),
            'keep_original' => true, 'generate_variants' => false,
        ]);
        $beleg->update([
            'context_file_id' => (int) $res['id'],
            'datei_name' => mb_substr((string) ($res['original_name'] ?? $datei->getClientOriginalName()), 0, 255),
            'datei_mime' => $mime,
        ]);
    }

    private function dateiEntfernen(Team $team, FoodAlchemistTrendBeleg $beleg): void
    {
        if ($beleg->context_file_id !== null) {
            app(ContextFileService::class)->delete((int) $beleg->context_file_id, $team->id);
        }
    }

    /** @return array<string,?string> nur die übergebenen Einordnungsfelder, geprüft */
    private function einordnungsFelder(array $d, bool $nurVorhandene = false): array
    {
        $felder = ['typ' => V::TYPEN, 'ebene' => V::EBENEN, 'kategorie' => V::KATEGORIEN, 'food_cluster' => V::FOOD_CLUSTER,
            'sicht' => V::SICHTEN, 'gartner_phase' => V::GARTNER_PHASEN];
        $labels = ['typ' => 'Typ', 'ebene' => 'Ebene', 'kategorie' => 'Kategorie', 'food_cluster' => 'Food-Cluster', 'sicht' => 'Sicht', 'gartner_phase' => 'Gartner-Phase'];
        $out = [];
        foreach ($felder as $feld => $liste) {
            if ($nurVorhandene && ! array_key_exists($feld, $d)) {
                continue;
            }
            $wert = $d[$feld] ?? null;
            $out[$feld] = $this->enum($wert === '' ? null : $wert, $liste, $labels[$feld]);
        }
        $kategorie = $out['kategorie'] ?? null;
        if (! empty($out['food_cluster']) && $kategorie !== null && $kategorie !== 'food') {
            throw new \RuntimeException('Ein Food-Cluster gibt es nur bei der Kategorie Food.');
        }

        return $out;
    }

    /** Sparten prüfen (Liste oder Komma-Text); leer → null = für alle Sparten. @return list<string>|null */
    private function sparten(mixed $wert): ?array
    {
        $liste = is_array($wert) ? $wert : array_map('trim', explode(',', (string) $wert));
        $liste = array_values(array_unique(array_filter(array_map('strval', $liste), fn ($s) => $s !== '')));
        foreach ($liste as $s) {
            $this->enum($s, V::SPARTEN, 'Sparte');
        }

        return $liste === [] ? null : $liste;
    }

    private function hatEinordnung(array $felder): bool
    {
        return ! empty($felder['typ']) || ! empty($felder['ebene']) || ! empty($felder['kategorie']);
    }

    private function enum(mixed $wert, array $liste, string $label): ?string
    {
        if ($wert === null) {
            return null;
        }
        $wert = (string) $wert;
        if (! array_key_exists($wert, $liste)) {
            throw new \RuntimeException("Ungültiger Wert für {$label}: „{$wert}\". Erlaubt: ".implode(', ', array_keys($liste)).'.');
        }

        return $wert;
    }

    private function belegHatInhalt(array $d): bool
    {
        foreach (['url', 'notiz', 'titel'] as $feld) {
            if (trim((string) ($d[$feld] ?? '')) !== '') {
                return true;
            }
        }

        return isset($d['anteil']) && $d['anteil'] !== '';
    }

    /** Inspiration des eigenen Teams (Schreiben nur im besitzenden Team). */
    private function eigeneInspiration(Team $team, int $id): FoodAlchemistTrendInspiration
    {
        return FoodAlchemistTrendInspiration::where('team_id', $team->id)->findOrFail($id);
    }

    /** Inspiration und alle ihre Quellen an einen Trend hängen (oder mit null lösen). */
    private function inspirationAnTrend(FoodAlchemistTrendInspiration $insp, ?int $trendId): void
    {
        $insp->update(['trend_id' => $trendId]);
        FoodAlchemistTrendBeleg::where('inspiration_id', $insp->id)->update(['trend_id' => $trendId]);
    }

    /** Inspirations-IDs aus `inspiration_ids` und (Altbestand) `fundstueck_ids` — eine Quelle nimmt ihre Inspiration mit. */
    public function inspirationIdsAus(Team $team, array $daten): array
    {
        $ids = array_map('intval', (array) ($daten['inspiration_ids'] ?? []));
        $fundIds = array_map('intval', (array) ($daten['fundstueck_ids'] ?? []));
        if ($fundIds !== []) {
            $ids = array_merge($ids, FoodAlchemistTrendBeleg::whereIn('team_id', $this->fundstueckFamilie($team))
                ->where('fundstueck', true)->whereIn('id', $fundIds)->whereNotNull('inspiration_id')
                ->pluck('inspiration_id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    private function leereInspirationEntfernen(?int $inspirationId): void
    {
        if ($inspirationId !== null && ! FoodAlchemistTrendBeleg::where('inspiration_id', $inspirationId)->exists()) {
            FoodAlchemistTrendInspiration::whereKey($inspirationId)->delete();
        }
    }

    private function inspirationsTitel(array $daten): string
    {
        $titel = trim((string) ($daten['titel'] ?? ''));
        if ($titel === '' && ! empty($daten['url'])) {
            $titel = (string) (parse_url((string) $daten['url'], PHP_URL_HOST) ?: $daten['url']);
        }

        return mb_substr($titel !== '' ? $titel : 'Fundstück vom '.now()->format('d.m.Y'), 0, 255);
    }

    /** @return list<string> */
    private function worteVereinen(array $a, array $b): array
    {
        $out = $a;
        foreach ($b as $w) {
            if (! in_array(mb_strtolower($w), array_map('mb_strtolower', $out), true)) {
                $out[] = $w;
            }
        }

        return array_slice($out, 0, 15);
    }

    private function eigener(Team $team, int $id): FoodAlchemistTrend
    {
        return FoodAlchemistTrend::where('team_id', $team->id)->findOrFail($id);
    }

    private function pruefe(?int $userId, Team $team, FaRolle $rolle, string $wofuer): void
    {
        if ($userId !== null) {
            $this->rechte->pruefeId($userId, $team, $rolle, $wofuer);
        } else {
            $this->rechte->pruefeAngemeldet($team, $rolle, $wofuer);
        }
    }

    /** Kennung aus dem Namen (deutsche Umlaute: ä → ae). Dubletten-Prüfung läuft darüber. */
    public function slugFuer(string $name): string
    {
        $slug = Str::slug($name, '-', 'de');

        return mb_substr($slug !== '' ? $slug : 'trend-'.substr(md5($name), 0, 8), 0, 180);
    }

    private function text(mixed $wert, ?int $max = null): ?string
    {
        $t = trim((string) ($wert ?? ''));
        if ($t === '') {
            return null;
        }

        return $max !== null ? mb_substr($t, 0, $max) : $t;
    }

    /** @return list<string>|null */
    private function liste_(mixed $wert, bool $hashtag = false): ?array
    {
        if ($wert === null || $wert === '') {
            return null;
        }
        $teile = is_array($wert) ? $wert : preg_split('/[,;\n]+/', (string) $wert);
        $out = [];
        foreach ($teile as $t) {
            $t = trim((string) $t);
            if ($hashtag) {
                $t = ltrim($t, '#');
            }
            if ($t !== '' && ! in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) {
                $out[] = mb_substr($t, 0, 80);
            }
        }

        return $out === [] ? null : array_slice($out, 0, 10);
    }
}
