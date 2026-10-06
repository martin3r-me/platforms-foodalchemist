<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\AusgabeStatus;
use Platform\FoodAlchemist\Services\Concerns\PruefstOutletZuordnung;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie;

/**
 * M14 / Speiseplan v2 — Kantinen-/Kita-Logik: Menü-Linien × ECHTE Wochentage ×
 * Mahlzeit, belegt mit Concept/Paket/Gericht (D-PLAN-1). Wochen-Matrix + Monats-
 * Kalender, Kosten je Tag/Woche, Wiederholungs-Check in echten Tagen, Veggie-
 * Tagescheck, Zyklus-Vorlage ausrollen. Scope-Härte + Owner-Guard.
 */
class SpeiseplanService
{
    use PruefstOutletZuordnung;

    public function __construct(private ConceptService $concepts)
    {
    }

    public const MAHLZEITEN = ['fruehstueck' => 'Frühstück', 'mittag' => 'Mittag', 'abend' => 'Abend', 'snack' => 'Snack'];

    public const WOCHENTAGE = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    /**
     * Spec 31 (GV-Ausbau) — Kostformen, deren Tages-Abdeckung geprüft wird. Alle aus den
     * vorhandenen Diät-Spec-Flags am Rezept ableitbar (keine neuen Daten). „Passiert/püriert"
     * bewusst NICHT dabei — dafür gibt es (noch) kein Datenfeld, also nicht raten.
     */
    public const KOSTFORMEN = [
        'vegetarisch' => 'Vegetarisch',
        'vegan' => 'Vegan',
        'schweinefrei' => 'Schweinefleischfrei',
        'glutenfrei' => 'Glutenfrei',
        'laktosefrei' => 'Laktosefrei',
        'halal' => 'Halal',
    ];

    /** In-Request-Memo: Eintrag-Inhalt (c{id}/p{id}/g{id}) → aufgelöste Gerichte-Sammlung. */
    private array $gerichteCache = [];

    /** Konfidenz-Rang (schwächstes Glied) — lokal, da die Aggregat-Konstante privat ist. */
    private const KONF_RANG = ['unknown' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistSpeiseplan::visibleToTeam($team)
            ->select($this->browserSpalten('foodalchemist_menu_plans'))
            ->withCount('entries')
            ->when(($filters['search'] ?? '') !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $filters['search']))
            ->orderBy('name')->paginate($perPage);
    }

    /** Listen-Spalten OHNE große JSON-Blobs (Snapshots) → kein MySQL-„Out of sort memory". */
    private function browserSpalten(string $table): array
    {
        static $cache = [];
        if (! isset($cache[$table])) {
            $exclude = ['presentation_snapshot_json', 'presentation_settings_json'];
            $all = \Illuminate\Support\Facades\Schema::getColumnListing($table);
            $cols = array_values(array_diff($all, $exclude));
            $cache[$table] = $cols !== [] ? array_map(fn ($c) => $table . '.' . $c, $cols) : [$table . '.*'];
        }

        return $cache[$table];
    }

    public function detail(Team $team, int $id): ?FoodAlchemistSpeiseplan
    {
        return FoodAlchemistSpeiseplan::visibleToTeam($team)
            ->with(['lines',
                'entries.concept:id,name,price_per_person_cache',
                'entries.package:id,name,price_per_person,ek_per_person',
                // Spec 57 · 0.5: volle Spalten. Die frühere Liste (id,name,sales_net,ek_total_eur)
                // verschluckte `sales_wording_standard` — der Aushang zeigte den internen Namen —
                // und Diät-/Nährwert-Spalten, die die Zellen-Kennzahlen brauchen.
                'entries.dish',
                'entries.line',
                'crmCompany', 'crmContact'])
            ->find($id);
    }

    private const FELDER = [
        'name', 'start_date', 'cycle_weeks', 'min_abstand_tage', 'status', 'description', 'note',
        'default_pax', 'budget_wareneinsatz',
        // Spec 33 P2: beide Zuordnungsachsen. Ohne `outlet_id` waren zwei Kantinen im selben
        // Team nicht unterscheidbar — die größte Lücke der drei Ausgabeformen.
        'outlet_id', 'crm_company_id', 'crm_contact_id',
        // Spec 57 · Paket 9: Öffnungstage (ISO 1–7), leer = Mo–Fr.
        'opening_days',
    ];

    /** Spec 57 · Paket 2: pflegbare Felder einer Linie (Whitelist für add/update/dupliziere). */
    private const LINIEN_FELDER = [
        'name', 'color', 'is_vegetarian', 'role', 'plu', 'price_mode', 'price_value',
        'target_wes_min_pct', 'target_wes_max_pct', 'default_pax', 'is_standing', 'meal',
    ];

    public function create(Team $team, array $in): FoodAlchemistSpeiseplan
    {
        // Spec 33 P2: fremde Betriebe fallen hier raus — `outlet_id` zeigt auf ein Team-Vokabular
        // und wird vom Datensatz-Guard nicht mit erfasst.
        $in = $this->pruefeOutlet($team, $in);

        $plan = FoodAlchemistSpeiseplan::create([
            'team_id' => $team->id,
            'name' => trim((string) ($in['name'] ?? 'Neuer Speiseplan')) ?: 'Neuer Speiseplan',
            'start_date' => $in['start_date'] ?? Carbon::now()->startOfWeek()->format('Y-m-d'),
            'cycle_weeks' => max(1, (int) ($in['cycle_weeks'] ?? 4)),
            'min_abstand_tage' => max(0, (int) ($in['min_abstand_tage'] ?? 0)),
            'status' => AusgabeStatus::normalisiere($in['status'] ?? null)->value,
            // Spec 33 P2: beide Zuordnungsachsen, beide optional.
            'outlet_id' => $in['outlet_id'] ?? null,
            'crm_company_id' => $in['crm_company_id'] ?? null,
            'crm_contact_id' => $in['crm_contact_id'] ?? null,
            'opening_days' => array_key_exists('opening_days', $in) ? $this->normOeffnungstage($in['opening_days']) : null,
        ]);

        // Starter-Linien (Kantinen-Standard) — pro Plan frei änderbar. Spec 57: mit Rolle, damit
        // Tagesfuß und Budget je Gast von Anfang an die Hauptgänge als Gäste zählen.
        foreach ([['Menü 1', '#D85A30', false, 'hauptgang'], ['Vegetarisch', '#639922', true, 'hauptgang'], ['Dessert', '#EF9F27', false, 'dessert']] as $i => [$n, $f, $v, $r]) {
            $plan->lines()->create(['team_id' => $team->id, 'name' => $n, 'color' => $f, 'is_vegetarian' => $v, 'role' => $r, 'sort_order' => $i + 1]);
        }

        return $plan;
    }

    /**
     * Spec 57 · Paket 9: Öffnungstage säubern — ISO-Wochentage 1–7, eindeutig, sortiert.
     * Leer → null (= Standard Mo–Fr), damit „kein Tag“ nie gespeichert wird.
     *
     * @return list<int>|null
     */
    private function normOeffnungstage($wert): ?array
    {
        if (is_string($wert)) {
            $wert = preg_split('/[\s,;]+/', $wert, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $tage = array_values(array_unique(array_filter(
            array_map('intval', (array) $wert),
            fn (int $t) => $t >= 1 && $t <= 7,
        )));
        sort($tage);

        return $tage !== [] ? $tage : null;
    }

    /**
     * Spec 57 · Paket 2: Linien-Eingaben säubern (nur Whitelist, Typen, Grenzen).
     *
     * @return array<string, mixed>
     */
    private function linienFelder(array $in): array
    {
        $upd = array_intersect_key($in, array_flip(self::LINIEN_FELDER));
        if (array_key_exists('name', $upd)) {
            $upd['name'] = trim((string) $upd['name']);
        }
        foreach (['is_vegetarian', 'is_standing'] as $f) {
            if (array_key_exists($f, $upd)) {
                $upd[$f] = (bool) $upd[$f];
            }
        }
        if (array_key_exists('role', $upd)) {
            $upd['role'] = array_key_exists((string) $upd['role'], FoodAlchemistSpeiseplanLinie::ROLLEN) ? (string) $upd['role'] : null;
        }
        if (array_key_exists('meal', $upd)) {
            $upd['meal'] = array_key_exists((string) $upd['meal'], self::MAHLZEITEN) ? (string) $upd['meal'] : null;
        }
        if (array_key_exists('price_mode', $upd)) {
            $upd['price_mode'] = in_array($upd['price_mode'], FoodAlchemistSpeiseplanLinie::PREIS_MODI, true) ? $upd['price_mode'] : 'auto';
        }
        if (array_key_exists('plu', $upd)) {
            $plu = trim((string) $upd['plu']);
            $upd['plu'] = $plu !== '' ? mb_substr($plu, 0, 32) : null;
        }
        foreach (['price_value' => [0, 9999], 'target_wes_min_pct' => [0, 100], 'target_wes_max_pct' => [0, 100]] as $f => [$min, $max]) {
            if (array_key_exists($f, $upd)) {
                $roh = $upd[$f];
                $upd[$f] = ($roh === '' || $roh === null) ? null : max($min, min($max, (float) str_replace(',', '.', (string) $roh)));
            }
        }
        // Zielband: vertauschte Grenzen still geraderücken statt ein unmögliches Band zu speichern.
        if (isset($upd['target_wes_min_pct'], $upd['target_wes_max_pct']) && $upd['target_wes_min_pct'] > $upd['target_wes_max_pct']) {
            [$upd['target_wes_min_pct'], $upd['target_wes_max_pct']] = [$upd['target_wes_max_pct'], $upd['target_wes_min_pct']];
        }
        if (array_key_exists('default_pax', $upd)) {
            $pax = (int) $upd['default_pax'];
            $upd['default_pax'] = $pax > 0 ? $pax : null;
        }

        return $upd;
    }

    public function update(Team $team, int $id, array $in): FoodAlchemistSpeiseplan
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($id);
        $this->guard($plan, $team);
        $update = $this->pruefeOutlet($team, array_intersect_key($in, array_flip(self::FELDER)));
        // Spec 33 P0: Status IMMER durch den Enum schleusen — `status` steht in FELDER und ist
        // damit über Service UND MCP frei beschreibbar.
        if (array_key_exists('status', $update)) {
            $update['status'] = AusgabeStatus::normalisiere((string) $update['status'])->value;
        }
        foreach (['cycle_weeks' => 1, 'min_abstand_tage' => 0, 'default_pax' => 1] as $f => $min) {
            if (array_key_exists($f, $update)) {
                $update[$f] = max($min, (int) $update[$f]);
            }
        }
        if (array_key_exists('budget_wareneinsatz', $update)) {
            $update['budget_wareneinsatz'] = ($update['budget_wareneinsatz'] === '' || $update['budget_wareneinsatz'] === null)
                ? null : max(0, (float) str_replace(',', '.', (string) $update['budget_wareneinsatz']));
        }
        if (array_key_exists('opening_days', $update)) {
            $update['opening_days'] = $this->normOeffnungstage($update['opening_days']);
        }
        $plan->update($update);

        return $plan->refresh();
    }

    public function verknuepfeKunde(Team $team, int $id, ?int $companyId, ?int $contactId): FoodAlchemistSpeiseplan
    {
        return $this->update($team, $id, ['crm_company_id' => $companyId, 'crm_contact_id' => $contactId]);
    }

    /**
     * Tiefe Kopie eines Speiseplans: Kopf (FELDER) → Linien (Menü 1/Vegetarisch/… mit line-Map)
     * → Einträge (Zellen, line_id über die Map remappt). Status=Entwurf. Muster wie
     * SpeisekarteService::dupliziere; nutzt das MODELL-::create (KEINE Starter-Linien).
     */
    public function dupliziere(Team $team, int $id, array $ueberschreiben = [], bool $quelleMerken = false): FoodAlchemistSpeiseplan
    {
        $quelle = FoodAlchemistSpeiseplan::visibleToTeam($team)
            ->with(['lines' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'), 'entries'])
            ->findOrFail($id);
        $this->guard($quelle, $team);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($quelle, $team, $ueberschreiben, $quelleMerken) {
            $neu = FoodAlchemistSpeiseplan::create($this->pruefeOutlet($team, array_merge(
                array_intersect_key($quelle->only(self::FELDER), array_flip(self::FELDER)),
                ['team_id' => $team->id, 'name' => $quelle->name . ' (Kopie)', 'status' => AusgabeStatus::Entwurf->value],
                // Spec 59: Vorgaben gehören zum Plan — Kopie (auch Betriebs-Kopie) übernimmt sie.
                ['vorgaben' => $quelle->vorgaben],
                // Spec 57 · Paket 7: Betriebs-Kopie merkt sich ihre Vorlage (sonst eine freie Kopie).
                $quelleMerken ? ['source_plan_id' => $quelle->id, 'source_synced_at' => now()] : [],
                array_intersect_key($ueberschreiben, array_flip(array_merge(self::FELDER, ['name']))),
            )));

            // Linien kopieren + Map alt→neu (Einträge referenzieren die Linie).
            $lineMap = [];
            foreach ($quelle->lines as $l) {
                // Spec 57: ALLE pflegbaren Linien-Felder explizit mitnehmen (Rolle, PLU, Preis, Zielband …).
                $kopie = FoodAlchemistSpeiseplanLinie::create(array_merge(
                    $l->only(self::LINIEN_FELDER),
                    ['team_id' => $neu->team_id, 'menu_plan_id' => $neu->id, 'sort_order' => $l->sort_order],
                    $quelleMerken ? ['source_line_id' => $l->id] : [],
                ));
                $lineMap[$l->id] = $kopie->id;
            }
            // Einträge (Zellen) kopieren, line_id remappen (null bleibt null).
            foreach ($quelle->entries as $e) {
                FoodAlchemistSpeiseplanEintrag::create([
                    'team_id' => $neu->team_id, 'menu_plan_id' => $neu->id,
                    'week' => $e->week, 'weekday' => $e->weekday, 'meal' => $e->meal, 'position' => $e->position,
                    'entry_date' => $e->entry_date, 'pax' => $e->pax,
                    'line_id' => $e->line_id !== null ? ($lineMap[$e->line_id] ?? null) : null,
                    'concept_id' => $e->concept_id, 'package_id' => $e->package_id, 'sales_recipe_id' => $e->sales_recipe_id,
                ]);
            }

            return $neu->refresh();
        });
    }

    // ── Spec 57 · Paket 7: Vorlage für Betriebe (verknüpfte Kopie je Betrieb, E10) ──

    /** Plan als Vorlage für Betriebe freigeben oder zurücknehmen. Eine Betriebs-Kopie kann keine Vorlage sein. */
    public function setzeVorlage(Team $team, int $planId, bool $istVorlage): FoodAlchemistSpeiseplan
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);
        if ($istVorlage && $plan->source_plan_id !== null) {
            throw new \RuntimeException('Dieser Plan ist selbst eine Betriebs-Kopie und kann keine Vorlage sein.');
        }
        $plan->update(['is_template' => $istVorlage]);

        return $plan->refresh();
    }

    /**
     * Betriebs-Kopie einer Vorlage anlegen: tiefe Kopie (Linien mit Rückverweis, Einträge),
     * zugeordnet zum Betrieb, Status Entwurf. Nur im selben Team (der Betrieb muss dem Team
     * gehören) — Team-Grenzen überschreitet das bewusst nicht (D1, E10).
     */
    public function betriebsKopieAnlegen(Team $team, int $vorlageId, int $outletId): FoodAlchemistSpeiseplan
    {
        $vorlage = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($vorlageId);
        $this->guard($vorlage, $team);
        if (! $vorlage->is_template) {
            throw new \RuntimeException('Der Plan ist nicht als Vorlage freigegeben.');
        }
        $outlet = \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)->find($outletId);
        if ($outlet === null) {
            throw new \RuntimeException('Betrieb nicht gefunden oder nicht im eigenen Team.');
        }
        if (FoodAlchemistSpeiseplan::where('source_plan_id', $vorlage->id)->where('outlet_id', $outlet->id)->exists()) {
            throw new \RuntimeException('Für „' . $outlet->name . '“ gibt es schon eine Kopie dieser Vorlage.');
        }

        return $this->dupliziere($team, $vorlage->id, [
            'name' => $vorlage->name . ' · ' . $outlet->name,
            'outlet_id' => $outlet->id,
        ], true);
    }

    /**
     * Betriebs-Kopien einer Vorlage mit Stand des Abgleichs.
     *
     * @return list<array{id:int, name:string, outlet:?string, status:string, aus_vorlage:int, lokal:int, synced_at:?string}>
     */
    public function betriebsKopien(Team $team, int $vorlageId): array
    {
        $kopien = FoodAlchemistSpeiseplan::where('team_id', $team->id)->where('source_plan_id', $vorlageId)->orderBy('name')->get();
        $outlets = \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)->whereIn('id', $kopien->pluck('outlet_id')->filter())->pluck('name', 'id');

        return $kopien->map(function ($k) use ($team, $outlets) {
            $abgleich = $this->vorlagenAbgleich($team, (int) $k->id);

            return [
                'id' => (int) $k->id, 'name' => $k->name,
                'outlet' => $k->outlet_id !== null ? ($outlets[$k->outlet_id] ?? null) : null,
                'status' => $k->statusWert()->label(),
                'aus_vorlage' => collect($abgleich['zellen'])->where('art', 'vorlage_geaendert')->count() + count($abgleich['neue_linien']),
                'lokal' => collect($abgleich['zellen'])->where('art', 'lokal_abweichend')->count(),
                'synced_at' => $k->source_synced_at?->format('d.m.Y H:i'),
            ];
        })->values()->all();
    }

    /**
     * Abgleich Betriebs-Kopie ↔ Vorlage ab einem Datum (Standard heute): je Zelle (Datum ×
     * Mahlzeit × Linie der Vorlage) die Inhalte beider Seiten, wenn sie abweichen. `art`:
     * `vorlage_geaendert` = die Vorlage hat die Zelle seit dem letzten Abgleich geändert;
     * `lokal_abweichend` = der Betrieb weicht bewusst ab. Neue Linien der Vorlage extra.
     *
     * @return array{vorlage: ?array{id:int, name:string}, zellen: list<array>, neue_linien: list<array{id:int, name:string}>}
     */
    public function vorlagenAbgleich(Team $team, int $kopieId, ?string $ab = null): array
    {
        $kopie = FoodAlchemistSpeiseplan::visibleToTeam($team)->with(['lines', 'entries'])->findOrFail($kopieId);
        $leer = ['vorlage' => null, 'zellen' => [], 'neue_linien' => []];
        if ($kopie->source_plan_id === null) {
            return $leer;
        }
        $vorlage = FoodAlchemistSpeiseplan::visibleToTeam($team)->with('lines')->find($kopie->source_plan_id);
        if ($vorlage === null) {
            return $leer;
        }
        $abDatum = Carbon::parse($ab ?? 'today')->startOfDay();
        $seit = $kopie->source_synced_at;
        // Vorlage-Einträge inkl. gelöschter (eine gelöschte Zelle ist auch eine Änderung).
        $vEintraege = FoodAlchemistSpeiseplanEintrag::withTrashed()->where('menu_plan_id', $vorlage->id)
            ->whereDate('entry_date', '>=', $abDatum->format('Y-m-d'))->get();
        $linienMap = $kopie->lines->filter(fn ($l) => $l->source_line_id !== null)
            ->mapWithKeys(fn ($l) => [(int) $l->source_line_id => (int) $l->id]);
        $vLinienName = $vorlage->lines->pluck('name', 'id');

        $zellen = [];
        $sammeln = function ($eintraege, string $seite) use (&$zellen, $linienMap) {
            foreach ($eintraege as $e) {
                $vLinie = $seite === 'v' ? (int) $e->line_id : (int) ($linienMap->search((int) $e->line_id) ?: 0);
                $key = $e->entry_date->format('Y-m-d') . '|' . $e->meal . '|' . $vLinie;
                $zellen[$key][$seite][] = $e;
            }
        };
        $sammeln($vEintraege->whereNull('deleted_at'), 'v');
        $sammeln($kopie->entries->filter(fn ($e) => $e->entry_date !== null && $e->entry_date->gte($abDatum)), 'k');
        $geaendert = [];
        foreach ($vEintraege as $e) {
            $ts = $e->deleted_at ?? $e->updated_at ?? $e->created_at;
            if ($seit === null || ($ts !== null && $ts->gt($seit))) {
                $geaendert[$e->entry_date->format('Y-m-d') . '|' . $e->meal . '|' . (int) $e->line_id] = true;
            }
        }

        $out = [];
        ksort($zellen);
        foreach ($zellen as $key => $z) {
            $v = collect($z['v'] ?? [])->map(fn ($e) => $e->inhaltKey())->filter()->sort()->values()->all();
            $k = collect($z['k'] ?? [])->map(fn ($e) => $e->inhaltKey())->filter()->sort()->values()->all();
            if ($v === $k) {
                continue;
            }
            [$datum, $meal, $vLinie] = explode('|', $key);
            $out[] = [
                'key' => $key, 'datum' => $datum, 'mahlzeit' => $meal,
                'linie' => (int) $vLinie > 0 ? ($vLinienName[(int) $vLinie] ?? 'Linie #' . $vLinie) : 'Ohne Linie',
                'vorlage' => collect($z['v'] ?? [])->map(fn ($e) => $this->eintragName($e))->values()->all(),
                'betrieb' => collect($z['k'] ?? [])->map(fn ($e) => $this->eintragName($e))->values()->all(),
                'art' => isset($geaendert[$key]) ? 'vorlage_geaendert' : 'lokal_abweichend',
            ];
        }
        $neueLinien = $vorlage->lines->reject(fn ($l) => $linienMap->has((int) $l->id))
            ->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name])->values()->all();

        return ['vorlage' => ['id' => (int) $vorlage->id, 'name' => $vorlage->name], 'zellen' => $out, 'neue_linien' => $neueLinien];
    }

    /**
     * Änderungen aus der Vorlage übernehmen: neue Linien anlegen, dann die gewählten Zellen (oder
     * alle mit `vorlage_geaendert`) in der Kopie durch den Vorlage-Inhalt ersetzen. Pax des
     * Betriebs bleiben, wo es sie gab. Setzt den Abgleich-Zeitpunkt.
     *
     * @param  list<string>|null  $zellKeys  Keys aus {@see vorlagenAbgleich}; null = alle Vorlage-Änderungen
     * @return int Anzahl übernommener Zellen
     */
    public function ausVorlageUebernehmen(Team $team, int $kopieId, ?array $zellKeys = null): int
    {
        $kopie = FoodAlchemistSpeiseplan::visibleToTeam($team)->with('lines')->findOrFail($kopieId);
        $this->guard($kopie, $team);
        $abgleich = $this->vorlagenAbgleich($team, $kopieId);
        if ($abgleich['vorlage'] === null) {
            throw new \RuntimeException('Dieser Plan ist keine Betriebs-Kopie einer Vorlage.');
        }
        $vorlage = FoodAlchemistSpeiseplan::visibleToTeam($team)->with(['lines', 'entries'])->findOrFail($abgleich['vorlage']['id']);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($kopie, $vorlage, $abgleich, $zellKeys) {
            foreach ($abgleich['neue_linien'] as $nl) {
                $vl = $vorlage->lines->firstWhere('id', $nl['id']);
                FoodAlchemistSpeiseplanLinie::create(array_merge($vl->only(self::LINIEN_FELDER), [
                    'team_id' => $kopie->team_id, 'menu_plan_id' => $kopie->id, 'source_line_id' => $vl->id,
                    'sort_order' => (int) $kopie->lines()->max('sort_order') + 1,
                ]));
            }
            $linienMap = $kopie->lines()->whereNotNull('source_line_id')->pluck('id', 'source_line_id');
            $ziel = collect($abgleich['zellen'])->filter(fn ($z) => $zellKeys === null ? $z['art'] === 'vorlage_geaendert' : in_array($z['key'], $zellKeys, true));
            $n = 0;
            foreach ($ziel as $z) {
                $vLinie = (int) explode('|', $z['key'])[2];
                $kLinie = $vLinie > 0 ? ($linienMap[$vLinie] ?? null) : null;
                $alt = FoodAlchemistSpeiseplanEintrag::where('menu_plan_id', $kopie->id)->whereDate('entry_date', $z['datum'])
                    ->where('meal', $z['mahlzeit'])
                    ->when($kLinie !== null, fn ($q) => $q->where('line_id', $kLinie), fn ($q) => $q->whereNull('line_id'))->get();
                $paxBetrieb = $alt->max('pax');
                foreach ($alt as $a) {
                    $a->delete();
                }
                foreach ($vorlage->entries->filter(fn ($e) => $e->entry_date?->format('Y-m-d') === $z['datum'] && $e->meal === $z['mahlzeit'] && (int) $e->line_id === $vLinie) as $e) {
                    $kopie->entries()->create([
                        'team_id' => $kopie->team_id, 'entry_date' => $z['datum'], 'week' => 1, 'weekday' => (int) $e->weekday,
                        'meal' => $e->meal, 'line_id' => $kLinie, 'position' => $e->position,
                        'concept_id' => $e->concept_id, 'package_id' => $e->package_id, 'sales_recipe_id' => $e->sales_recipe_id,
                        'pax' => $paxBetrieb ?? $e->pax,
                    ]);
                }
                $n++;
            }
            $kopie->update(['source_synced_at' => now()]);

            return $n;
        });
    }

    // ── Spec 57 · Paket 8: Plan/Ist (nur lesend, vorhandene Verkaufsdaten) ──────

    /**
     * Plan gegen Ist einer Woche und Mahlzeit, je Gericht: geplante Essen und Plan-Umsatz gegen
     * verkaufte Menge und Umsatz aus dem Verkaufsjournal (`foodalchemist_sales_facts`, strikt das
     * eigene Team). Concepts und Pakete haben kein Gericht in der Kasse → „nicht vergleichbar“.
     * Das Journal kennt keinen Betrieb (nur `source_scope_label`) — es zählt jede Verkaufsstelle
     * des Teams; das steht als Hinweis im Ergebnis.
     *
     * @return array{zeilen: list<array>, summe: array, hat_ist: bool, nicht_vergleichbar: list<string>, hinweis: string}
     */
    public function planIst(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $mahlzeit = array_key_exists($mahlzeit, self::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $mo = $montag->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $so = $mo->copy()->addDays(6);
        $proGericht = [];
        $nichtVergleichbar = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->between($mo, $so)) {
                continue;
            }
            if ($e->sales_recipe_id === null) {
                $nichtVergleichbar[] = $e->inhaltName();

                continue;
            }
            $gid = (int) $e->sales_recipe_id;
            $pax = $this->effektivePax($e, $plan);
            $proGericht[$gid]['name'] ??= $this->eintragName($e);
            $proGericht[$gid]['plan'] = ($proGericht[$gid]['plan'] ?? 0) + $pax;
            $proGericht[$gid]['plan_umsatz'] = ($proGericht[$gid]['plan_umsatz'] ?? 0) + $this->eintragPreis($e, $outlet)['vk'] * $pax;
        }

        $ist = $proGericht === [] ? collect() : \Platform\FoodAlchemist\Models\FoodAlchemistSalesFact::where('team_id', $team->id)
            ->whereIn('recipe_id', array_keys($proGericht))
            ->whereBetween('sold_at', [$mo->format('Y-m-d'), $so->format('Y-m-d')])
            ->selectRaw('recipe_id, SUM(qty_sold) as menge, SUM(revenue_net) as umsatz')
            ->groupBy('recipe_id')->get()->keyBy('recipe_id');

        $zeilen = [];
        $summe = ['plan' => 0, 'ist' => 0.0, 'plan_umsatz' => 0.0, 'ist_umsatz' => 0.0];
        foreach ($proGericht as $gid => $g) {
            $i = $ist->get($gid);
            $menge = $i !== null ? (float) $i->menge : null;
            $umsatz = $i !== null ? (float) $i->umsatz : null;
            $zeilen[] = [
                'recipe_id' => $gid, 'name' => $g['name'], 'plan' => (int) $g['plan'],
                'ist' => $menge, 'abweichung_pct' => $menge !== null && $g['plan'] > 0 ? round(($menge - $g['plan']) / $g['plan'] * 100, 1) : null,
                'plan_umsatz' => round($g['plan_umsatz'], 2), 'ist_umsatz' => $umsatz !== null ? round($umsatz, 2) : null,
            ];
            $summe['plan'] += (int) $g['plan'];
            $summe['plan_umsatz'] += $g['plan_umsatz'];
            if ($menge !== null) {
                $summe['ist'] += $menge;
                $summe['ist_umsatz'] += (float) $umsatz;
            }
        }
        usort($zeilen, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $hatIst = $ist->isNotEmpty();
        $summe['plan_umsatz'] = round($summe['plan_umsatz'], 2);
        $summe['ist_umsatz'] = round($summe['ist_umsatz'], 2);
        $summe['abweichung_pct'] = $hatIst && $summe['plan'] > 0 ? round(($summe['ist'] - $summe['plan']) / $summe['plan'] * 100, 1) : null;

        return [
            'zeilen' => $zeilen, 'summe' => $summe, 'hat_ist' => $hatIst,
            'nicht_vergleichbar' => array_values(array_unique($nichtVergleichbar)),
            'hinweis' => 'Ist aus dem Verkaufsjournal des Teams (alle Verkaufsstellen, Zeitraum Mo–So). Gerichte ohne Zuordnung im Journal zählen nicht.',
        ];
    }

    public function crmVerfuegbar(): bool
    {
        return class_exists(\Platform\Crm\Services\CompanyLinkService::class);
    }

    public function sucheFirmen(string $suche, int $limit = 10): Collection
    {
        $suche = trim($suche);
        if ($suche === '' || ! $this->crmVerfuegbar()) {
            return collect();
        }

        return app(\Platform\Crm\Services\CompanyLinkService::class)->searchCompanies($suche, $limit);
    }

    public function sucheKontakte(string $suche, int $limit = 10): Collection
    {
        $suche = trim($suche);
        if ($suche === '' || ! class_exists(\Platform\Crm\Services\ContactLinkService::class)) {
            return collect();
        }

        return app(\Platform\Crm\Services\ContactLinkService::class)->searchContacts($suche, $limit);
    }

    public function delete(Team $team, int $id): void
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($id);
        $this->guard($plan, $team);
        $plan->delete();
    }

    // ── Menü-Linien (pro Speiseplan frei) ────────────────────────────────

    public function addLinie(Team $team, int $planId, array $in): FoodAlchemistSpeiseplanLinie
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);
        $felder = $this->linienFelder($in);

        return $plan->lines()->create(array_merge($felder, [
            'team_id' => $plan->team_id,
            'name' => ($felder['name'] ?? '') !== '' ? $felder['name'] : 'Neue Linie',
            'color' => $in['color'] ?? null,
            'is_vegetarian' => (bool) ($in['is_vegetarian'] ?? false),
            'sort_order' => (int) $plan->lines()->max('sort_order') + 1,
        ]));
    }

    public function updateLinie(Team $team, int $linieId, array $in): FoodAlchemistSpeiseplanLinie
    {
        $linie = FoodAlchemistSpeiseplanLinie::visibleToTeam($team)->with('mealPlan')->findOrFail($linieId);
        $this->guard($linie->mealPlan, $team);
        $upd = $this->linienFelder($in);
        if (array_key_exists('name', $upd) && $upd['name'] === '') {
            $upd['name'] = $linie->name;
        }
        $linie->update($upd);

        return $linie->refresh();
    }

    public function removeLinie(Team $team, int $linieId): void
    {
        $linie = FoodAlchemistSpeiseplanLinie::visibleToTeam($team)->with('mealPlan')->findOrFail($linieId);
        $this->guard($linie->mealPlan, $team);
        // FK app-seitig: Einträge der Linie entkoppeln statt löschen
        FoodAlchemistSpeiseplanEintrag::where('line_id', $linie->id)->update(['line_id' => null]);
        $linie->delete();
    }

    /** Linie um eine Position verschieben ($richtung < 0 = hoch, sonst runter). */
    public function reorderLinie(Team $team, int $linieId, int $richtung): void
    {
        $linie = FoodAlchemistSpeiseplanLinie::visibleToTeam($team)->with('mealPlan')->findOrFail($linieId);
        $this->guard($linie->mealPlan, $team);
        $nachbar = FoodAlchemistSpeiseplanLinie::where('menu_plan_id', $linie->menu_plan_id)->whereNull('deleted_at')
            ->when($richtung < 0,
                fn ($q) => $q->where('sort_order', '<', $linie->sort_order)->orderByDesc('sort_order'),
                fn ($q) => $q->where('sort_order', '>', $linie->sort_order)->orderBy('sort_order'))
            ->first();
        if ($nachbar === null) {
            return;
        }
        [$a, $b] = [$linie->sort_order, $nachbar->sort_order];
        $linie->update(['sort_order' => $b]);
        $nachbar->update(['sort_order' => $a]);
    }

    // ── Einträge (echtes Datum × Linie × Mahlzeit) ───────────────────────

    /**
     * Spec 42: Zell-Picker-Kandidaten mit Facetten — spiegelt SpeisekarteService::gerichtKandidaten
     * (Filter-Vertrag Hauptgruppe/dish_class wie der Verkauf-Browser). Ohne Suche = Browse (kein
     * „erst tippen"). Varianten-Guard `variant_source_recipe_id` wie in der Speisekarte.
     */
    public function gerichtKandidaten(Team $team, string $suche, int $limit = 50, ?int $hauptgruppe = null, ?int $dishClassId = null): Collection
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id')
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->when($hauptgruppe !== null, fn ($q) => $q->where('dish_main_group_id', $hauptgruppe))
            ->when($dishClassId !== null, fn ($q) => $q->where('dish_class_id', $dishClassId))
            ->with(['dishClass:id,diet_form'])
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'sales_net', 'dish_class_id']);
    }

    /** Concepts (Fix-Menüs) für den Zell-Picker. */
    public function conceptKandidaten(Team $team, string $suche, int $limit = 50): Collection
    {
        // Kaskade: Ausgabe-Form → Konzepte UND Pakete buchbar (Paket = kind=paket-Concept).
        return FoodAlchemistConcept::visibleToTeam($team)->echte()
            ->where('status', 'active') // Picker zeigt nur aktive (keine Entwürfe/archivierten; Status berücksichtigt)
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'price_per_person_cache']);
    }

    /** Pakete für den Zell-Picker. */
    public function paketKandidaten(Team $team, string $suche, int $limit = 50): Collection
    {
        return FoodAlchemistPaket::visibleToTeam($team)
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->orderBy('name')->limit($limit)->get(['id', 'name']);
    }

    public function addEintrag(Team $team, int $planId, array $in): FoodAlchemistSpeiseplanEintrag
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);
        $inhalt = $this->pruefeInhalt($team, $in);
        $datum = Carbon::parse($in['entry_date'])->startOfDay();
        $mahlzeit = in_array($in['mahlzeit'] ?? '', array_keys(self::MAHLZEITEN), true) ? $in['mahlzeit'] : 'mittag';
        $linieId = $in['line_id'] ?? null;
        if ($linieId !== null && ! $plan->lines->contains('id', (int) $linieId)) {
            $linieId = null;
        }
        $tag = $datum->format('Y-m-d');

        return $plan->entries()->create(array_merge($inhalt, [
            'team_id' => $plan->team_id,
            'entry_date' => $tag,
            'week' => 1, 'weekday' => (int) $datum->isoWeekday(),   // Back-Compat-Spalten
            'meal' => $mahlzeit,
            'line_id' => $linieId,
            'position' => (int) $plan->entries()
                ->whereDate('entry_date', $tag)->where('meal', $mahlzeit)
                ->when($linieId !== null, fn ($q) => $q->where('line_id', $linieId))->max('position') + 1,
        ]));
    }

    /**
     * Spec 57 · 0.1 (D-PLAN-1): Inhalt eines Eintrags prüfen — GENAU EINER (Vorrang Concept >
     * Paket > Gericht, wie bisher dokumentiert) und für das Team SICHTBAR. Vorher prüfte das nur
     * das MCP-Tool; der Editor reichte die ID aus dem Browser ungeprüft durch — damit ließ sich
     * ein Gericht eines fremden Mandanten in den eigenen Plan hängen.
     *
     * @return array{concept_id:?int, package_id:?int, sales_recipe_id:?int}
     */
    private function pruefeInhalt(Team $team, array $in): array
    {
        $refs = [
            'concept_id' => FoodAlchemistConcept::class,
            'package_id' => FoodAlchemistPaket::class,
            'sales_recipe_id' => FoodAlchemistRecipe::class,
        ];
        foreach ($refs as $feld => $model) {
            $id = (int) ($in[$feld] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (! $model::visibleToTeam($team)->whereKey($id)->exists()) {
                throw new \RuntimeException((['concept_id' => 'Konzept', 'package_id' => 'Paket', 'sales_recipe_id' => 'Gericht'][$feld] ?? 'Inhalt') . ' #' . $id . ' ist nicht vorhanden oder nicht sichtbar.');
            }

            return array_merge(array_fill_keys(array_keys($refs), null), [$feld => $id]);
        }

        throw new \RuntimeException('Genau einen Inhalt angeben: Konzept, Paket oder Gericht.');
    }

    /**
     * Spec 57: effektive Essen/Portionen eines Eintrags — Eintrag-Override › Linien-Standard ›
     * Plan-Standard. Eine Stelle für Produktion, Kennzahlen, Mengen und Bedarf.
     */
    public function effektivePax(FoodAlchemistSpeiseplanEintrag $e, FoodAlchemistSpeiseplan $plan): int
    {
        if ((int) $e->pax > 0) {
            return (int) $e->pax;
        }
        $linie = $e->line_id !== null ? $plan->lines->firstWhere('id', (int) $e->line_id) : null;
        if ($linie !== null && (int) $linie->default_pax > 0) {
            return (int) $linie->default_pax;
        }

        return max(1, (int) ($plan->default_pax ?: 100));
    }

    /**
     * Spec 57 · Paket 9: die Öffnungstage einer Woche als Datumsliste (ab Montag).
     *
     * @return list<Carbon>
     */
    public function wochenTage(FoodAlchemistSpeiseplan $plan, Carbon $montag): array
    {
        $mo = $montag->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();

        return array_map(fn (int $iso) => $mo->copy()->addDays($iso - 1), $plan->oeffnungstage());
    }

    /**
     * Tage für die Wochen-Aggregate: explizite Anzahl (Alt-Signatur, ab Montag) oder — Standard —
     * die Öffnungstage des Plans.
     *
     * @return list<Carbon>
     */
    private function aggregatTage(FoodAlchemistSpeiseplan $plan, Carbon $montag, ?int $anzahl): array
    {
        if ($anzahl === null) {
            return $this->wochenTage($plan, $montag);
        }
        $mo = $montag->copy()->startOfDay();

        return array_map(fn (int $i) => $mo->copy()->addDays($i), range(0, max(1, $anzahl) - 1));
    }

    public function removeEintrag(Team $team, int $id): void
    {
        $e = FoodAlchemistSpeiseplanEintrag::visibleToTeam($team)->with('mealPlan')->findOrFail($id);
        $this->guard($e->mealPlan, $team);
        $e->delete();
    }

    /** Spec 31 / Stufe C: Pax-Override je Eintrag setzen (leer/0 → NULL = Plan-Default gilt). */
    public function setEintragPax(Team $team, int $id, $pax): void
    {
        $e = FoodAlchemistSpeiseplanEintrag::visibleToTeam($team)->with('mealPlan')->findOrFail($id);
        $this->guard($e->mealPlan, $team);
        $wert = (int) $pax;
        $e->update(['pax' => $wert > 0 ? $wert : null]);
    }

    // ── Spec 57 · Paket 5: Umbauen (verschieben, ersetzen, kopieren, Woche kopieren) ──

    /**
     * Eintrag in eine andere Zelle legen (Datum, Linie, optional Mahlzeit). Die Linie muss zum
     * Plan gehören, sonst landet er unter „Ohne Linie“ (wie beim Anlegen). Pax bleibt.
     */
    public function verschiebeEintrag(Team $team, int $id, string $datum, ?int $lineId, ?string $mahlzeit = null): FoodAlchemistSpeiseplanEintrag
    {
        $e = FoodAlchemistSpeiseplanEintrag::visibleToTeam($team)->with('mealPlan.lines')->findOrFail($id);
        $plan = $e->mealPlan;
        $this->guard($plan, $team);
        $ziel = Carbon::parse($datum)->startOfDay();
        $meal = $mahlzeit !== null && array_key_exists($mahlzeit, self::MAHLZEITEN) ? $mahlzeit : $e->meal;
        if ($lineId !== null && ! $plan->lines->contains('id', $lineId)) {
            $lineId = null;
        }
        $tag = $ziel->format('Y-m-d');
        $e->update([
            'entry_date' => $tag,
            'weekday' => (int) $ziel->isoWeekday(),   // Back-Compat-Spalte mitpflegen
            'meal' => $meal,
            'line_id' => $lineId,
            'position' => (int) $plan->entries()->whereKeyNot($e->id)
                ->whereDate('entry_date', $tag)->where('meal', $meal)
                ->when($lineId !== null, fn ($q) => $q->where('line_id', $lineId), fn ($q) => $q->whereNull('line_id'))
                ->max('position') + 1,
        ]);

        return $e->refresh();
    }

    /** Inhalt eines Eintrags tauschen (Zelle und Pax bleiben). Gleiche Prüfung wie beim Anlegen. */
    public function ersetzeEintrag(Team $team, int $id, array $inhalt): FoodAlchemistSpeiseplanEintrag
    {
        $e = FoodAlchemistSpeiseplanEintrag::visibleToTeam($team)->with('mealPlan')->findOrFail($id);
        $this->guard($e->mealPlan, $team);
        $e->update($this->pruefeInhalt($team, $inhalt));

        return $e->refresh();
    }

    /**
     * Eintrag auf weitere Tage kopieren (gleiche Linie, Mahlzeit, Inhalt, Pax). Steht derselbe
     * Inhalt dort schon, wird nicht doppelt angelegt.
     *
     * @param  list<string>  $daten
     * @return int Anzahl neuer Einträge
     */
    public function kopiereEintrag(Team $team, int $id, array $daten): int
    {
        $e = FoodAlchemistSpeiseplanEintrag::visibleToTeam($team)->with('mealPlan')->findOrFail($id);
        $plan = $e->mealPlan;
        $this->guard($plan, $team);
        $neu = 0;
        foreach (array_unique(array_map('strval', $daten)) as $d) {
            $ziel = Carbon::parse($d)->startOfDay();
            $tag = $ziel->format('Y-m-d');
            if ($tag === $e->entry_date?->format('Y-m-d')) {
                continue;
            }
            // whereDate: unabhängig davon, ob die DB das Datum mit Uhrzeit ablegt (SQLite) oder als DATE (MySQL).
            $gleich = $plan->entries()->whereDate('entry_date', $tag)->where('meal', $e->meal)
                ->when($e->line_id !== null, fn ($q) => $q->where('line_id', $e->line_id), fn ($q) => $q->whereNull('line_id'))
                ->where('concept_id', $e->concept_id)->where('package_id', $e->package_id)->where('sales_recipe_id', $e->sales_recipe_id)
                ->exists();
            if ($gleich) {
                continue;
            }
            $plan->entries()->create([
                'team_id' => $plan->team_id, 'entry_date' => $tag,
                'week' => 1, 'weekday' => (int) $ziel->isoWeekday(), 'meal' => $e->meal, 'line_id' => $e->line_id,
                'concept_id' => $e->concept_id, 'package_id' => $e->package_id, 'sales_recipe_id' => $e->sales_recipe_id,
                'pax' => $e->pax,
                'position' => (int) $plan->entries()->whereDate('entry_date', $tag)->where('meal', $e->meal)->max('position') + 1,
            ]);
            $neu++;
        }

        return $neu;
    }

    /**
     * Eine Woche (Montag bis Sonntag) auf eine andere Woche kopieren (Spec 57 · E5). Ohne
     * `zusammenfuehren` wird jede Zielzelle, die Inhalt bekommt, vorher geleert; mit
     * `zusammenfuehren` kommen die Einträge dazu (gleicher Inhalt in derselben Zelle nicht doppelt).
     * Optional nur eine Mahlzeit. Pax wandert mit, wenn `mitPax`.
     *
     * @return array{kopiert:int, ersetzt:int}
     */
    public function kopiereWoche(Team $team, int $planId, string $vonMontag, string $nachMontag, bool $zusammenfuehren = false, bool $mitPax = true, ?string $mahlzeit = null): array
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);
        $von = Carbon::parse($vonMontag)->startOfWeek(Carbon::MONDAY)->startOfDay();
        $nach = Carbon::parse($nachMontag)->startOfWeek(Carbon::MONDAY)->startOfDay();
        if ($von->equalTo($nach)) {
            throw new \RuntimeException('Quell- und Zielwoche sind gleich.');
        }
        $offset = (int) $von->diffInDays($nach, false);
        $mahlzeit = $mahlzeit !== null && array_key_exists($mahlzeit, self::MAHLZEITEN) ? $mahlzeit : null;
        $inWoche = fn ($e, Carbon $mo) => $e->entry_date !== null && $e->entry_date->between($mo, $mo->copy()->addDays(6))
            && ($mahlzeit === null || $e->meal === $mahlzeit);

        $quelle = $plan->entries->filter(fn ($e) => $inWoche($e, $von))->values();
        $zielBestand = [];
        foreach ($plan->entries->filter(fn ($e) => $inWoche($e, $nach)) as $e) {
            $zielBestand[$e->entry_date->format('Y-m-d') . '|' . $e->meal . '|' . (int) $e->line_id][] = $e;
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($plan, $quelle, $zielBestand, $offset, $zusammenfuehren, $mitPax) {
            $kopiert = 0;
            $ersetzt = 0;
            $geleert = [];
            $gesetzt = [];
            foreach ($quelle as $e) {
                $ziel = $e->entry_date->copy()->addDays($offset);
                $zelle = $ziel->format('Y-m-d') . '|' . $e->meal . '|' . (int) $e->line_id;
                if (! $zusammenfuehren && isset($zielBestand[$zelle]) && ! isset($geleert[$zelle])) {
                    foreach ($zielBestand[$zelle] as $alt) {
                        $alt->delete();
                        $ersetzt++;
                    }
                    $geleert[$zelle] = true;
                    unset($zielBestand[$zelle]);
                }
                $sig = $zelle . '|' . $e->inhaltKey();
                $schonDa = isset($gesetzt[$sig]) || collect($zielBestand[$zelle] ?? [])->contains(fn ($x) => $x->inhaltKey() === $e->inhaltKey());
                if ($schonDa) {
                    continue;
                }
                $plan->entries()->create([
                    'team_id' => $plan->team_id, 'entry_date' => $ziel->format('Y-m-d'),
                    'week' => 1, 'weekday' => (int) $ziel->isoWeekday(), 'meal' => $e->meal, 'line_id' => $e->line_id,
                    'concept_id' => $e->concept_id, 'package_id' => $e->package_id, 'sales_recipe_id' => $e->sales_recipe_id,
                    'pax' => $mitPax ? $e->pax : null, 'position' => $e->position,
                ]);
                $gesetzt[$sig] = true;
                $kopiert++;
            }

            return ['kopiert' => $kopiert, 'ersetzt' => $ersetzt];
        });
    }

    /**
     * Einträge eines Plans lesbar machen (für MCP und Agenten): je Eintrag Zelle, Inhalt, Pax.
     * Optional auf einen Zeitraum und eine Mahlzeit begrenzt.
     *
     * @return list<array<string, mixed>>
     */
    public function eintragsListe(FoodAlchemistSpeiseplan $plan, ?string $von = null, ?string $bis = null, ?string $mahlzeit = null): array
    {
        $vonC = $von !== null ? Carbon::parse($von)->startOfDay() : null;
        $bisC = $bis !== null ? Carbon::parse($bis)->endOfDay() : null;

        return $plan->entries
            ->filter(fn ($e) => $e->entry_date !== null
                && ($vonC === null || $e->entry_date->gte($vonC))
                && ($bisC === null || $e->entry_date->lte($bisC))
                && ($mahlzeit === null || $e->meal === $mahlzeit))
            ->map(fn ($e) => [
                'id' => (int) $e->id, 'entry_date' => $e->entry_date->format('Y-m-d'), 'mahlzeit' => $e->meal,
                'line_id' => $e->line_id !== null ? (int) $e->line_id : null,
                'concept_id' => $e->concept_id !== null ? (int) $e->concept_id : null,
                'package_id' => $e->package_id !== null ? (int) $e->package_id : null,
                'sales_recipe_id' => $e->sales_recipe_id !== null ? (int) $e->sales_recipe_id : null,
                'name' => $this->eintragName($e),
                'pax' => $e->pax !== null ? (int) $e->pax : null,
                'pax_effektiv' => $this->effektivePax($e, $plan),
            ])->values()->all();
    }

    // ── Spec 57 · Paket 3: Mengen (Essen je Linie × Tag) ─────────────────────

    /**
     * Mengen-Matrix einer Woche und Mahlzeit: je Linie × Öffnungstag die Essen der Zelle
     * (effektive Pax je Eintrag; mehrere Einträge einer Zelle = größte Zahl, weil sie dieselben
     * Gäste bedienen), dazu Vorwoche und Ø der letzten vier Wochen aus den PLANWERTEN, Summe,
     * Anteil, Wareneinsatz, Ø VK netto und Umsatz der Linie.
     *
     * @return array{tage: list<string>, zeilen: list<array>, summe: array}
     */
    public function mengenMatrix(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $mo = $montag->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $tage = array_map(fn (Carbon $t) => $t->format('Y-m-d'), $this->wochenTage($plan, $mo));
        $linien = $plan->lines->filter(fn ($l) => $l->giltFuerMahlzeit($mahlzeit))->values();

        // Einmal indizieren (Linie|Tag) statt je Zelle alle Einträge zu durchsuchen — ein
        // ausgerollter Jahresplan hat Tausende Einträge.
        $index = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date !== null && $e->meal === $mahlzeit) {
                $index[(int) $e->line_id . '|' . $e->entry_date->format('Y-m-d')][] = $e;
            }
        }
        $zellPax = function (int $lineId, string $ymd) use ($plan, $index): ?int {
            $liste = $index[$lineId . '|' . $ymd] ?? [];

            return $liste === [] ? null : (int) max(array_map(fn ($e) => $this->effektivePax($e, $plan), $liste));
        };
        $wochenSumme = function (int $lineId, Carbon $woMo) use ($zellPax, $plan): int {
            $s = 0;
            foreach ($this->wochenTage($plan, $woMo) as $t) {
                $s += (int) ($zellPax($lineId, $t->format('Y-m-d')) ?? 0);
            }

            return $s;
        };

        $zeilen = [];
        $gesamt = ['summe' => 0, 'umsatz' => 0.0, 'ek' => 0.0, 'vorwoche' => 0, 'schnitt4' => 0.0, 'je_tag' => array_fill_keys($tage, 0)];
        foreach ($linien as $l) {
            $zellen = [];
            $summe = 0;
            $umsatz = 0.0;
            $ek = 0.0;
            foreach ($tage as $ymd) {
                $p = $zellPax((int) $l->id, $ymd);
                $zellen[$ymd] = $p;
                $summe += (int) $p;
                $gesamt['je_tag'][$ymd] += (int) $p;
                foreach ($index[(int) $l->id . '|' . $ymd] ?? [] as $e) {
                    $preis = $this->eintragPreis($e, $outlet);
                    $pax = $this->effektivePax($e, $plan);
                    $umsatz += $preis['vk'] * $pax;
                    $ek += $preis['ek'] * $pax;
                }
            }
            $vorwoche = $wochenSumme((int) $l->id, $mo->copy()->subWeek());
            $vier = [];
            for ($w = 1; $w <= 4; $w++) {
                $vier[] = $wochenSumme((int) $l->id, $mo->copy()->subWeeks($w));
            }
            $schnitt4 = round(array_sum($vier) / 4, 1);
            $zeilen[] = [
                'line_id' => (int) $l->id, 'name' => $l->name, 'color' => $l->color, 'role' => $l->role,
                'zellen' => $zellen, 'summe' => $summe, 'vorwoche' => $vorwoche, 'schnitt4' => $schnitt4,
                'umsatz' => round($umsatz, 2),
                'wes' => $umsatz > 0 ? round($ek / $umsatz * 100, 1) : null,
                'vk_schnitt' => $summe > 0 ? round($umsatz / max(1, $summe), 2) : null,
            ];
            $gesamt['summe'] += $summe;
            $gesamt['umsatz'] += $umsatz;
            $gesamt['ek'] += $ek;
            $gesamt['vorwoche'] += $vorwoche;
            $gesamt['schnitt4'] += $schnitt4;
        }
        foreach ($zeilen as $i => $z) {
            $zeilen[$i]['anteil'] = $gesamt['summe'] > 0 ? round($z['summe'] / $gesamt['summe'] * 100, 1) : null;
        }
        $gesamt['wes'] = $gesamt['umsatz'] > 0 ? round($gesamt['ek'] / $gesamt['umsatz'] * 100, 1) : null;
        $gesamt['umsatz'] = round($gesamt['umsatz'], 2);
        $gesamt['ek'] = round($gesamt['ek'], 2);

        return ['tage' => $tage, 'zeilen' => $zeilen, 'summe' => $gesamt];
    }

    /**
     * Essen einer Zelle (Linie × Tag × Mahlzeit) setzen — schreibt den Pax-Override aller
     * Einträge der Zelle. Leer/0 = zurück auf den Standard. Leere Zelle: nichts zu setzen.
     *
     * @return int Anzahl geänderter Einträge
     */
    public function setzeZellenPax(Team $team, int $planId, int $lineId, string $datum, string $mahlzeit, $pax): int
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);
        $wert = (int) $pax;
        // Je Model speichern (nicht per Massen-Update), damit das Activity-Log die Änderung sieht.
        $eintraege = FoodAlchemistSpeiseplanEintrag::where('menu_plan_id', $plan->id)
            ->whereDate('entry_date', Carbon::parse($datum)->format('Y-m-d'))
            ->where('meal', $mahlzeit)->where('line_id', $lineId)
            ->get();
        foreach ($eintraege as $e) {
            $e->update(['pax' => $wert > 0 ? $wert : null]);
        }

        return $eintraege->count();
    }

    /**
     * Mengen der Vorwoche übernehmen: jede belegte Zelle dieser Woche bekommt die Essen derselben
     * Linie am selben Wochentag der Vorwoche (wenn es dort Werte gab).
     *
     * @return int Anzahl geänderter Einträge
     */
    public function uebernehmeVorwoche(Team $team, int $planId, string $mahlzeit, Carbon $montag): int
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->with(['lines', 'entries'])->findOrFail($planId);
        $this->guard($plan, $team);
        $vorher = $this->mengenMatrix($team, $plan, $mahlzeit, $montag->copy()->subWeek());
        $n = 0;
        foreach ($vorher['zeilen'] as $z) {
            foreach ($z['zellen'] as $ymd => $pax) {
                if ($pax === null) {
                    continue;
                }
                $n += $this->setzeZellenPax($team, $planId, $z['line_id'], Carbon::parse($ymd)->addWeek()->format('Y-m-d'), $mahlzeit, $pax);
            }
        }

        return $n;
    }

    /**
     * Alle Essen der Woche (eine Mahlzeit) mit einem Faktor skalieren und als Override setzen.
     *
     * @return int Anzahl geänderter Einträge
     */
    public function skaliereWoche(Team $team, int $planId, string $mahlzeit, Carbon $montag, float $faktor): int
    {
        if ($faktor <= 0 || $faktor > 10) {
            throw new \RuntimeException('Skalierungsfaktor muss zwischen 0 und 10 liegen.');
        }
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->with(['lines', 'entries'])->findOrFail($planId);
        $this->guard($plan, $team);
        $mo = $montag->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $n = 0;
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->between($mo, $mo->copy()->addDays(6))) {
                continue;
            }
            $e->update(['pax' => max(1, (int) round($this->effektivePax($e, $plan) * $faktor))]);
            $n++;
        }

        return $n;
    }

    // ── Wochen-Matrix + Monats-Kalender ──────────────────────────────────

    /**
     * Wochen-Matrix einer Mahlzeit: [line_id][Y-m-d] => list<Eintrag> (Mo..So ab $montag).
     * Einträge ohne Linie laufen unter Key 0 (»Ohne Linie«).
     *
     * @return array<int, array<string, list<FoodAlchemistSpeiseplanEintrag>>>
     */
    public function wochenRaster(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag): array
    {
        $start = $montag->copy()->startOfDay();
        $ende = $start->copy()->addDays(6);
        $grid = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->between($start, $ende)) {
                continue;
            }
            $grid[(int) $e->line_id][$e->entry_date->format('Y-m-d')][] = $e;
        }

        return $grid;
    }

    /**
     * Monats-Belegung: [Y-m-d] => {count, vk} (optional auf eine Mahlzeit gefiltert).
     *
     * @return array<string, array{count:int, vk:float}>
     */
    public function monatsRaster(FoodAlchemistSpeiseplan $plan, int $jahr, int $monat, ?string $mahlzeit = null, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $out = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || (int) $e->entry_date->year !== $jahr || (int) $e->entry_date->month !== $monat) {
                continue;
            }
            if ($mahlzeit !== null && $e->meal !== $mahlzeit) {
                continue;
            }
            $key = $e->entry_date->format('Y-m-d');
            $p = $this->eintragPreis($e, $outlet);
            $out[$key]['count'] = ($out[$key]['count'] ?? 0) + 1;
            $out[$key]['vk'] = round(($out[$key]['vk'] ?? 0) + $p['vk'], 2);
        }

        return $out;
    }

    /** Per-Person-Preis eines Eintrags (Concept/Paket/Gericht). @return array{vk: float, ek: float} */
    public function eintragPreis(FoodAlchemistSpeiseplanEintrag $e, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $preis = $this->inhaltPreis($e, $outlet);
        // Spec 57 · E1: eine Linie mit `price_mode=manuell` setzt den Ausgabepreis (Anzeige und
        // Kennzahlen). Der EK bleibt der des Inhalts; das Gericht behält seinen eigenen VK.
        $manuell = $e->line_id !== null ? $e->line?->manuellerPreis() : null;
        if ($manuell !== null) {
            $preis['vk'] = $manuell;
        }

        return $preis;
    }

    /** VK/EK je Person aus dem Inhalt selbst (Concept/Paket/Gericht), ohne Linienpreis. @return array{vk: float, ek: float} */
    private function inhaltPreis(FoodAlchemistSpeiseplanEintrag $e, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        if ($e->concept_id !== null && $e->concept) {
            $c = $this->concepts->preisCockpit($e->concept, $outlet);

            return ['vk' => (float) $c['price_per_person'], 'ek' => (float) $c['ek_per_person']];
        }
        if ($e->package_id !== null && $e->package) {
            return ['vk' => (float) ($e->package->price_per_person ?? 0), 'ek' => (float) ($e->package->ek_per_person ?? 0)];
        }
        if ($e->sales_recipe_id !== null && $e->dish) {
            $vk = $outlet !== null
                ? (app(DarreichungResolver::class)->vkNettoMitQuelle($e->dish, $outlet)['vk'] ?? (float) ($e->dish->sales_net ?? 0))
                : (float) ($e->dish->sales_net ?? 0);

            return ['vk' => $vk, 'ek' => (float) ($e->dish->ek_total_eur ?? 0)];
        }

        return ['vk' => 0.0, 'ek' => 0.0];
    }

    /**
     * Kosten/Person der sichtbaren Woche+Mahlzeit: je Tag und Wochensumme.
     *
     * @return array{pro_tag: array<string,array{vk:float,ek:float}>, woche: array{vk:float,ek:float}}
     */
    public function wochenKosten(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $start = $montag->copy()->startOfDay();
        $ende = $start->copy()->addDays(6);
        $proTag = [];
        $wVk = 0.0;
        $wEk = 0.0;
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->between($start, $ende)) {
                continue;
            }
            $p = $this->eintragPreis($e, $outlet);
            $k = $e->entry_date->format('Y-m-d');
            $proTag[$k]['vk'] = round(($proTag[$k]['vk'] ?? 0) + $p['vk'], 2);
            $proTag[$k]['ek'] = round(($proTag[$k]['ek'] ?? 0) + $p['ek'], 2);
            $wVk += $p['vk'];
            $wEk += $p['ek'];
        }

        return ['pro_tag' => $proTag, 'woche' => ['vk' => round($wVk, 2), 'ek' => round($wEk, 2)]];
    }

    // ── Spec 57 · Paket 1+2: Zelle „auf einen Blick“ + Zielband je Linie ────────

    /**
     * Zielband Wareneinsatz einer Linie in %. Ohne gepflegtes Band gilt das Team-/Betriebs-Ziel
     * als Obergrenze (keine Untergrenze) — dieselbe Leiter wie {@see MargeService::weAmpel}.
     *
     * @return array{min:?float, max:float, quelle:string}
     */
    public function zielband(?FoodAlchemistSpeiseplanLinie $linie, float $teamZiel): array
    {
        $min = $linie?->target_wes_min_pct;
        $max = $linie?->target_wes_max_pct;
        if ($min === null && $max === null) {
            return ['min' => null, 'max' => $teamZiel, 'quelle' => 'team'];
        }

        return ['min' => $min, 'max' => $max ?? $teamZiel, 'quelle' => 'linie'];
    }

    /**
     * Ampel für einen Wareneinsatz gegen ein Band: `unter` (unter der Untergrenze, nur Hinweis) ·
     * `ok` · `ueber` (bis 1,5 × Obergrenze) · `weit_ueber` · `unbekannt` (kein VK — nie geraten).
     */
    public function wesStatus(?float $wes, array $band): string
    {
        if ($wes === null) {
            return 'unbekannt';
        }
        if ($band['min'] !== null && $wes < (float) $band['min']) {
            return 'unter';
        }
        if ($wes <= (float) $band['max']) {
            return 'ok';
        }

        return $wes > (float) $band['max'] * 1.5 ? 'weit_ueber' : 'ueber';
    }

    /**
     * Kennzahlen der sichtbaren Woche für die Matrix: je Eintrag (Titel/Wording, Diät, LMIV-Codes,
     * VK, EK, Wareneinsatz gegen das Zielband der Linie, Pax, kcal) und je Öffnungstag (Gäste =
     * Essen auf Hauptgang-Linien, Umsatz, Wareneinsatz, EK je Gast), dazu je Linie die Wochen-Quote.
     * Rechnet nichts neu: Preis über {@see eintragPreis}, Pax über {@see effektivePax}, Diät und
     * Kennzeichnung über den {@see ConcepterAggregateService}.
     *
     * @return array{eintraege: array<int, array>, tage: array<string, array>, linien: array<int, array>, team_ziel: float, gaeste_aus_rollen: bool}
     */
    public function zellenKennzahlen(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null, bool $mitKomponenten = false): array
    {
        $agg = app(ConcepterAggregateService::class);
        $teamZiel = app(TeamSettingsService::class)->zielWareneinsatzPct($team, $outlet);
        $linienById = $plan->lines->keyBy(fn ($l) => (int) $l->id);
        $hatRollen = $plan->lines->contains(fn ($l) => $l->istHauptgang());

        $tage = [];
        foreach ($this->wochenTage($plan, $montag) as $tag) {
            $tage[$tag->format('Y-m-d')] = ['gaeste' => 0, 'portionen' => 0, 'umsatz' => 0.0, 'ek' => 0.0];
        }
        $linienWoche = [];
        $eintraege = [];

        foreach ($this->wochenRaster($plan, $mahlzeit, $montag) as $lineId => $proTag) {
            $linie = $linienById->get((int) $lineId);
            $band = $this->zielband($linie, $teamZiel);
            foreach ($proTag as $ymd => $liste) {
                foreach ($liste as $e) {
                    $preis = $this->eintragPreis($e, $outlet);
                    $pax = $this->effektivePax($e, $plan);
                    $wes = $preis['vk'] > 0 ? round($preis['ek'] / $preis['vk'] * 100, 1) : null;
                    $gerichte = $this->eintragGerichte($e);
                    [$titel, $untertitel] = $this->titelUndWording($e, $gerichte);
                    $naehr = $gerichte->isNotEmpty()
                        ? $agg->naehrwertAggregat($gerichte->map(fn ($g) => ['gericht' => $g, 'quantity' => 1, 'unit' => null]))
                        : null;

                    $eintraege[(int) $e->id] = [
                        'id' => (int) $e->id,
                        'typ' => $e->concept_id !== null ? 'concept' : ($e->package_id !== null ? 'paket' : 'gericht'),
                        'titel' => $titel,
                        'untertitel' => $untertitel,
                        'diaet' => $this->diaetMerkmale($agg->allergenRollupFromGerichte($gerichte), $agg->kennzeichnungFromGerichte($gerichte)),
                        'codes' => $this->eintragCodes($e),
                        'vk' => round($preis['vk'], 2),
                        'ek' => round($preis['ek'], 2),
                        'linienpreis' => $linie?->manuellerPreis() !== null,
                        'wes' => $wes,
                        'band' => $band,
                        'status' => $this->wesStatus($wes, $band),
                        'pax' => $pax,
                        'pax_override' => (int) $e->pax > 0,
                        'kcal' => $naehr['kcal'] ?? null,
                        'portion_g' => $gerichte->sum(fn ($g) => (float) ($g->sales_quantity_per_unit_g ?? 0)) ?: null,
                        'komponenten' => $mitKomponenten ? $this->komponenten($e, $gerichte) : [],
                    ];

                    if (isset($tage[$ymd])) {
                        $tage[$ymd]['umsatz'] += $preis['vk'] * $pax;
                        $tage[$ymd]['ek'] += $preis['ek'] * $pax;
                        $tage[$ymd]['portionen'] += $pax;
                        if ($linie?->istHauptgang()) {
                            $tage[$ymd]['gaeste'] += $pax;
                        }
                        $linienWoche[(int) $lineId]['umsatz'] = ($linienWoche[(int) $lineId]['umsatz'] ?? 0) + $preis['vk'] * $pax;
                        $linienWoche[(int) $lineId]['ek'] = ($linienWoche[(int) $lineId]['ek'] ?? 0) + $preis['ek'] * $pax;
                    }
                }
            }
        }

        foreach ($tage as $ymd => $t) {
            $tage[$ymd]['umsatz'] = round($t['umsatz'], 2);
            $tage[$ymd]['ek'] = round($t['ek'], 2);
            $tage[$ymd]['wes'] = $t['umsatz'] > 0 ? round($t['ek'] / $t['umsatz'] * 100, 1) : null;
            $tage[$ymd]['status'] = $this->wesStatus($tage[$ymd]['wes'], ['min' => null, 'max' => $teamZiel]);
            $tage[$ymd]['ek_je_gast'] = $t['gaeste'] > 0 ? round($t['ek'] / $t['gaeste'], 2) : null;
        }

        $linien = [];
        foreach ($plan->lines as $l) {
            $w = $linienWoche[(int) $l->id] ?? ['umsatz' => 0.0, 'ek' => 0.0];
            $band = $this->zielband($l, $teamZiel);
            $wes = $w['umsatz'] > 0 ? round($w['ek'] / $w['umsatz'] * 100, 1) : null;
            $linien[(int) $l->id] = ['name' => $l->name, 'color' => $l->color, 'band' => $band, 'wes' => $wes, 'status' => $this->wesStatus($wes, $band)];
        }

        $wUmsatz = array_sum(array_column($tage, 'umsatz'));
        $wEk = array_sum(array_column($tage, 'ek'));
        $wWes = $wUmsatz > 0 ? round($wEk / $wUmsatz * 100, 1) : null;
        $woche = [
            'umsatz' => round($wUmsatz, 2), 'ek' => round($wEk, 2), 'wes' => $wWes,
            'status' => $this->wesStatus($wWes, ['min' => null, 'max' => $teamZiel]),
            'portionen' => (int) array_sum(array_column($tage, 'portionen')),
            'gaeste' => (int) array_sum(array_column($tage, 'gaeste')),
        ];

        return ['eintraege' => $eintraege, 'tage' => $tage, 'linien' => $linien, 'woche' => $woche, 'team_ziel' => $teamZiel, 'gaeste_aus_rollen' => $hatRollen];
    }

    /**
     * Wareneinsatz-Budget-Ampel (Spec 57 · E2): Ø EK je GAST und Tag gegen das Budget (€/Person).
     * Gäste = Essen auf Hauptgang-Linien. Hat kein Plan-Tag Hauptgang-Gäste (Linien ohne Rolle),
     * gilt das Altmodell „Summe je Person über alle Linien“ — ausgewiesen in `basis`.
     *
     * @return ?array{avg:float, budget:float, ueber_tage:int, ampel:string, basis:string}
     */
    public function budgetAmpel(FoodAlchemistSpeiseplan $plan, array $kennzahlen, array $kosten): ?array
    {
        if (! $plan->budget_wareneinsatz) {
            return null;
        }
        $budget = (float) $plan->budget_wareneinsatz;
        $jeGast = collect($kennzahlen['tage'] ?? [])->pluck('ek_je_gast')->filter(fn ($v) => $v !== null);
        if ($jeGast->isNotEmpty()) {
            $werte = $jeGast;
            $basis = 'je_gast';
        } else {
            $werte = collect($kosten['pro_tag'] ?? [])->pluck('ek');
            $basis = 'summe_linien';
        }
        if ($werte->isEmpty()) {
            return null;
        }
        $avg = round((float) $werte->avg(), 2);
        $ueber = $werte->filter(fn ($v) => (float) $v > $budget)->count();

        return [
            'avg' => $avg, 'budget' => $budget, 'ueber_tage' => $ueber, 'basis' => $basis,
            'ampel' => $avg > $budget ? 'danger' : ($ueber > 0 ? 'warning' : 'success'),
        ];
    }

    /**
     * Titel + Wording für die Zelle. Gericht: Wording-Kette ({@see eintragName}); der erste
     * Pipe-Teil wird Titel, der Rest Untertitel („Kürbissuppe | Ingwer | Kernöl“). Concept/Paket:
     * Name + Anzahl Gerichte.
     *
     * @return array{0:string, 1:?string}
     */
    private function titelUndWording(FoodAlchemistSpeiseplanEintrag $e, Collection $gerichte): array
    {
        $name = $this->eintragName($e);
        if ($e->sales_recipe_id !== null) {
            $teile = array_values(array_filter(array_map('trim', explode('|', $name)), fn ($t) => $t !== ''));
            if (count($teile) > 1) {
                return [$teile[0], implode(' · ', array_slice($teile, 1))];
            }

            return [$name, null];
        }
        $typ = $e->concept_id !== null ? 'Concept' : 'Paket';
        $n = $gerichte->count();

        return [$name, $typ . ($n > 0 ? ' · ' . $n . ' Gericht' . ($n === 1 ? '' : 'e') : '')];
    }

    /**
     * Diät-Merkmale aus vorhandenen Flags — ohne Raten. Geflügel/Lamm/Wild haben kein Datenfeld;
     * sie erscheinen als „fleisch“ (unbestimmt), nicht als geratene Tierart (Spec 57 · E3).
     * Spec 59: „fleisch“ nur bei BELEGTEM Fleisch (`fleisch_belegt` im Rollup). Gerichte ganz ohne
     * Diät-Pflege liefern `[]` (= ohne Angabe) — unbekannt ist nicht Fleisch.
     *
     * @return list<string>  vegan | vegetarisch | schwein | rind | fisch | fleisch
     */
    private function diaetMerkmale(array $roll, array $kennzeichnung): array
    {
        if (($roll['n_gerichte'] ?? 0) === 0) {
            return [];
        }
        if ($roll['is_vegan']) {
            return ['vegan'];
        }
        if ($roll['is_vegetarian']) {
            return ['vegetarisch'];
        }
        $out = [];
        if ($roll['contains_pork']) {
            $out[] = 'schwein';
        }
        if ($roll['contains_beef']) {
            $out[] = 'rind';
        }
        $fisch = collect($kennzeichnung['allergene'] ?? [])->contains(fn ($a) => $a['slug'] === 'fish' && $a['status'] === 'enthalten');
        if ($fisch) {
            $out[] = 'fisch';
        }

        // „Fleisch" nur, wenn es FESTSTEHT (mind. ein Gericht ausdrücklich nicht vegetarisch, oder
        // Schwein/Rind gepflegt). Unbekannte Diät-Angaben (NULL nach Recompute, GP ohne Tags) sind
        // kein Fleisch — vorher landeten Suppen und Desserts ohne Pflege als „Fleisch" auf dem Aushang.
        if ($out === [] && ($roll['fleisch_belegt'] ?? false)) {
            $out[] = 'fleisch';
        }

        return $out;
    }

    /**
     * Komponenten für die Detail-Dichte: beim Gericht die Zutaten (Name, Menge, Einheit), bei
     * Concept/Paket die enthaltenen Gerichte. Nur auf Anforderung geladen (eine Abfrage je Gericht).
     *
     * @return list<array{name:string, menge:?string}>
     */
    private function komponenten(FoodAlchemistSpeiseplanEintrag $e, Collection $gerichte): array
    {
        if ($e->sales_recipe_id === null) {
            return $gerichte->map(fn ($g) => ['name' => (string) $g->name, 'menge' => null])->values()->all();
        }
        $dish = $gerichte->first();
        if ($dish === null) {
            return [];
        }

        return $dish->ingredients()->with(['gp:id,name', 'referencedRecipe:id,name', 'unit:id,slug'])->limit(12)->get()
            ->map(fn ($z) => [
                'name' => (string) ($z->display_name ?: ($z->gp?->name ?? $z->referencedRecipe?->name ?? $z->raw_text)),
                'menge' => $z->quantity !== null
                    ? rtrim(rtrim(number_format((float) $z->quantity, 2, ',', ''), '0'), ',') . ' ' . ($z->unit?->slug ?? '')
                    : null,
            ])->values()->all();
    }

    // Spec 57 · 0.9: der linienbasierte `veggieCheck` ist entfernt — er wurde berechnet, aber nie
    // angezeigt (und lieferte `active` statt des dokumentierten `aktiv`). Die rezeptbasierte
    // {@see kostformAbdeckung} deckt „vegetarisch an jedem Tag“ ab.

    // ── Spec 31 (GV-Ausbau): Kennzeichnung + Kostformen-Abdeckung ─────────────

    /**
     * Löst einen Speiseplan-Eintrag (Concept/Paket/Gericht) auf seine tatsächlichen Gerichte
     * (Verkaufsrezepte) auf — Basis für Kennzeichnungs- und Diät-Rollups. Gleiche Sammel-Logik
     * wie {@see ConceptService::allergenRollup} (slots→package→dishes + slot→dish). In-Request
     * memoisiert, damit derselbe Inhalt in einer Woche nicht mehrfach geladen wird.
     *
     * @return Collection<int, FoodAlchemistRecipe>
     */
    /**
     * Anzeigename eines Eintrags fürs Kunden-/Aushang-Dokument: Gericht über die Wording-Kette
     * (saubere Kunden-Namen, ohne interne [HG]/[KAE]-Marker), Paket/Concept behalten ihren Namen.
     */
    public function eintragName(FoodAlchemistSpeiseplanEintrag $e): string
    {
        if ($e->sales_recipe_id !== null) {
            $dish = $e->relationLoaded('dish') ? $e->dish : $e->dish()->first();
            if ($dish !== null) {
                return app(WordingResolver::class)->fuerGericht($dish)['text'] ?? $e->inhaltName();
            }
        }

        return $e->inhaltName();
    }

    /**
     * LMIV-Codes EINES Eintrags (Allergen-Buchstaben, `*` = Spuren, Zusatzstoff-Nummern) aus dem
     * Kennzeichnungs-Rollup seiner Gerichte. Sammelt die vorkommenden Slugs by-ref für die Legende.
     * Eine Stelle für Aushang ({@see dokumentDaten}) und Zellen-Kennzahlen.
     *
     * @return list<string>
     */
    public function eintragCodes(FoodAlchemistSpeiseplanEintrag $e, array &$usedAlg = [], array &$usedZus = []): array
    {
        $agg = app(ConcepterAggregateService::class);
        $katalog = $agg->kennzeichnungKatalog();
        $k = $agg->kennzeichnungFromGerichte($this->eintragGerichte($e));
        $codes = [];
        foreach ($k['allergene'] as $a) {
            if ($a['status'] === 'enthalten' || $a['status'] === 'spuren') {
                $usedAlg[$a['slug']] = true;
                $codes[] = $katalog['allergene'][$a['slug']]['code'] . ($a['status'] === 'spuren' ? '*' : '');
            }
        }
        foreach ($k['zusatzstoffe'] as $z) {
            if ($z['status'] === 'ja') {
                $usedZus[$z['slug']] = true;
                $codes[] = $katalog['zusatzstoffe'][$z['slug']]['code'];
            }
        }

        return $codes;
    }

    public function eintragGerichte(FoodAlchemistSpeiseplanEintrag $e): Collection
    {
        $key = $e->inhaltKey();
        if ($key === null) {
            return collect();
        }
        if (isset($this->gerichteCache[$key])) {
            return $this->gerichteCache[$key];
        }

        $gerichte = collect();
        if ($e->sales_recipe_id !== null) {
            $dish = FoodAlchemistRecipe::find($e->sales_recipe_id);
            $gerichte = $dish ? collect([$dish]) : collect();
        } elseif ($e->package_id !== null) {
            $pkg = FoodAlchemistPaket::with('dishes.gericht')->find($e->package_id);
            $gerichte = $pkg ? $pkg->dishes->pluck('gericht')->filter() : collect();
        } elseif ($e->concept_id !== null) {
            $c = FoodAlchemistConcept::with(['slots.package.dishes.gericht', 'slots.dish'])->find($e->concept_id);
            if ($c !== null) {
                foreach ($c->slots as $slot) {
                    if ($slot->package) {
                        $gerichte = $gerichte->merge($slot->package->dishes->pluck('gericht')->filter());
                    }
                    if ($slot->dish) {
                        $gerichte->push($slot->dish);
                    }
                }
            }
        }

        return $this->gerichteCache[$key] = $gerichte->filter()->unique('id')->values();
    }

    /**
     * LMIV-Kennzeichnung (14 Allergene + 18 Zusatzstoffe) je Werktag der sichtbaren Woche +
     * Wochen-Rollup, ALL-MAXIMAL über alle Gerichte des Tages. Für Rail-Übersicht + Aushang.
     *
     * @return array{pro_tag: array<string, array>, woche: array}
     */
    public function wochenKennzeichnung(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?int $tage = null): array
    {
        $agg = app(ConcepterAggregateService::class);
        $proTag = [];
        $wocheGerichte = collect();
        foreach ($this->aggregatTage($plan, $montag, $tage) as $tag) {
            $tagGerichte = collect();
            foreach ($plan->entries as $e) {
                if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->isSameDay($tag)) {
                    continue;
                }
                $tagGerichte = $tagGerichte->merge($this->eintragGerichte($e));
            }
            $tagGerichte = $tagGerichte->filter()->unique('id')->values();
            $proTag[$tag->format('Y-m-d')] = $agg->kennzeichnungFromGerichte($tagGerichte);
            $wocheGerichte = $wocheGerichte->merge($tagGerichte);
        }

        return ['pro_tag' => $proTag, 'woche' => $agg->kennzeichnungFromGerichte($wocheGerichte->unique('id')->values())];
    }

    /**
     * Aushang-Daten (Spec 31 / Stufe B): druckbarer Wochen-Speiseplan als Grid Linien × Mo–Fr,
     * je Gericht Allergen-Buchstaben (A…) + Zusatzstoff-Nummern (1…), darunter eine Legende NUR
     * der tatsächlich vorkommenden Kennzeichen (LMIV). Spuren als »Code*« markiert.
     *
     * @return array{plan:FoodAlchemistSpeiseplan, mahlzeitLabel:string, kwLabel:string,
     *               tage:list<array{ymd:string,label:string}>,
     *               zeilen:list<array{linie:?string,color:?string,zellen:array<string,list<array{name:string,codes:list<string>}>>}>,
     *               legende:array{allergene:list<array{code:string,label:string}>, zusatzstoffe:list<array{code:string,label:string}>},
     *               kostformen:list, erzeugt:string}
     */
    public function dokumentDaten(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit = 'mittag', ?string $montag = null, bool $intern = false, bool $mitKaskade = false, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null, bool $mitPreis = false): array
    {
        $mahlzeit = array_key_exists($mahlzeit, self::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $mo = ($montag !== null ? Carbon::parse($montag) : ($plan->start_date ?? Carbon::now()))->startOfWeek(Carbon::MONDAY);

        // Codes: Allergene = Buchstaben in EU-Reihenfolge, Zusatzstoffe = Nummern.
        $allergenCode = [];
        $i = 0;
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $slug => $label) {
            $allergenCode[$slug] = ['code' => chr(65 + $i), 'label' => $label];
            $i++;
        }
        $zusatzCode = [];
        $j = 1;
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE as $slug => $label) {
            $zusatzCode[$slug] = ['code' => (string) $j, 'label' => $label];
            $j++;
        }

        $agg = app(ConcepterAggregateService::class);
        $tage = [];
        $raster = $this->wochenRaster($plan, $mahlzeit, $mo);       // [line_id][Ymd] => [entries]
        $usedAlg = [];
        $usedZus = [];

        // Zellen-Inhalt je Eintrag → Name + Codes; sammelt nebenbei die Legende.
        $codesFuer = function (FoodAlchemistSpeiseplanEintrag $e) use (&$usedAlg, &$usedZus, $outlet, $mitPreis): array {
            $codes = $this->eintragCodes($e, $usedAlg, $usedZus);

            $zelle = ['name' => $this->eintragName($e), 'codes' => $codes];
            // Preis nur wenn ausdrücklich gewünscht (GV-Aushang ist per Default preislos), betriebs-aware.
            if ($mitPreis) {
                $zelle['vk'] = round((float) $this->eintragPreis($e, $outlet)['vk'], 2);
            }

            return $zelle;
        };

        // Spec 57 · Paket 9: Spalten = Öffnungstage des Plans (Standard Mo–Fr).
        foreach ($this->wochenTage($plan, $mo) as $tag) {
            $tage[] = ['ymd' => $tag->format('Y-m-d'), 'label' => self::WOCHENTAGE[$tag->isoWeekday()] . ' ' . $tag->format('d.m.')];
        }
        $letzterTag = $tage !== [] ? Carbon::parse(end($tage)['ymd']) : $mo->copy()->addDays(4);

        // Zeilen = Menü-Linien dieser Mahlzeit (+ »Ohne Linie«, falls belegt). Spec 57 · E11: eine
        // Linie mit fester Mahlzeit erscheint nur dort.
        $linienListe = $plan->lines->filter(fn ($l) => $l->giltFuerMahlzeit($mahlzeit))
            ->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name, 'color' => $l->color, 'role' => $l->role, 'plu' => $l->plu])->values()->all();
        if (isset($raster[0])) {
            $linienListe[] = ['id' => 0, 'name' => 'Ohne Linie', 'color' => null];
        }

        $zeilen = [];
        foreach ($linienListe as $lin) {
            $zellen = [];
            foreach ($tage as $t) {
                $eintraege = $raster[$lin['id']][$t['ymd']] ?? [];
                $zellen[$t['ymd']] = array_map($codesFuer, $eintraege);
            }
            $zeilen[] = ['linie' => $lin['name'], 'color' => $lin['color'], 'role' => $lin['role'] ?? null, 'plu' => $lin['plu'] ?? null, 'zellen' => $zellen];
        }

        $legendeAlg = [];
        foreach ($allergenCode as $slug => $cl) {
            if (isset($usedAlg[$slug])) {
                $legendeAlg[] = $cl;
            }
        }
        $legendeZus = [];
        foreach ($zusatzCode as $slug => $cl) {
            if (isset($usedZus[$slug])) {
                $legendeZus[] = $cl;
            }
        }

        // #3: optionaler Produktions-Kaskaden-Anhang. Gericht-Rezepte je Wochen-Eintrag via
        // eintragGerichte (über alle Linien × Tage der gewählten Mahlzeit/Woche). Je Gericht der
        // rekursive Baum aus ReportExportService (report-recipe-node). EK nur bei $intern.
        $kaskaden = [];
        if ($mitKaskade) {
            $gerichtIds = [];
            foreach ($raster as $proLinie) {
                foreach ($proLinie as $eintraege) {
                    foreach ($eintraege as $e) {
                        foreach ($this->eintragGerichte($e) as $g) {
                            if ($g !== null) {
                                $gerichtIds[] = (int) $g->id;
                            }
                        }
                    }
                }
            }
            $kOpt = [
                'stammdaten' => true, 'zutaten' => true, 'kaskade' => true,
                'steps' => false, 'sensorik' => false, 'produktion' => false, 'bilder' => false,
                'deklaration' => false, 'naehrwerte' => false, 'notizen' => false,
                'preise' => $intern, 'lieferanten' => $intern, 'ek' => $intern, 'intern' => $intern,
            ];
            $report = app(\Platform\FoodAlchemist\Services\ReportExportService::class);
            foreach (array_values(array_unique($gerichtIds)) as $gid) {
                try {
                    $d = $report->rezeptDaten($team, $gid, $kOpt);
                    $kaskaden[] = ['name' => $d['name'], 'recipe' => $d['recipe'], 'optionen' => $kOpt];
                } catch (\Throwable) {
                    // fail-soft
                }
            }
        }

        return [
            'plan' => $plan,
            'mahlzeitLabel' => self::MAHLZEITEN[$mahlzeit],
            'kwLabel' => 'KW ' . $mo->isoWeek() . ' · ' . $mo->format('d.m.') . '–' . $letzterTag->format('d.m.Y'),
            'montag' => $mo->format('Y-m-d'),
            'mahlzeit' => $mahlzeit,
            'tage' => $tage,
            'zeilen' => $zeilen,
            'legende' => ['allergene' => $legendeAlg, 'zusatzstoffe' => $legendeZus],
            'kostformen' => $this->kostformAbdeckung($plan, $mahlzeit, $mo),
            'naehrwerte' => $this->wochenNaehrwerte($plan, $mahlzeit, $mo),
            'erzeugt' => Carbon::now()->format('d.m.Y'),
            // #3: Kaskaden-Anhang + interne Sicht.
            'intern' => $intern,
            'kaskaden' => $kaskaden,
        ];
    }

    /**
     * Spec 31 / Stufe C: Wochen-Speiseplan an die Produktion übergeben. Erzeugt je Öffnungstag MIT
     * Belegung EINEN Produktionsauftrag (GV kocht tagesweise); jeder Eintrag wird zu einem Ziel
     * (Concept → persons, VK-Gericht → portions, Paket → seine Gerichte je portions), Menge =
     * effektive Pax ({@see effektivePax}). Zielform gespiegelt aus Produktion\Editor.
     *
     * Spec 57 · 0.2: IDEMPOTENT. Ein zweiter Aufruf legt keine Dubletten an: ein noch offener
     * Auftrag desselben Plans/Tags/Namens bekommt die Ziele ersetzt (`aktualisiert`); ein Auftrag,
     * der schon läuft oder fertig ist, bleibt unangetastet (`gesperrt`).
     *
     * @return array{auftraege:int, aktualisiert:int, gesperrt:int, ziele:int, tage:list<string>}
     */
    public function wocheAnProduktion(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?int $userId = null): array
    {
        $this->guard($plan, $team);
        $mahlzeit = array_key_exists($mahlzeit, self::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $produktion = app(ProductionOrderService::class);
        $raster = $this->wochenRaster($plan, $mahlzeit, $montag);
        $referenz = 'Speiseplan #' . $plan->id;

        $auftraege = 0;
        $aktualisiert = 0;
        $gesperrt = 0;
        $zieleGesamt = 0;
        $tage = [];
        foreach ($this->wochenTage($plan, $montag) as $tag) {
            $ymd = $tag->format('Y-m-d');
            $eintraege = collect($raster)->flatMap(fn ($proLinie) => $proLinie[$ymd] ?? []);
            if ($eintraege->isEmpty()) {
                continue;
            }

            $targets = $this->produktionsZiele($plan, $eintraege, $ymd);
            if ($targets === []) {
                continue;
            }

            $name = $plan->name . ' · ' . self::WOCHENTAGE[$tag->isoWeekday()] . ' ' . $tag->format('d.m.') . ' (' . self::MAHLZEITEN[$mahlzeit] . ')';
            $vorhanden = \Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder::where('team_id', $team->id)
                ->where('reference', $referenz)
                ->whereDate('production_date', $ymd)
                ->where('name', $name)
                // Ein stornierter Auftrag blockiert nicht — dann wird neu angelegt.
                ->where('status', '!=', \Platform\FoodAlchemist\Enums\ProductionOrderStatus::Cancelled->value)
                ->latest('id')->first();
            if ($vorhanden !== null) {
                $status = $vorhanden->status instanceof \Platform\FoodAlchemist\Enums\ProductionOrderStatus
                    ? $vorhanden->status
                    : \Platform\FoodAlchemist\Enums\ProductionOrderStatus::from((string) $vorhanden->status);
                if (! $status->istOffen()) {
                    $gesperrt++;

                    continue;
                }
                $produktion->replaceTargets($team, (int) $vorhanden->id, $targets);
                $aktualisiert++;
            } else {
                $produktion->saveNew($team, $ymd, $name, $targets, $referenz, null, $userId);
                $auftraege++;
            }
            $zieleGesamt += count($targets);
            $tage[] = $ymd;
        }

        return ['auftraege' => $auftraege, 'aktualisiert' => $aktualisiert, 'gesperrt' => $gesperrt, 'ziele' => $zieleGesamt, 'tage' => $tage];
    }

    /**
     * Produktions-Ziele der Einträge eines Tages — EINE Zielform für Produktion und Bedarf:
     * Concept → persons, VK-Gericht → portions, Paket → seine Gerichte je portions; Menge =
     * {@see effektivePax}. `source_ref` bleibt je Eintrag stabil (Idempotenz der Produktion).
     *
     * @param  iterable<FoodAlchemistSpeiseplanEintrag>  $eintraege
     * @return list<array<string, mixed>>
     */
    private function produktionsZiele(FoodAlchemistSpeiseplan $plan, iterable $eintraege, string $ymd): array
    {
        $targets = [];
        foreach ($eintraege as $e) {
            $pax = $this->effektivePax($e, $plan);
            $ref = 'menuplan:' . $plan->id . ':' . $ymd . ':' . $e->id;
            if ($e->concept_id !== null) {
                $targets[] = ['concept_id' => (int) $e->concept_id, 'persons' => $pax, 'source_ref' => $ref];
            } elseif ($e->sales_recipe_id !== null) {
                $targets[] = ['recipe_id' => (int) $e->sales_recipe_id, 'portions' => $pax, 'source_ref' => $ref];
            } elseif ($e->package_id !== null) {
                foreach ($this->eintragGerichte($e) as $g) {
                    $targets[] = ['recipe_id' => (int) $g->id, 'portions' => $pax, 'source_ref' => $ref . ':d' . $g->id];
                }
            }
        }

        return $targets;
    }

    // ── Spec 57 · Paket 4: Bedarf (Zutaten aus Plan × Mengen) ────────────────

    /**
     * Zutatenbedarf der Woche (oder eines Tages) einer Mahlzeit: dieselben Ziele wie die
     * Produktion ({@see produktionsZiele}) durch {@see PlanungsblattService::einkaufsliste} —
     * GP-Ebene, Basiseinheit, Lead-Lieferantenartikel, ganze Gebinde. Keine eigene Rechnung.
     * Nur lesend: die Übergabe an den Einkauf läuft über die Produktion (Spec 57 · E7).
     *
     * @return array{tage: list<string>, ziele: int, liste: ?array}
     */
    public function wochenBedarf(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?string $tag = null): array
    {
        $mahlzeit = array_key_exists($mahlzeit, self::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $raster = $this->wochenRaster($plan, $mahlzeit, $montag);
        $tage = array_map(fn (Carbon $t) => $t->format('Y-m-d'), $this->wochenTage($plan, $montag));
        if ($tag !== null) {
            $tage = array_values(array_intersect($tage, [Carbon::parse($tag)->format('Y-m-d')]));
        }
        $ziele = [];
        foreach ($tage as $ymd) {
            $eintraege = collect($raster)->flatMap(fn ($proLinie) => $proLinie[$ymd] ?? []);
            foreach ($this->produktionsZiele($plan, $eintraege, $ymd) as $z) {
                unset($z['source_ref']);
                $ziele[] = $z;
            }
        }

        return [
            'tage' => $tage,
            'ziele' => count($ziele),
            'liste' => $ziele !== [] ? app(PlanungsblattService::class)->einkaufsliste($team, $ziele) : null,
        ];
    }

    // ── Spec 57 · Paket 6: Ausgabe-Formate (Tischaufsteller, Linienschild, Liste, CSV) ──

    public const AUSGABE_FORMATE = ['woche', 'tag', 'schild', 'liste', 'buffet'];

    /**
     * Daten für die Druck-Formate neben dem Wochenaushang. Baut auf {@see dokumentDaten}
     * (Codes, Legende, Kostformen) und {@see zellenKennzahlen} (Titel/Wording, Diät, VK,
     * Komponenten) auf — keine zweite Kennzeichnungs- oder Preislogik.
     *
     * - `tag`: ein Tag, alle Linien (Tischaufsteller)
     * - `schild`: ein Tag, je Linie ein Schild (optional nur eine Linie), mit Preis nur wenn gewünscht
     * - `liste`: Allergen- und Komponentenliste der Woche oder eines Tages (für den Ordner an der Ausgabe)
     *
     * Einträge ohne Linie (Raster-Schlüssel 0) laufen als „Weitere Gerichte“ mit — sonst fehlten
     * sie in der Allergenliste. Die Legende gilt für die gezeigten Einträge, nicht für die Woche.
     *
     * @return array<string, mixed>
     */
    public function ausgabeFormat(Team $team, FoodAlchemistSpeiseplan $plan, string $format, string $mahlzeit = 'mittag', ?string $montag = null, ?string $tag = null, ?int $lineId = null, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null, bool $mitPreis = false): array
    {
        $format = in_array($format, self::AUSGABE_FORMATE, true) ? $format : 'woche';
        $dok = $this->dokumentDaten($team, $plan, $mahlzeit, $montag ?? $tag, false, false, $outlet, $mitPreis);
        if ($format === 'woche') {
            return $dok + ['format' => 'woche'];
        }
        $mo = Carbon::parse($dok['montag']);
        $zk = $this->zellenKennzahlen($team, $plan, $dok['mahlzeit'], $mo, $outlet, $format === 'liste');
        $raster = $this->wochenRaster($plan, $dok['mahlzeit'], $mo);
        $tageDerWoche = array_column($dok['tage'], 'label', 'ymd');
        $tagYmd = $tag !== null ? Carbon::parse($tag)->format('Y-m-d') : null;
        $tage = $tagYmd !== null && isset($tageDerWoche[$tagYmd]) ? [$tagYmd] : array_keys($tageDerWoche);
        if ($format !== 'liste' && $tagYmd === null) {
            $tage = array_slice($tage, 0, 1);   // Tischaufsteller/Schild: ohne Tag der erste Öffnungstag
        }

        $linien = $plan->lines->filter(fn ($l) => $l->giltFuerMahlzeit($dok['mahlzeit']) && ($lineId === null || (int) $l->id === $lineId))
            ->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name, 'color' => $l->color, 'plu' => $l->plu, 'role' => $l->role])->values()->all();
        if ($lineId === null && isset($raster[0])) {
            $linien[] = ['id' => 0, 'name' => 'Weitere Gerichte', 'color' => null, 'plu' => null, 'role' => null];
        }
        $usedCodes = [];
        $bloecke = [];
        foreach ($tage as $ymd) {
            $zeilen = [];
            foreach ($linien as $l) {
                $eintraege = [];
                foreach ($raster[$l['id']][$ymd] ?? [] as $e) {
                    $k = $zk['eintraege'][$e->id] ?? [];
                    $eintraege[] = [
                        'titel' => $k['titel'] ?? $e->inhaltName(),
                        'untertitel' => $k['untertitel'] ?? null,
                        'codes' => $k['codes'] ?? [],
                        'diaet' => $k['diaet'] ?? [],
                        'vk' => $mitPreis ? ($k['vk'] ?? null) : null,
                        'komponenten' => $k['komponenten'] ?? [],
                        'kcal' => $k['kcal'] ?? null,
                    ];
                    foreach ($k['codes'] ?? [] as $c) {
                        $usedCodes[rtrim((string) $c, '*')] = true;
                    }
                }
                // Leeres Schild nur für echte Linien — „Weitere Gerichte“ erscheint nur, wenn belegt.
                if ($eintraege !== [] || ($format === 'schild' && $l['id'] !== 0)) {
                    $zeilen[] = ['linie' => $l['name'], 'color' => $l['color'], 'plu' => $l['plu'], 'role' => $l['role'], 'eintraege' => $eintraege];
                }
            }
            $bloecke[] = ['ymd' => $ymd, 'label' => $tageDerWoche[$ymd] ?? $ymd, 'zeilen' => $zeilen];
        }

        return [
            'format' => $format, 'plan' => $plan, 'mahlzeitLabel' => $dok['mahlzeitLabel'], 'kwLabel' => $dok['kwLabel'],
            'bloecke' => $bloecke, 'legende' => $this->legendeAusCodes(array_keys($usedCodes)), 'mitPreis' => $mitPreis, 'erzeugt' => $dok['erzeugt'],
            'rollen' => FoodAlchemistSpeiseplanLinie::ROLLEN, 'optik' => $this->druckOptik($team, $plan),
        ];
    }

    /**
     * Legende nur für die Codes, die im Dokument wirklich stehen (Allergen-Buchstaben,
     * Zusatzstoff-Nummern; `*` = Spuren ist vorher abgeschnitten). Reihenfolge wie im Katalog.
     *
     * @param  list<string>  $codes
     * @return array{allergene: list<array{code:string,label:string}>, zusatzstoffe: list<array{code:string,label:string}>}
     */
    private function legendeAusCodes(array $codes): array
    {
        $katalog = app(ConcepterAggregateService::class)->kennzeichnungKatalog();
        $da = array_flip(array_map('strval', $codes));

        return [
            'allergene' => array_values(array_filter($katalog['allergene'], fn ($a) => isset($da[$a['code']]))),
            'zusatzstoffe' => array_values(array_filter($katalog['zusatzstoffe'], fn ($z) => isset($da[$z['code']]))),
        ];
    }

    /**
     * Optik der Gäste-Drucke (Tischaufsteller, Linienschild, Buffetschild) aus dem Präsentationsmodus:
     * Logo + Footer aus dem Branding des Plans, Farben + Schriftcharakter aus dem gewählten
     * Präsentations-Design. Gedruckt wird immer auf weißem Papier — ein dunkles Design (Kiosk)
     * liefert deshalb nur den Akzent, abgedunkelt bis er auf Weiß lesbar ist (≥ 4,5 : 1).
     *
     * @return array{logo:?string, footer:?string, akzent:string, band:string, text:string, muted:string, serif:bool, schrift_kopf:string, schrift_text:string}
     */
    public function druckOptik(Team $team, FoodAlchemistSpeiseplan $plan): array
    {
        $tokens = app(PresentationDesignService::class)->resolveTokens($plan->presentation_design ?: 'kiosk', $team);
        $pal = $tokens['palette'] ?? [];
        $hell = $this->luminanz((string) ($pal['bg'] ?? '#ffffff')) >= 0.5;
        $akzent = $this->lesbarAufWeiss((string) ($pal['primary'] ?? ''), '#6d28d9');
        $serif = in_array($tokens['typography']['heading'] ?? 'display-serif', ['display-serif', 'serif'], true);

        return [
            'logo' => app(FoodAlchemistMediaService::class)->dataUri($plan->logo_context_file_id ?? null, $plan->logo_path ?? null),
            'footer' => trim((string) $plan->footer_text) !== '' ? trim((string) $plan->footer_text) : null,
            'akzent' => $akzent,
            'band' => $this->lesbarAufWeiss((string) ($plan->band_color ?? ''), $akzent),
            'text' => $hell ? $this->lesbarAufWeiss((string) ($pal['text'] ?? ''), '#1a1712') : '#1a1712',
            'muted' => $hell ? $this->lesbarAufWeiss((string) ($pal['muted'] ?? ''), '#5f5850') : '#5f5850',
            'serif' => $serif,
            // Schrift-Stacks fürs Template; PT Serif bettet partials/speiseplan-druck-schrift ein.
            'schrift_kopf' => $serif ? '"PT Serif", "DejaVu Serif", Georgia, serif' : '"DejaVu Sans", Arial, sans-serif',
            'schrift_text' => '"DejaVu Sans", Arial, sans-serif',
        ];
    }

    /** Relative Leuchtdichte (WCAG) eines #rrggbb-Werts; Nicht-Hex zählt als Weiß. */
    private function luminanz(string $hex): float
    {
        if (! preg_match('/^#?([0-9a-f]{6})$/i', trim($hex), $m)) {
            return 1.0;
        }
        $kanal = function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = array_map('hexdec', str_split($m[1], 2));

        return 0.2126 * $kanal($r) + 0.7152 * $kanal($g) + 0.0722 * $kanal($b);
    }

    /** Farbe so weit abdunkeln, bis sie auf Weiß 4,5 : 1 erreicht; Nicht-Hex → Ersatzfarbe. */
    private function lesbarAufWeiss(string $hex, string $ersatz): string
    {
        $hex = trim($hex);
        if (! preg_match('/^#?([0-9a-f]{6})$/i', $hex, $m)) {
            return $ersatz;
        }
        [$r, $g, $b] = array_map('hexdec', str_split($m[1], 2));
        for ($i = 0; $i < 30; $i++) {
            $farbe = sprintf('#%02x%02x%02x', $r, $g, $b);
            if (1.05 / ($this->luminanz($farbe) + 0.05) >= 4.5) {
                return $farbe;
            }
            [$r, $g, $b] = [(int) floor($r * 0.9), (int) floor($g * 0.9), (int) floor($b * 0.9)];
        }

        return $ersatz;
    }

    /**
     * Buffetschilder: ein Zeltkärtchen je Gericht eines Tages — Pakete und Concepts in ihre
     * Gerichte aufgelöst ({@see eintragGerichte}), optional dazu je Unterrezept (eine Ebene,
     * z. B. Jus oder Beilage) ein eigenes Kärtchen. Allergene ausgeschrieben, nicht als Code.
     *
     * Diät-Hinweise nur aus gesetzten Flags (vegan/vegetarisch, Schwein/Rind/Fisch) — das
     * unbestimmte „mit Fleisch“ aus {@see diaetMerkmale} steht auf keinem Buffetschild, weil
     * Unterrezepte die Flags oft gar nicht tragen. Unbewertete Allergene → `unvollstaendig`.
     *
     * @return array<string, mixed>
     */
    public function buffetKarten(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit = 'mittag', ?string $montag = null, ?string $tag = null, ?int $lineId = null, bool $mitUnterrezepten = true): array
    {
        $mahlzeit = array_key_exists($mahlzeit, self::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $mo = Carbon::parse($montag ?? $tag ?? ($plan->start_date ?? Carbon::now()))->startOfWeek(Carbon::MONDAY);
        $tage = [];
        foreach ($this->wochenTage($plan, $mo) as $t) {
            $tage[$t->format('Y-m-d')] = self::WOCHENTAGE[$t->isoWeekday()] . ' ' . $t->format('d.m.');
        }
        $tagYmd = $tag !== null ? Carbon::parse($tag)->format('Y-m-d') : null;
        if ($tagYmd === null || ! isset($tage[$tagYmd])) {
            $tagYmd = array_key_first($tage) ?? $mo->format('Y-m-d');
        }

        $raster = $this->wochenRaster($plan, $mahlzeit, $mo);
        $linienIds = $plan->lines->filter(fn ($l) => $l->giltFuerMahlzeit($mahlzeit) && ($lineId === null || (int) $l->id === $lineId))
            ->map(fn ($l) => (int) $l->id)->values()->all();
        if ($lineId === null) {
            $linienIds[] = 0;
        }
        $linienNamen = $plan->lines->mapWithKeys(fn ($l) => [(int) $l->id => $l->name])->all();

        $agg = app(ConcepterAggregateService::class);
        $katalog = $agg->kennzeichnungKatalog();
        $wording = app(WordingResolver::class);
        $karten = [];
        $gesehen = [];
        $karte = function (FoodAlchemistRecipe $r, ?string $zu, ?string $linie) use ($agg, $katalog, $wording): array {
            $text = $wording->fuerGericht($r)['text'];
            if (trim((string) $r->sales_wording_standard) === '') {
                $text = trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $text) ?? $text);   // „(Basis)“-Suffix ist intern
            }
            $teile = array_values(array_filter(array_map('trim', explode('|', $text)), fn ($t) => $t !== ''));
            $k = $agg->kennzeichnungFromGerichte(collect([$r]));
            $allergene = [];
            $unvollstaendig = false;
            foreach ($k['allergene'] as $a) {
                if ($a['status'] === 'enthalten' || $a['status'] === 'spuren') {
                    $allergene[] = ['code' => $katalog['allergene'][$a['slug']]['code'], 'label' => $a['label'], 'spuren' => $a['status'] === 'spuren'];
                } elseif ($a['status'] === 'unbekannt') {
                    $unvollstaendig = true;
                }
            }
            $zusatz = [];
            foreach ($k['zusatzstoffe'] as $z) {
                if ($z['status'] === 'ja') {
                    $zusatz[] = ['code' => $katalog['zusatzstoffe'][$z['slug']]['code'], 'label' => $z['label']];
                }
            }
            $diaet = match (true) {
                (bool) $r->spec_is_vegan => ['vegan'],
                (bool) $r->spec_is_vegetarian => ['vegetarisch'],
                default => array_values(array_filter([
                    $r->spec_contains_pork ? 'schwein' : null,
                    $r->spec_contains_beef ? 'rind' : null,
                    collect($k['allergene'])->contains(fn ($a) => $a['slug'] === 'fish' && $a['status'] === 'enthalten') ? 'fisch' : null,
                ])),
            };

            return [
                'recipe_id' => (int) $r->id, 'titel' => $teile[0] ?? (string) $r->name,
                'wording' => count($teile) > 1 ? implode(' · ', array_slice($teile, 1)) : null,
                'zu' => $zu, 'linie' => $linie, 'diaet' => $diaet,
                'allergene' => $allergene, 'zusatzstoffe' => $zusatz, 'unvollstaendig' => $unvollstaendig,
            ];
        };

        foreach ($linienIds as $lid) {
            foreach ($raster[$lid][$tagYmd] ?? [] as $e) {
                foreach ($this->eintragGerichte($e) as $g) {
                    if (isset($gesehen[(int) $g->id])) {
                        continue;
                    }
                    $gesehen[(int) $g->id] = true;
                    $gk = $karte($g, null, $linienNamen[$lid] ?? null);
                    $karten[] = $gk;
                    if (! $mitUnterrezepten) {
                        continue;
                    }
                    $subs = $g->ingredients()->whereNotNull('referenced_recipe_id')->with('referencedRecipe')->orderBy('position')->get()
                        ->pluck('referencedRecipe')->filter();
                    foreach ($subs as $sub) {
                        if (isset($gesehen[(int) $sub->id])) {
                            continue;
                        }
                        $gesehen[(int) $sub->id] = true;
                        $karten[] = $karte($sub, $gk['titel'], $linienNamen[$lid] ?? null);
                    }
                }
            }
        }

        return [
            'format' => 'buffet', 'plan' => $plan, 'mahlzeitLabel' => self::MAHLZEITEN[$mahlzeit],
            'tagLabel' => $tage[$tagYmd] ?? $tagYmd, 'karten' => $karten, 'mitUnterrezepten' => $mitUnterrezepten,
            'optik' => $this->druckOptik($team, $plan), 'erzeugt' => Carbon::now()->format('d.m.Y'),
        ];
    }

    /**
     * CSV-Zeilen der Woche (eine Mahlzeit): je Eintrag Datum, Tag, Mahlzeit, Linie, Kassen-Nr.,
     * Gericht, Kennzeichnung, Essen, VK netto, EK, Wareneinsatz. Für Controlling und Kasse.
     *
     * @return list<list<string|int|float|null>>
     */
    public function csvZeilen(Team $team, FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $mahlzeit = array_key_exists($mahlzeit, self::MAHLZEITEN) ? $mahlzeit : 'mittag';
        $zk = $this->zellenKennzahlen($team, $plan, $mahlzeit, $montag, $outlet);
        $linien = $plan->lines->keyBy(fn ($l) => (int) $l->id);
        $tage = array_map(fn (Carbon $t) => $t->format('Y-m-d'), $this->wochenTage($plan, $montag));
        $zahl = fn ($v, int $d = 2) => $v === null ? '' : number_format((float) $v, $d, ',', '');

        $zeilen = [['Datum', 'Tag', 'Mahlzeit', 'Linie', 'Kassen-Nr.', 'Gericht', 'Kennzeichnung', 'Essen', 'VK netto', 'EK', 'Wareneinsatz %']];
        foreach ($this->wochenRaster($plan, $mahlzeit, $montag) as $lineId => $proTag) {
            foreach ($proTag as $ymd => $liste) {
                if (! in_array($ymd, $tage, true)) {
                    continue;
                }
                foreach ($liste as $e) {
                    $k = $zk['eintraege'][$e->id] ?? [];
                    $l = $linien->get((int) $lineId);
                    $zeilen[] = [
                        Carbon::parse($ymd)->format('d.m.Y'), self::WOCHENTAGE[Carbon::parse($ymd)->isoWeekday()],
                        self::MAHLZEITEN[$mahlzeit], $l?->name ?? 'Ohne Linie', $l?->plu ?? '',
                        $this->eintragName($e), implode(' ', $k['codes'] ?? []), (int) ($k['pax'] ?? 0),
                        $zahl($k['vk'] ?? null), $zahl($k['ek'] ?? null), $zahl($k['wes'] ?? null, 1),
                    ];
                }
            }
        }
        // Sortiert nach Datum, dann Linien-Reihenfolge des Plans („Ohne Linie“ zuletzt) — so liest
        // sich die Datei wie der Plan (vorher alphabetisch nach Linienname).
        $kopf = array_shift($zeilen);
        $rang = array_flip($plan->lines->pluck('name')->all());
        $schluessel = fn ($z) => [Carbon::createFromFormat('d.m.Y', $z[0])->format('Ymd'), $rang[$z[3]] ?? PHP_INT_MAX];
        usort($zeilen, fn ($a, $b) => $schluessel($a) <=> $schluessel($b));

        return [$kopf, ...$zeilen];
    }

    // ── Spec 31 / Stufe D: DGE-Nährwertbilanz + Abwechslung ──────────────────

    /**
     * Nährwert-Wochenbilanz — Ø je Person und Werktag (kcal/Eiweiß/Fett/ges.Fett/Salz/Zucker/KH).
     * Reuse {@see ConcepterAggregateService::naehrwertAggregat}: je Gericht 1 Portion/Person
     * (GV-Modell „eine Komponente = eine Portion"). Gemittelt über Werktage MIT Nährwertdaten.
     *
     * @return array{schnitt: array<string,?float>, tage_mit_daten:int, confidence:string}
     */
    public function wochenNaehrwerte(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?int $tage = null): array
    {
        $agg = app(ConcepterAggregateService::class);
        $felder = ['kcal', 'protein_g', 'fett_g', 'gesfett_g', 'salz_g', 'zucker_g', 'kh_g'];
        $summe = array_fill_keys($felder, 0.0);
        $nTage = 0;
        $konfRang = null;

        foreach ($this->aggregatTage($plan, $montag, $tage) as $tag) {
            $rows = collect();
            foreach ($plan->entries as $e) {
                if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->isSameDay($tag)) {
                    continue;
                }
                foreach ($this->eintragGerichte($e) as $g) {
                    $rows->push(['gericht' => $g, 'quantity' => 1, 'unit' => null]);
                }
            }
            if ($rows->isEmpty()) {
                continue;
            }
            $n = $agg->naehrwertAggregat($rows);
            if (($n['n_mit_naehrwerten'] ?? 0) === 0) {
                continue;
            }
            $nTage++;
            foreach ($felder as $f) {
                $summe[$f] += (float) ($n[$f] ?? 0);
            }
            $rang = self::KONF_RANG[$n['confidence']] ?? 0;
            $konfRang = $konfRang === null ? $rang : min($konfRang, $rang);
        }

        $schnitt = array_fill_keys($felder, null);
        if ($nTage > 0) {
            foreach ($felder as $f) {
                $roh = $summe[$f] / $nTage;
                $schnitt[$f] = $f === 'kcal' ? round($roh) : ($f === 'salz_g' ? round($roh, 2) : round($roh, 1));
            }
        }

        return [
            'schnitt' => $schnitt,
            'tage_mit_daten' => $nTage,
            'confidence' => $nTage === 0 ? 'unknown' : (array_search($konfRang ?? 0, self::KONF_RANG, true) ?: 'unknown'),
        ];
    }

    /**
     * Abwechslung/Häufigkeit der Woche: Diät-Mix je serviertem GERICHT + Warengruppen-Häufigkeit
     * (dish_main_group) + Spec 59 Plan-Vorgaben (mind./höchstens je Chip). Weicher Hinweis, wenn
     * eine Warengruppe die Woche dominiert (≥ $tage Vorkommen). Alles aus vorhandenen Feldern.
     *
     * Spec 59: Diät je Gericht über denselben Rollup wie die Zellen-Chips ({@see diaetMerkmale}).
     * Vorher zählte alles Nicht-Vegane/-Vegetarische als „mit Fleisch oder Fisch“ — auch Gerichte
     * ganz ohne Diät-Pflege. Jetzt: Fleisch nur belegt, Rest unter `ohne_angabe`.
     * Zählbasis = Gericht-Vorkommen (ein Paket/Concept mit 3 Gerichten zählt 3).
     * `diaet_eintraege`/`wg_eintraege`/`treffer` liefern die Eintrag-Ids fürs Hervorheben in der Matrix.
     *
     * @return array{diaet: array{vegan:int, vegetarisch:int, fleisch:int, fisch:int, schwein:int, rind:int, ohne_angabe:int, omnivor:int},
     *               warengruppen: list<array{id:int, name:string, count:int}>, hinweis: ?string,
     *               vorgaben: list<array>, treffer: array<int, list<int>>,
     *               diaet_eintraege: array<string, list<int>>, wg_eintraege: array<int, list<int>>}
     */
    public function wochenAbwechslung(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?int $tage = null): array
    {
        $agg = app(ConcepterAggregateService::class);
        $diaetKeys = ['vegan', 'vegetarisch', 'fleisch', 'fisch', 'schwein', 'rind', 'ohne_angabe'];
        $diaet = array_fill_keys($diaetKeys, 0);
        $omni = 0;
        $diaetEintraege = array_fill_keys($diaetKeys, []);
        $wg = [];            // dish_main_group_id => count
        $wgEintraege = [];   // dish_main_group_id => [entry_id => true]
        $paare = [];         // je Gericht-Vorkommen: Merkmale für die Vorgaben-Prüfung
        $merkmaleCache = [];
        $tageListe = $this->aggregatTage($plan, $montag, $tage);
        $tage = count($tageListe);
        foreach ($tageListe as $tag) {
            foreach ($plan->entries as $e) {
                if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->isSameDay($tag)) {
                    continue;
                }
                foreach ($this->eintragGerichte($e) as $g) {
                    $gc = collect([$g]);
                    $m = $merkmaleCache[$g->id] ??= $this->diaetMerkmale($agg->allergenRollupFromGerichte($gc), $agg->kennzeichnungFromGerichte($gc));
                    // Schwein/Rind sind Fleisch — für Zählung und Chip „Fleisch“.
                    if (array_intersect($m, ['schwein', 'rind']) !== [] && ! in_array('fleisch', $m, true)) {
                        $m[] = 'fleisch';
                    }
                    $schluessel = $m === [] ? ['ohne_angabe'] : $m;
                    foreach ($schluessel as $k) {
                        $diaet[$k]++;
                        $diaetEintraege[$k][$e->id] = true;
                    }
                    if (in_array('fleisch', $m, true) || in_array('fisch', $m, true)) {
                        $omni++;
                    }
                    $gid = $g->dish_main_group_id !== null ? (int) $g->dish_main_group_id : null;
                    if ($gid !== null) {
                        $wg[$gid] = ($wg[$gid] ?? 0) + 1;
                        $wgEintraege[$gid][$e->id] = true;
                    }
                    $paare[] = ['entry_id' => (int) $e->id, 'gericht_id' => (int) $g->id, 'diaet' => $m, 'hauptgruppe' => $gid];
                }
            }
        }

        // Warengruppen-Namen in EINER Query auflösen.
        $namen = [];
        if ($wg !== []) {
            $namen = \Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup::whereIn('id', array_keys($wg))
                ->pluck('label', 'id')->all();
        }
        arsort($wg);
        $warengruppen = [];
        $dominant = null;
        foreach ($wg as $gid => $count) {
            $name = $namen[$gid] ?? ('#' . $gid);
            $warengruppen[] = ['id' => (int) $gid, 'name' => $name, 'count' => $count];
            if ($dominant === null && $count >= $tage) {
                $dominant = $name;
            }
        }
        $vorgaben = app(SpeiseplanVorgabenService::class)->auswerten($plan, $mahlzeit, $paare);

        return [
            // `omnivor` (= Gerichte mit Fleisch ∪ Fisch) bleibt für Altverwender.
            'diaet' => $diaet + ['omnivor' => $omni],
            'warengruppen' => array_slice($warengruppen, 0, 6),
            'hinweis' => $dominant !== null ? 'Warengruppe „' . $dominant . '" dominiert die Woche — mehr Abwechslung erwägen.' : null,
            'vorgaben' => $vorgaben['vorgaben'],
            'treffer' => $vorgaben['treffer'],
            'diaet_eintraege' => array_map('array_keys', $diaetEintraege),
            'wg_eintraege' => array_map('array_keys', $wgEintraege),
        ];
    }

    /**
     * Kostformen-Abdeckung: hat jeder Werktag in der gewählten Mahlzeit mindestens EINEN Eintrag,
     * der die jeweilige Kostform erfüllt? Verallgemeinert den linien-basierten {@see veggieCheck}
     * auf die tatsächliche Rezept-Diät (Diät-Flag-Rollup je Eintrag, ein Eintrag genügt/Tag).
     *
     * @return list<array{key:string, label:string, erfuellt:bool, fehltage:list<string>, abgedeckt:int, tage:int}>
     */
    public function kostformAbdeckung(FoodAlchemistSpeiseplan $plan, string $mahlzeit, Carbon $montag, ?int $tage = null): array
    {
        $agg = app(ConcepterAggregateService::class);
        $fehl = array_fill_keys(array_keys(self::KOSTFORMEN), []);
        $tageListe = $this->aggregatTage($plan, $montag, $tage);
        $tage = count($tageListe);

        foreach ($tageListe as $tag) {
            $tagErfuellt = array_fill_keys(array_keys(self::KOSTFORMEN), false);
            foreach ($plan->entries as $e) {
                if ($e->entry_date === null || $e->meal !== $mahlzeit || ! $e->entry_date->isSameDay($tag)) {
                    continue;
                }
                $roll = $agg->allergenRollupFromGerichte($this->eintragGerichte($e));
                foreach (self::KOSTFORMEN as $k => $_) {
                    if (! $tagErfuellt[$k] && $this->kostformErfuellt($k, $roll)) {
                        $tagErfuellt[$k] = true;
                    }
                }
            }
            foreach (self::KOSTFORMEN as $k => $_) {
                if (! $tagErfuellt[$k]) {
                    $fehl[$k][] = $tag->format('Y-m-d');
                }
            }
        }

        $out = [];
        foreach (self::KOSTFORMEN as $k => $label) {
            $out[] = [
                'key' => $k, 'label' => $label,
                'erfuellt' => $fehl[$k] === [], 'fehltage' => $fehl[$k],
                'abgedeckt' => $tage - count($fehl[$k]), 'tage' => $tage,
            ];
        }

        return $out;
    }

    /** Erfüllt der Diät-Flag-Rollup eines Eintrags die Kostform? (leerer Eintrag erfüllt nichts) */
    private function kostformErfuellt(string $key, array $roll): bool
    {
        if (($roll['n_gerichte'] ?? 0) === 0) {
            return false;
        }

        return match ($key) {
            'vegetarisch' => (bool) $roll['is_vegetarian'],
            'vegan' => (bool) $roll['is_vegan'],
            'schweinefrei' => ! $roll['contains_pork'],
            'glutenfrei' => (bool) $roll['is_gluten_free'],
            'laktosefrei' => (bool) $roll['is_lactose_free'],
            'halal' => (bool) $roll['is_halal'],
            default => false,
        };
    }

    /**
     * Wiederholungs-Check über ECHTE Tages-Abstände: gleicher Inhalt zu eng beieinander.
     *
     * @return list<array{key:string, name:string, vorkommen:int, min_abstand:int, konflikt:bool}>
     */
    public function wiederholungen(FoodAlchemistSpeiseplan $plan): array
    {
        $minRegel = (int) $plan->min_abstand_tage;
        // Spec 57 · Paket 2: Dauerangebote (Salatbar …) stehen bewusst jeden Tag da — sie zählen
        // nicht für die Wiederholungsregel.
        $dauer = $plan->lines->where('is_standing', true)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $proInhalt = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date === null || ($e->line_id !== null && in_array((int) $e->line_id, $dauer, true))) {
                continue;
            }
            $key = $e->inhaltKey();
            if ($key === null) {
                continue;
            }
            $proInhalt[$key]['name'] ??= $e->inhaltName();
            $proInhalt[$key]['tage'][] = $e->entry_date->copy()->startOfDay()->getTimestamp();
        }

        $out = [];
        foreach ($proInhalt as $key => $d) {
            $tage = $d['tage'];
            sort($tage);
            if (count($tage) < 2) {
                continue;
            }
            $minGap = PHP_INT_MAX;
            for ($i = 1; $i < count($tage); $i++) {
                $minGap = min($minGap, (int) round(($tage[$i] - $tage[$i - 1]) / 86400));
            }
            $out[] = [
                'key' => $key, 'name' => $d['name'], 'vorkommen' => count($tage),
                'min_abstand' => $minGap,
                'konflikt' => $minRegel > 0 && $minGap < $minRegel,
            ];
        }

        return $out;
    }

    /**
     * Zyklus-Vorlage ausrollen: den Block [start_date, +cycle_weeks Wochen) auf alle
     * folgenden Zyklen bis $bisDatum kopieren.
     *
     * Spec 57 · 0.3 / E5: die Regel gilt je ZELLE (Datum|Mahlzeit|Linie), nicht je Inhalt. Eine
     * schon belegte Zelle bleibt unberührt — so wie es die Oberfläche verspricht; vorher bekam
     * sie das Vorlage-Gericht zusätzlich. Mit $belegteErsetzen=true wird sie stattdessen durch
     * die Vorlage ersetzt. Pax-Overrides wandern mit.
     *
     * @return int Anzahl neu erzeugter Einträge
     */
    public function vorlageAusrollen(Team $team, int $planId, string $bisDatum, bool $belegteErsetzen = false): int
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);
        if ($plan->start_date === null) {
            return 0;
        }
        $start = $plan->start_date->copy()->startOfDay();
        $bis = Carbon::parse($bisDatum)->startOfDay();
        $blockTage = max(1, (int) $plan->cycle_weeks) * 7;
        $blockEnde = $start->copy()->addDays($blockTage - 1);

        $basis = $plan->entries->filter(fn ($e) => $e->entry_date !== null && $e->entry_date->between($start, $blockEnde));
        if ($basis->isEmpty()) {
            return 0;
        }

        // Belegte Zellen außerhalb des Vorlage-Blocks (nur dort wird ausgerollt).
        $belegt = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date !== null && ! $e->entry_date->between($start, $blockEnde)) {
                $belegt[$e->entry_date->format('Y-m-d') . '|' . $e->meal . '|' . (int) $e->line_id][] = $e;
            }
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($plan, $basis, $start, $bis, $blockTage, $belegt, $belegteErsetzen) {
            $neu = 0;
            $geleert = [];
            for ($k = 1; $k <= 520; $k++) {           // Sicherheitsdeckel ~10 Jahre
                $offset = $k * $blockTage;
                if ($start->copy()->addDays($offset)->gt($bis)) {
                    break;
                }
                foreach ($basis as $e) {
                    $ziel = $e->entry_date->copy()->addDays($offset);
                    if ($ziel->gt($bis)) {
                        continue;
                    }
                    $zelle = $ziel->format('Y-m-d') . '|' . $e->meal . '|' . (int) $e->line_id;
                    if (isset($belegt[$zelle])) {
                        if (! $belegteErsetzen) {
                            continue;
                        }
                        if (! isset($geleert[$zelle])) {
                            foreach ($belegt[$zelle] as $alt) {
                                $alt->delete();
                            }
                            $geleert[$zelle] = true;
                        }
                    }
                    $plan->entries()->create([
                        'team_id' => $plan->team_id, 'entry_date' => $ziel->format('Y-m-d'),
                        'week' => 1, 'weekday' => (int) $ziel->isoWeekday(), 'meal' => $e->meal,
                        'line_id' => $e->line_id, 'concept_id' => $e->concept_id, 'package_id' => $e->package_id,
                        'sales_recipe_id' => $e->sales_recipe_id, 'position' => $e->position,
                        'pax' => $e->pax,
                    ]);
                    $neu++;
                }
            }

            return $neu;
        });
    }

    private function guard(FoodAlchemistSpeiseplan $plan, Team $team): void
    {
        if (! $plan->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Speiseplan: Ändern kann ihn nur das Besitzer-Team.');
        }
    }

    // ── Spec 43: Branding (neu für Speiseplan; gespiegelt aus FoodbookService) ──

    public function setBranding(Team $team, int $planId, array $in): FoodAlchemistSpeiseplan
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);

        $daten = [];
        if (array_key_exists('brand_color', $in)) {
            $daten['brand_color'] = $this->normHexOderThrow($in['brand_color'], 'Markenfarbe') ?? '#6d28d9';
        }
        if (array_key_exists('band_color', $in)) {
            $daten['band_color'] = $this->normHexOderThrow($in['band_color'], 'Bandfarbe', true);
        }
        if (array_key_exists('footer_text', $in)) {
            $t = trim((string) $in['footer_text']);
            $daten['footer_text'] = $t !== '' ? $t : null;
        }
        if ($daten !== []) {
            $plan->update($daten);
        }

        return $plan->refresh();
    }

    public function storeLogo(Team $team, int $planId, UploadedFile $file): string
    {
        return $this->speichereBrandingBild($team, $planId, $file, 'logo_path');
    }

    public function storeCover(Team $team, int $planId, UploadedFile $file): string
    {
        return $this->speichereBrandingBild($team, $planId, $file, 'cover_image_path');
    }

    public function clearLogo(Team $team, int $planId): FoodAlchemistSpeiseplan
    {
        return $this->loescheBrandingBild($team, $planId, 'logo_path');
    }

    public function clearCover(Team $team, int $planId): FoodAlchemistSpeiseplan
    {
        return $this->loescheBrandingBild($team, $planId, 'cover_image_path');
    }

    private function speichereBrandingBild(Team $team, int $planId, UploadedFile $file, string $spalte): string
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);
        $contextSpalte = $spalte === 'logo_path' ? 'logo_context_file_id' : 'cover_context_file_id';
        app(FoodAlchemistMediaService::class)->delete($plan->{$contextSpalte}, (string) $plan->{$spalte}, $team);

        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $file, $team, 'foodalchemist.speiseplan', $planId, "foodalchemist/branding/speiseplan/{$planId}",
        );
        $plan->update([$spalte => $media['path'], $contextSpalte => $media['context_file_id']]);

        return $media['path'];
    }

    private function loescheBrandingBild(Team $team, int $planId, string $spalte): FoodAlchemistSpeiseplan
    {
        $plan = FoodAlchemistSpeiseplan::visibleToTeam($team)->findOrFail($planId);
        $this->guard($plan, $team);
        $contextSpalte = $spalte === 'logo_path' ? 'logo_context_file_id' : 'cover_context_file_id';
        app(FoodAlchemistMediaService::class)->delete($plan->{$contextSpalte}, (string) $plan->{$spalte}, $team);
        $plan->update([$spalte => null, $contextSpalte => null]);

        return $plan->refresh();
    }

    private function normHexOderThrow($wert, string $feld, bool $erlaubeLeer = false): ?string
    {
        $wert = trim((string) $wert);
        if ($wert === '') {
            if ($erlaubeLeer) {
                return null;
            }
            throw new \RuntimeException("{$feld} fehlt.");
        }
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $wert)) {
            throw new \RuntimeException("{$feld}: ungültiger Farbwert „{$wert}“ (erwartet #RRGGBB).");
        }

        return $wert;
    }
}
