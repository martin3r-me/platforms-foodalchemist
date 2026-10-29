<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Support\StammPaket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Support\DeklarationsProfil;
use Platform\FoodAlchemist\Models\FoodAlchemistGpLaPreference;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;

/**
 * M3-06: Lead-LA-Wahl (GL-03) + V-27-Auflösung.
 *
 * Kaskade T1 als SORTIER-Kriterien (I3 — keine Filter; auch ein discontinued LA
 * ohne Preis wird Lead, wenn es keinen besseren gibt):
 *  -1 Lieferant bestellbar (inaktiver/gesperrter Lieferant nie vor einem aktiven; nur Ausweich, wenn es sonst nichts gibt, und dann gekennzeichnet)
 *   0 Strategie-Stufe (M1-05: stamm_lieferant | guenstigster_preis | prioritaets_kette)
 *   1 Aktivität (aktiv vor discontinued)
 *   2 hat aktiven Preis (GL-11: standard_ek | aktion)
 *   3 Vergleichspreis — NULL ans ENDE (A-2 NULLS LAST, PHP-seitig ⇒ engine-agnostisch)
 *   4 Lieferantenname alphabetisch
 *   5 supplier_item_id ASC (I1 Determinismus)
 *
 * V-27: EIN globaler Default-Lead (gps.lead_la_supplier_item_id); Team-Sperren/Pins
 * leben im Overlay (gp_la_preferences). Effektiver Lead = Pin, sonst erster nicht
 * gesperrter Rang. Pins überleben Bulk-Neuwahl (Bulk schreibt nur die globale Spalte).
 * A-3 (Einheiten-Mix €/kg vs €/l vs €/Stk im Preisvergleich) bleibt Ist-konform —
 * dokumentierte Abweichung, Normalisierung ist GL-11-Folgearbeit.
 */
class LeadLaService
{
    public function __construct(
        private TeamSettingsService $settings,
        private StammLieferantService $stamm,
        private PriceService $preise,
    ) {
    }

    /**
     * Vollständige Rangliste (V-27-Kette): Rang 1 = Lead-Kandidat, Rest = Ausweich-LAs.
     * Jeder Eintrag trägt vergleichspreis/ist_stamm/locked/gepinnt fürs Panel (M3-07).
     *
     * Spec 20 · E3: $strategieOverride (z. B. je Bestellschiene) gewinnt vor der Team-Einstellung;
     * NULL = Team-Haupteinstellung (mit WG-Override). Sortier-Stufen sonst unverändert.
     *
     * @return Collection<int, FoodAlchemistSupplierItem>
     */
    public function rangliste(FoodAlchemistGp $gp, Team $team, ?LeadLaStrategie $strategieOverride = null, bool $teamPreise = false): Collection
    {
        $kandidaten = $this->kandidaten($gp, $teamPreise ? $team : null);
        if ($kandidaten->isEmpty()) {
            return $kandidaten;
        }

        $strategie = $strategieOverride ?? $this->settings->leadLaStrategie($team, $gp->commodity_group_code); // E3-Override vor Phase-3-WG vor Global
        $prioritaeten = array_map('intval', $this->settings->leadLaPrioritaeten($team));
        $stammIds = array_map('intval', $this->stamm->stammSupplierIdsFor($team, $gp->commodity_group_code));
        $overlay = collect(\Platform\FoodAlchemist\Support\LeadOverlay::fuer($team, [(int) $gp->id])[(int) $gp->id] ?? []);   // Spec 85 PR-B: inkl. Übernahme vom Hauptteam

        return $this->sortiere($kandidaten, $strategie, $prioritaeten, $stammIds, $overlay);
    }

    /**
     * #6.3 N+1: Ranglisten fuer VIELE GPs mit vorgeladenen Daten (Controlling-Preisvergleich, bis 60
     * GPs). Vorher rief das Panel {@see rangliste()} je GP → ~3 Queries pro GP (Kandidaten + Overlay
     * + Stamm). Hier: Kandidaten UND Overlay je in EINEM whereIn, Strategie/Stamm je DISTINCT WG
     * gecacht; die Sortierung ist bit-identisch zu rangliste() (geteilte {@see sortiere()}).
     *
     * @param  Collection<int, FoodAlchemistGp>  $gps
     * @return array<int, Collection<int, FoodAlchemistSupplierItem>>  gp_id ⇒ Rangliste
     */
    public function ranglisteBulk(Collection $gps, Team $team, bool $teamPreise = false): array
    {
        $gpIds = $gps->map(fn ($g) => (int) $g->id)->values()->all();
        if ($gpIds === []) {
            return [];
        }

        $kandidatenByGp = $this->kandidatenBulk($gpIds, $teamPreise ? $team : null);
        $overlayByGp = \Platform\FoodAlchemist\Support\LeadOverlay::fuer($team, $gpIds);   // Spec 85 PR-B: inkl. Übernahme vom Hauptteam
        $prioritaeten = array_map('intval', $this->settings->leadLaPrioritaeten($team));
        $strategieByWg = [];
        $stammByWg = [];

        $out = [];
        foreach ($gps as $gp) {
            $wg = (string) $gp->commodity_group_code;
            $strategieByWg[$wg] ??= $this->settings->leadLaStrategie($team, $wg);
            $stammByWg[$wg] ??= array_map('intval', $this->stamm->stammSupplierIdsFor($team, $wg));
            $kandidaten = $kandidatenByGp[(int) $gp->id] ?? collect();
            if ($kandidaten->isEmpty()) {
                $out[(int) $gp->id] = $kandidaten;
                continue;
            }
            $overlay = collect($overlayByGp[(int) $gp->id] ?? []);
            $out[(int) $gp->id] = $this->sortiere($kandidaten, $strategieByWg[$wg], $prioritaeten, $stammByWg[$wg], $overlay);
        }

        return $out;
    }

    /**
     * Geteilte Sortier-Logik (V-27-Kette) — aus rangliste() extrahiert, damit Einzel- und Bulk-Pfad
     * bit-identisch sortieren. Setzt ist_stamm/locked/gepinnt aus dem Overlay, sortiert nach den
     * Stufen 0–5.
     *
     * @param  Collection<int, FoodAlchemistSupplierItem>  $kandidaten
     * @param  array<int, int>  $prioritaeten
     * @param  array<int, int>  $stammIds
     * @param  Collection  $overlay  supplier_item_id ⇒ ['gepinnt' => bool, 'locked' => bool] ({@see \Platform\FoodAlchemist\Support\LeadOverlay})
     * @return Collection<int, FoodAlchemistSupplierItem>
     */
    private function sortiere(Collection $kandidaten, LeadLaStrategie $strategie, array $prioritaeten, array $stammIds, Collection $overlay): Collection
    {
        return $kandidaten
            ->each(function ($la) use ($overlay, $stammIds) {
                $pref = $overlay->get($la->id);
                $la->setAttribute('ist_stamm', in_array((int) $la->supplier_id, $stammIds, true));
                $la->setAttribute('locked', (bool) ($pref['locked'] ?? false));
                $la->setAttribute('gepinnt', (bool) ($pref['gepinnt'] ?? false));
            })
            ->sortBy(fn ($la) => [
                $la->lieferant_inaktiv ? 1 : 0,                            // Stufe -1: inaktiver/gesperrter Lieferant nie vor einem aktiven (nur Ausweich, wenn es sonst nichts gibt)
                match ($strategie) {                                       // Stufe 0 (V-27-Strategie)
                    LeadLaStrategie::GuenstigsterPreis => 0,
                    LeadLaStrategie::StammLieferant => $la->ist_stamm ? 0 : 1,
                    LeadLaStrategie::PrioritaetsKette => ($pos = array_search((int) $la->supplier_id, $prioritaeten, true)) !== false ? $pos : PHP_INT_MAX,
                },
                $la->is_discontinued ? 1 : 0,                              // Stufe 1
                $la->hat_aktiven_preis ? 0 : ($la->hat_letzten_preis ? 1 : 2),   // Stufe 2: aktiv vor letztem Preis (eingestellt) vor ohne (27.10.)
                $la->vergleichspreis_wert === null ? 1 : 0,                // Stufe 3: NULLS LAST (A-2!)
                $la->vergleichspreis_wert ?? PHP_FLOAT_MAX,
                mb_strtolower((string) $la->supplier_name),                // Stufe 4
                (int) $la->id,                                             // Stufe 5 (I1)
            ])
            ->values();
    }

    /** Heuristik-Sieger (Rang 1) — ohne Overlay; NULL bei Phantom-GP (GT-3). */
    public function pickLeadLa(FoodAlchemistGp $gp, Team $team): ?int
    {
        return $this->rangliste($gp, $team)->first()?->id;
    }

    /**
     * Schreibt den GLOBALEN Default-Lead (Arbeits-Annahme ⚠D1: EIN globaler Lead,
     * berechnet aus Sicht des kuratierenden Teams; Team-Abweichungen nur im Overlay).
     */
    public function applyLeadLa(FoodAlchemistGp $gp, Team $team): ?int
    {
        $lead = $this->pickLeadLa($gp, $team);
        $gp->update(['lead_la_supplier_item_id' => $lead]);

        return $lead;
    }

    /**
     * Lead-Neuwahl mit Vorschau (2026-10-05): Matrix-/Strategie-Änderungen wählen KEINEN gespeicherten
     * Lead neu — das passiert nur hier, bewusst ausgelöst. Kandidaten = GPs, die dem Team GEHÖREN
     * (der Lead ist global, nur der Katalog-Besitzer wählt ihn, D1), approved + requires_la.
     *
     * Kein Wechsel, wenn
     *  - der neue Rang-1 schon der Lead ist,
     *  - der neue Rang-1 keinen aktiven Preis hat (Regel aus LeadLaRepickCommand),
     *  - der aktuelle Lead manuell gesetzt wurde (Begründung auf gp_la_preferences, s. setLeadLa).
     * Team-Pins/-Sperren leben im Overlay und bleiben unberührt.
     *
     * @return array{geprueft: int, unveraendert: int, ohne_preis: int, manuell_geschuetzt: int,
     *               wechsel: list<array<string, mixed>>}
     */
    public function repickVorschau(Team $team, ?string $warengruppeCode = null, ?array $nurGpIds = null, bool $nurInaktiveLieferanten = false): array
    {
        $out = ['geprueft' => 0, 'unveraendert' => 0, 'ohne_preis' => 0, 'manuell_geschuetzt' => 0, 'kein_aktiver_ersatz' => 0, 'wechsel' => []];

        FoodAlchemistGp::where('team_id', $team->id)
            ->where('status', 'approved')->where('requires_la', true)
            ->when($warengruppeCode !== null && $warengruppeCode !== '', fn ($q) => $q->where('commodity_group_code', $warengruppeCode))
            ->when($nurGpIds !== null, fn ($q) => $q->whereIn('id', $nurGpIds))
            ->when($nurInaktiveLieferanten, fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')   // nur GPs, deren Lead bei einem inaktiven/gesperrten Lieferanten liegt
                ->from('foodalchemist_supplier_items AS lead_si')->join('foodalchemist_suppliers AS sup', 'sup.id', '=', 'lead_si.supplier_id')
                ->whereColumn('lead_si.id', 'foodalchemist_gps.lead_la_supplier_item_id')->whereRaw(FoodAlchemistSupplier::nichtBestellbarSql())))
            ->chunkById(200, function ($gps) use ($team, &$out, $nurInaktiveLieferanten) {
                $ranglisten = $this->ranglisteBulk($gps, $team);
                $manuell = FoodAlchemistGpLaPreference::whereIn('gp_id', $gps->pluck('id'))
                    ->whereNotNull('reason')->get(['gp_id', 'supplier_item_id'])
                    ->map(fn ($p) => $p->gp_id . ':' . $p->supplier_item_id)->flip();

                foreach ($gps as $gp) {
                    $out['geprueft']++;
                    $kette = $ranglisten[(int) $gp->id] ?? collect();
                    $neu = $kette->first();
                    $alt = $gp->lead_la_supplier_item_id !== null ? (int) $gp->lead_la_supplier_item_id : null;
                    if ($nurInaktiveLieferanten && $neu !== null && $neu->lieferant_inaktiv) {   // auch Rang 1 ist nicht bestellbar: es gibt keinen aktiven Ersatz
                        $out['kein_aktiver_ersatz']++;
                        continue;
                    }
                    if ($neu === null || (int) $neu->id === $alt) {
                        $out['unveraendert']++;
                        continue;
                    }
                    if (! $neu->hat_aktiven_preis && ! $neu->hat_letzten_preis) {   // letzter Preis eines eingestellten Artikels zählt als Preis (27.10.), gekennzeichnet
                        $out['ohne_preis']++;
                        continue;
                    }
                    if ($alt !== null && $manuell->has($gp->id . ':' . $alt)) {
                        $out['manuell_geschuetzt']++;
                        continue;
                    }
                    $altLa = $alt !== null ? $kette->firstWhere('id', $alt) : null;
                    $out['wechsel'][] = [
                        'gp_id' => (int) $gp->id,
                        'gp' => $gp->name,
                        'warengruppe' => $gp->commodity_group_code,
                        'alt_la_id' => $alt,
                        'alt_lieferant' => $altLa?->supplier_name,
                        'alt_vergleichspreis' => $altLa?->vergleichspreis_wert,
                        'neu_la_id' => (int) $neu->id,
                        'neu_lieferant' => $neu->supplier_name,
                        'neu_vergleichspreis' => $neu->vergleichspreis_wert,
                        'neu_ist_stamm' => (bool) $neu->ist_stamm,
                        'neu_preis_quelle' => $neu->preis_quelle,
                        'alt_lieferant_inaktiv' => (bool) ($altLa?->lieferant_inaktiv ?? false),
                    ];
                }
            });

        if ($out['wechsel'] !== []) {
            $nutzer = DB::table('foodalchemist_recipe_ingredients')
                ->whereIn('gp_id', array_column($out['wechsel'], 'gp_id'))->whereNull('deleted_at')
                ->selectRaw('gp_id, COUNT(DISTINCT recipe_id) AS n')->groupBy('gp_id')->pluck('n', 'gp_id');
            foreach ($out['wechsel'] as &$w) {
                $w['rezepte'] = (int) ($nutzer[$w['gp_id']] ?? 0);
            }
            unset($w);
        }

        return $out;
    }

    /**
     * Übernimmt die Wechsel aus {@see repickVorschau()} für die gewählten GPs. Die Vorschau wird
     * dafür NEU gerechnet (keine Client-Daten vertrauen; was seither manuell gesetzt wurde, bleibt).
     * Danach EIN Recompute über alle nutzenden Rezepte (V-049).
     *
     * @param  list<int>  $gpIds
     * @return array{umgestellt: int, rezepte: int}
     */
    public function repickAnwenden(Team $team, array $gpIds): array
    {
        $gpIds = array_values(array_unique(array_map('intval', $gpIds)));
        if ($gpIds === []) {
            return ['umgestellt' => 0, 'rezepte' => 0];
        }
        $wechsel = $this->repickVorschau($team, null, $gpIds)['wechsel'];
        foreach ($wechsel as $w) {
            FoodAlchemistGp::whereKey($w['gp_id'])->update(['lead_la_supplier_item_id' => $w['neu_la_id']]);
        }
        $rezepte = $this->recomputeNutzerFuerGps(array_column($wechsel, 'gp_id'));

        return ['umgestellt' => count($wechsel), 'rezepte' => $rezepte];
    }

    /**
     * Effektiver Lead des Teams (V-27): Pin gewinnt (sofern verknüpft + nicht locked),
     * sonst erster nicht gesperrter Rang der Kette. Spec 20 · E3: optionaler
     * Strategie-Override (je Schiene) — der Pin bleibt aber ein harter Team-Override.
     */
    public function effektiverLead(FoodAlchemistGp $gp, Team $team, ?LeadLaStrategie $strategieOverride = null): ?FoodAlchemistSupplierItem
    {
        $kette = $this->rangliste($gp, $team, $strategieOverride);

        return $kette->first(fn ($la) => $la->gepinnt && ! $la->locked)
            ?? $kette->first(fn ($la) => ! $la->locked);
    }

    /**
     * Manueller Override des globalen Leads (GT-6): LA muss zum GP gehören (I2), NULL erlaubt.
     *
     * R9.2 (E5): optionale $reason wird auf der gp_la_preferences-Zeile (team,gp,laId)
     * vermerkt → Override-Historie fällt über LogsActivity ab. $recompute=true rechnet
     * die GP-nutzenden Rezepte neu (neuer Lead ⇒ neuer EK); Default false hält die zwei
     * bestehenden UI-Caller verhaltensgleich (Bulk-Recompute läuft dort separat).
     */
    public function setLeadLa(Team $team, FoodAlchemistGp $gp, ?int $laId, ?string $reason = null, bool $recompute = false): void
    {
        if ($laId !== null && ! $this->gehoertZuGp($gp, $laId)) {
            throw new \RuntimeException("Lieferantenartikel #{$laId} ist nicht mit dem Grundprodukt „{$gp->name}“ verknüpft.");
        }
        $this->pruefeLeadBesitzer($team, $gp);
        $gp->update(['lead_la_supplier_item_id' => $laId]);
        // Spec 82 3b: der globale Lead gilt für alle Teams ohne eigenen Pin → Team-Kosten aller Teams veraltet
        app(KostenCacheService::class)->markiereVeraltet(app(KostenCacheService::class)->rezepteMitGps([(int) $gp->id]), null);
        app(DarreichungService::class)->recomputeFuerZubehoerGps([(int) $gp->id]);   // Non-Food-GP als Zubehör: Darreichungen gezielt neu rechnen

        // Jeder Aufruf hier IST ein manueller Override — auch ohne Begründung. Die Zeile ist das
        // Merkmal, an dem repickVorschau() den Lead vor einer Neuwahl schützt.
        $reason ??= 'manuell gesetzt';
        if ($laId === null) {
            // Override gelöst (zurück auf Heuristik): das Merkmal am bisherigen Lead mit entfernen,
            // sonst schützt repickVorschau() später einen Lead, den niemand mehr festhält.
            FoodAlchemistGpLaPreference::where('team_id', $team->id)->where('gp_id', $gp->id)
                ->whereNotNull('reason')->update(['reason' => null]);
        } else {
            FoodAlchemistGpLaPreference::withTrashed()->updateOrCreate(
                ['team_id' => $team->id, 'gp_id' => $gp->id, 'supplier_item_id' => $laId],
                ['reason' => $reason, 'deleted_at' => null],   // LogsActivity → Historie
            );
        }

        if ($recompute) {
            $this->recomputeGpNutzer($gp);
        }
    }

    /**
     * Darf dieses Team den globalen Lead des GP setzen? Besitzer (`isOwnedBy`: eigenes Team, Master bei globalen stamm_paket-Zeilen)
     * und — nur für den Lead — der Master auch bei globalen BASIS-GPs (Dominique 18.10.: „Global ist Master-Team, ich muss sie
     * irgendwo einstellen und pflegen“). Mandanten nie. Bewusst hier statt in `isOwnedBy`/`Curate`: die übrige Pflege globaler
     * Basis-GPs bleibt unverändert. Grundlage auch für PR-B (wer den globalen Lead setzt, wer einen eigenen Pin).
     */
    public static function darfGlobalenLead(Team $team, FoodAlchemistGp $gp): bool
    {
        return $gp->isOwnedBy($team) || ($gp->team_id === null && \Platform\FoodAlchemist\Support\MasterTeam::istMaster($team));
    }

    /**
     * Spec 85 Controlling PR-A (Dominique 19.10.): der globale Lead gilt für alle Teams — im angemeldeten Weg (Seite, MCP, KI) setzt
     * ihn nur, wer `darfGlobalenLead` erfüllt (Besitzer; bei globalen GPs der Master), mit Rolle Kuratieren. Systemwege ohne
     * Anmeldung (Neuwahl-Job, Kommando, Migration) bleiben offen — dieselbe Linie wie `FaRechte::pruefeAngemeldet`.
     *
     * @throws \Platform\FoodAlchemist\Exceptions\LeadNurBesitzerException
     * @throws \Platform\FoodAlchemist\Exceptions\FaRechtFehltException
     */
    private function pruefeLeadBesitzer(Team $team, FoodAlchemistGp $gp): void
    {
        if (\Illuminate\Support\Facades\Auth::id() === null) {
            return;
        }
        if (! self::darfGlobalenLead($team, $gp)) {
            throw new \Platform\FoodAlchemist\Exceptions\LeadNurBesitzerException((int) $gp->id, (string) $gp->name);
        }
        app(\Platform\FoodAlchemist\Services\FaRechte::class)->pruefeAngemeldet($team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren, 'Bezugsquelle für alle setzen');
    }

    /** R9.2: GP-nutzende Rezepte neu rechnen (Direktnutzer + transitive Eltern). */
    private function recomputeGpNutzer(FoodAlchemistGp $gp): void
    {
        $this->recomputeNutzerFuerGps([(int) $gp->id]);
    }

    /**
     * Dieselbe Kaskade für eine GANZE GP-Menge — ein Lauf über die Vereinigung.
     *
     * Spec 32: die Batch-Umstellung im Controlling-Zentrum stellt N Bezugsquellen auf einmal um.
     * Würde sie N-mal {@see self::setLeadLa} mit `recompute: true` rufen, liefe die teure
     * Rezeptbaum-Kaskade N-mal über weitgehend DIESELBEN Eltern-Gerichte. Der Aufrufer setzt
     * darum ohne Recompute und ruft am Ende einmal hier her.
     *
     * V-049 gilt auf beiden Ebenen: eine Menge, ein Lauf.
     *
     * @param  list<int>  $gpIds
     * @return int Anzahl neu gerechneter Rezepte (inkl. transitiver Eltern)
     */
    public function recomputeNutzerFuerGps(array $gpIds): int
    {
        $gpIds = array_values(array_unique(array_map('intval', $gpIds)));
        if ($gpIds === []) {
            return 0;
        }

        $recipeIds = DB::table('foodalchemist_recipe_ingredients')
            ->whereIn('gp_id', $gpIds)->whereNull('deleted_at')->distinct()->pluck('recipe_id');
        if ($recipeIds->isEmpty()) {
            return 0;
        }

        return count(app(RecipeRecomputeService::class)->recomputeMany($recipeIds->all()));
    }

    /**
     * R9.2 — bediente Lead-Steuerung für einen GP: aktueller Lead (effektiv + global
     * gesetzt), Heuristik-Vorschlag (pickLeadLa), Ausweichquellen (Rangliste ab Rang 2,
     * nicht gesperrt) + Override-Begründung (falls vermerkt).
     */
    public function leadSteuerung(FoodAlchemistGp $gp, Team $team): array
    {
        $kette = $this->rangliste($gp, $team);
        $effektiv = $this->effektiverLead($gp, $team);
        $vorschlag = $kette->first()?->id;
        $gesetzt = $gp->lead_la_supplier_item_id !== null ? (int) $gp->lead_la_supplier_item_id : null;

        $reason = $gesetzt !== null
            ? FoodAlchemistGpLaPreference::where('team_id', $team->id)->where('gp_id', $gp->id)
                ->where('supplier_item_id', $gesetzt)->value('reason')
            : null;

        $map = fn ($la) => [
            'la_id' => (int) $la->id,
            'supplier' => $la->supplier_name,
            'vergleichspreis' => $la->vergleichspreis_wert,
            // Rückfall 27.10.: Quelle des Preises (aktiv | letzter_eingestellt) samt Datum — der letzte Preis ist nie ein normaler Preis
            'preis_quelle' => $la->preis_quelle,
            'letzter_preis_ab' => $la->hat_letzten_preis ? $la->letzter_preis_ab : null,
            'eingestellt_seit' => $la->hat_letzten_preis ? $la->lieferstatus_seit : null,
            'ist_stamm' => (bool) $la->ist_stamm,
            'locked' => (bool) $la->locked,
            'gepinnt' => (bool) $la->gepinnt,
        ];

        return [
            'gp_id' => (int) $gp->id,
            'name' => $gp->name,
            'lead_gesetzt_la_id' => $gesetzt,
            'lead_effektiv_la_id' => $effektiv?->id !== null ? (int) $effektiv->id : null,
            'vorschlag_la_id' => $vorschlag !== null ? (int) $vorschlag : null,
            'override_reason' => $reason,
            'lead' => $kette->first() !== null ? $map($kette->first()) : null,
            'ausweichquellen' => $kette->slice(1)->filter(fn ($la) => ! $la->locked)->map($map)->values()->all(),
        ];
    }

    /** Team-Sperre (V-27): LA fällt aus der effektiven Kette dieses Teams. */
    public function sperren(Team $team, FoodAlchemistGp $gp, int $laId, bool $locked = true): void
    {
        if (! $this->gehoertZuGp($gp, $laId)) {
            throw new \RuntimeException("Lieferantenartikel #{$laId} ist nicht mit dem Grundprodukt „{$gp->name}“ verknüpft.");
        }
        FoodAlchemistGpLaPreference::withTrashed()
            ->updateOrCreate(
                ['team_id' => $team->id, 'gp_id' => $gp->id, 'supplier_item_id' => $laId],
                ['locked' => $locked, 'deleted_at' => null],
            );
        $this->raeumeLeerePrefsAuf($team, $gp);
        app(KostenCacheService::class)->nachPinAenderung((int) $gp->id, $team);   // Spec 82 3b: Auslöser Pin/Lock
    }

    /**
     * Spec 85 PR-B (Dominique 19.10.): „Bezugsquelle setzen“ aus dem Controlling. Wer den globalen Lead setzen darf
     * ({@see darfGlobalenLead}: Besitzer, bei globalen GPs der Master), setzt ihn für alle; jedes andere Team legt seinen
     * Leitartikel als Pin fest — nur einen für das Team sichtbaren Artikel, Rolle Kuratieren. Ohne Pin gilt der Plattform-Lead.
     *
     * @return 'global'|'pin'
     */
    public function setzeLeitartikel(Team $team, FoodAlchemistGp $gp, int $laId, ?string $reason = null, bool $recompute = true): string
    {
        if (self::darfGlobalenLead($team, $gp)) {
            $this->setLeadLa($team, $gp, $laId, $reason, $recompute);

            return 'global';
        }
        app(\Platform\FoodAlchemist\Services\FaRechte::class)->pruefeAngemeldet($team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren, 'Leitartikel festlegen');
        if (! FoodAlchemistSupplierItem::visibleToTeam($team)->whereKey($laId)->exists()) {
            throw new \RuntimeException('Diesen Artikel kann euer Team nicht beziehen (Lieferant nicht freigeschaltet).');
        }
        $this->pinnen($team, $gp, $laId);

        return 'pin';
    }

    /** Team-Pin (V-27): fixiert den effektiven Lead, überlebt Bulk-Neuwahl. NULL = Pin lösen. */
    public function pinnen(Team $team, FoodAlchemistGp $gp, ?int $laId): void
    {
        if ($laId !== null && ! $this->gehoertZuGp($gp, $laId)) {
            throw new \RuntimeException("Lieferantenartikel #{$laId} ist nicht mit dem Grundprodukt „{$gp->name}“ verknüpft.");
        }

        DB::transaction(function () use ($team, $gp, $laId) {
            FoodAlchemistGpLaPreference::where('team_id', $team->id)->where('gp_id', $gp->id)
                ->where('gepinnt', true)->update(['gepinnt' => false]);     // max. ein Pin pro GP/Team
            if ($laId !== null) {
                FoodAlchemistGpLaPreference::withTrashed()->updateOrCreate(
                    ['team_id' => $team->id, 'gp_id' => $gp->id, 'supplier_item_id' => $laId],
                    ['gepinnt' => true, 'deleted_at' => null],
                );
            }
        });
        $this->raeumeLeerePrefsAuf($team, $gp);
        app(KostenCacheService::class)->nachPinAenderung((int) $gp->id, $team);   // Spec 82 3b: Auslöser Pin
    }

    /**
     * LA↔GP-Verknüpfung lösen (GT-5): Struktur-Mapping weg, n_las_total runter;
     * war der LA der globale Lead ⇒ sofortige Neuwahl (I4).
     */
    public function entknuepfen(Team $team, FoodAlchemistGp $gp, int $laId): void
    {
        $struktur = DB::table('foodalchemist_supplier_item_structures')
            ->where('gp_id', $gp->id)->where('supplier_item_id', $laId)->whereNull('deleted_at')->first();
        if ($struktur === null) {
            throw new \RuntimeException("Lieferantenartikel #{$laId} ist nicht mit dem Grundprodukt „{$gp->name}“ verknüpft.");
        }

        DB::transaction(function () use ($gp, $laId, $struktur, $team) {
            DB::table('foodalchemist_supplier_item_structures')->where('id', $struktur->id)
                ->update(['gp_id' => null, 'updated_at' => now()]);
            $gp->update(['n_las_total' => max(0, (int) $gp->n_las_total - 1)]);

            if ((int) $gp->lead_la_supplier_item_id === $laId) {
                $this->applyLeadLa($gp->refresh(), $team);                 // I4: sofortige Neuwahl
            }
        });
    }

    /** LA an GP verknüpfen (Gegenstück, M3-07-Aktion): Struktur-Update + Lead-Neuwahl-Trigger (T3). */
    public function verknuepfen(Team $team, FoodAlchemistGp $gp, int $laId): void
    {
        DB::transaction(function () use ($gp, $laId, $team) {
            // Unique-Index auf supplier_item_id filtert NICHT nach deleted_at ⇒ evtl. Soft-Delete-
            // Leiche mitziehen, sonst kracht ein Neu-Insert. Gros der LAs (aus WaWi-Sync) hat noch
            // GAR keine Struktur-Zeile — Verknüpfen legt sie dann an, statt zu scheitern (GL-05:
            // das Mapping darf die Struktur erzeugen).
            $struktur = FoodAlchemistSupplierItemStructure::withTrashed()
                ->where('supplier_item_id', $laId)->first();

            if ($struktur !== null && $struktur->deleted_at === null
                && $struktur->gp_id !== null && (int) $struktur->gp_id !== $gp->id) {
                throw new \RuntimeException('Der Lieferantenartikel ist bereits einem anderen Grundprodukt zugeordnet. Zuerst dort lösen.');
            }

            if ($struktur !== null && $struktur->deleted_at === null && (int) $struktur->gp_id === $gp->id) {
                return; // idempotent: Zähler und Lead nicht erneut verändern
            }

            $this->guardDeklarationsprofil($team, $gp, $laId);

            if ($struktur === null) {
                $struktur = new FoodAlchemistSupplierItemStructure(['supplier_item_id' => $laId]);
            }
            $struktur->team_id ??= $team->id;
            $struktur->gp_id = $gp->id;
            $struktur->deleted_at = null;                                  // evtl. Leiche reaktivieren
            $struktur->save();

            $gp->update(['n_las_total' => (int) $gp->n_las_total + 1]);
            $this->applyLeadLa($gp->refresh(), $team);                     // T3: Verknüpfen triggert Neuwahl
        });
    }

    /** M3-07-Picker: verknüpfbare LAs (Struktur-Zeile mit gp_id NULL), Suche über designation. */
    /**
     * R12 (Jarvis «✨ KI-Vorschlag» am GP): unverknüpfte LA-Kandidaten — deterministisch
     * (kein LLM). Pflicht: das ERSTE GP-Namens-Token (= Hauptzutat, steht vorne) muss als
     * ganzes Wort im LA-Namen stehen («burger» trifft NICHT «Hamburgerblätter»). Score =
     * Anteil aller Tokens mit Wort-Treffer, sortiert nach Score + Name.
     */
    public function kandidatenFuerGp(Team $team, FoodAlchemistGp $gp, int $limit = 6): Collection
    {
        // Derselbe Recall-Pfad wie in der Leitstelle: WG-Lead-Scope + Terminologie +
        // Qdrant/semantischer Recall. Gerade fremdsprachige Artikelnamen werden durch
        // die alte reine Wortsuche sonst unsichtbar.
        $leitstelle = app(LaCandidateFinder::class)
            ->find($team, $gp->name, $gp->commodity_group_code, max($limit * 2, 12))
            ->filter(fn ($la) => $la->structure?->gp_id === null)
            ->map(function ($la) {
                $la->setAttribute('supplier_name', $la->supplier?->name);
                $la->setAttribute('match_score', (float) ($la->score ?? 0));
                return $la;
            });

        $basis = mb_strtolower(trim(explode(':', $gp->name)[0]));   // «Aepfel Braeburn: frisch» → «aepfel braeburn»
        $tokens = array_values(array_filter(preg_split('/[\s\/,-]+/', $basis), fn ($t) => mb_strlen($t) >= 3));
        if ($tokens === []) {
            return $leitstelle->take($limit)->values();
        }
        // GP-Namen stehen im Singular (§6.1 «Schalotte»), Artikel im Plural («SCHALOTTEN RUND KG»): Beugungsendung zulassen, sonst fielen genau die
        // Rohware-Artikel durch und es blieben die Fertigprodukte mit dem Singular im Namen («Chutney Schalotte-Birne»).
        $alsWort = fn (string $name, string $token): bool => (bool) preg_match('/\b' . preg_quote($token, '/') . '(?:n|en|s|e|er)?\b/u', $name);

        $query = FoodAlchemistSupplierItem::query()
            ->visibleToTeam($team)                                     // D1: globaler Seed + eigenes Team/Master-Kette
            ->leftJoin('foodalchemist_supplier_item_structures AS s', function ($join) {
                $join->on('s.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
                    ->whereNull('s.deleted_at');
            })
            ->leftJoin('foodalchemist_suppliers AS sup', 'sup.id', '=', 'foodalchemist_supplier_items.supplier_id')
            ->whereNull('s.gp_id')
            // Entscheid Dominique 16.10.2026: von Necta eingestellte Artikel (nicht mehr bestellbar) nicht vorschlagen
            ->lieferbar()
            ->whereRaw('LOWER(foodalchemist_supplier_items.designation) LIKE ?', ['%' . $tokens[0] . '%'])
            ->limit(200)
            ->select('foodalchemist_supplier_items.*', 'sup.name AS supplier_name')
            ->get();

        $lexikalisch = $query
            ->map(function ($item) use ($tokens, $alsWort) {
                $name = mb_strtolower((string) $item->designation);
                $treffer = count(array_filter($tokens, fn ($t) => $alsWort($name, $t)));
                $item->match_score = $treffer / count($tokens);

                return $item;
            })
            // Pflicht: Haupttoken als Wort + mind. die Hälfte aller Tokens («Mini …»-Rauschen raus)
            ->filter(fn ($item) => $item->match_score >= 0.5 && $alsWort(mb_strtolower((string) $item->designation), $tokens[0]))
            ->sortBy([fn ($a, $b) => $b->match_score <=> $a->match_score, fn ($a, $b) => strcmp($a->designation, $b->designation)])
            ->values();

        return $leitstelle->concat($lexikalisch)->unique('id')
            ->reject(fn ($la) => $this->istVerarbeitetesProdukt($gp, (string) $la->designation))
            ->sortByDesc(fn ($la) => (float) $la->match_score)->take($limit)->values();
    }

    /**
     * Wörter, die ein Fertig-/Verarbeitungsprodukt kennzeichnen («Schalotten-Birnen-Chutney», «Frischkäsekugel m. Schalotte», «Würzkaviar»).
     * Der Namenstreffer allein gab 100 % für jeden Artikel, in dessen Name die Hauptzutat vorkommt (Dominique 29.10.: «Artikel vorschlagen» =
     * «nur Müll»). Gilt NUR, wenn der GP-Name das Wort nicht selbst führt (GP «Tomatenmark» darf Tomatenmark-Artikel, GP «Senf» keine Dressings).
     */
    private const VERARBEITET = [
        'aufstrich', 'chutney', 'kaviar', 'perlen', 'vinaigrette', 'dressing', 'pesto', 'essig', 'sauce', 'soße', 'sosse', 'marmelade',
        'konfitüre', 'konfiture', 'likör', 'liqueur', 'schnaps', 'limonade', 'getränk', 'kugel', 'dip', 'cracker', 'chips', 'riegel', 'müsli',
        'muesli', 'coupelle', 'frischkäse', 'relish', 'gelee', 'suppe', 'fertiggericht', 'tortelloni', 'ravioli', 'pizza', 'burger',
    ];

    /**
     * Passt der Artikel offensichtlich nicht zum GP: Verarbeitungs-Wort, das der GP-Name nicht trägt, oder (frischer GP) ein Artikel mit
     * Einkaufsform TK/trocken/konserviert. Bewusst nur harte Ausschlüsse — die Reihenfolge der Treffer bleibt der Score.
     */
    private function istVerarbeitetesProdukt(FoodAlchemistGp $gp, string $artikel): bool
    {
        $gpName = mb_strtolower((string) $gp->name);
        $name = mb_strtolower($artikel);
        foreach (self::VERARBEITET as $wort) {
            if (str_contains($name, $wort) && ! str_contains($gpName, $wort)) {
                return true;
            }
        }
        if (mb_strtolower((string) ($gp->condition ?? '')) === 'frisch') {
            $zustand = app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class)->produktForm($artikel)['zustand'] ?? null;
            if (in_array($zustand, ['TK', 'trocken', 'konserviert'], true)) {
                return true;
            }
        }

        return false;
    }

    public function sucheVerknuepfbare(Team $team, string $suche, int $limit = 20, ?string $warengruppe = null): Collection
    {
        return app(\Platform\FoodAlchemist\Services\Matching\VerknuepfbareArtikelSuche::class)->suche($team, $suche, $warengruppe, $limit)['treffer'];
    }

    /**
     * Ein GP ist ein deklaratorisch einheitlicher Einkaufsgegenstand. Sobald zwei
     * bekannte LA-Profile bei EU-14-Allergenen oder LMIV-Zusatzstoffen voneinander
     * abweichen, dürfen sie nicht unter demselben GP aggregiert werden. Unbekannte
     * Werte blockieren nicht; sie sind eine Datenlücke, kein belegter Widerspruch.
     * Spec 82 §2 (Dominique 13.10.): Beim Allergenvergleich zählt nur „enthalten“, Spuren zählen
     * nicht mit ({@see DeklarationsProfil}). Greift nur beim NEUEN Verknüpfen; Bestand bleibt unberührt.
     */
    private function guardDeklarationsprofil(Team $team, FoodAlchemistGp $gp, int $neuerLaId): void
    {
        $abweichungen = $this->guardAbweichungen($team, $gp, $neuerLaId);
        if ($abweichungen !== []) {
            throw new \Platform\FoodAlchemist\Exceptions\DeklarationsprofilAbweichtException($abweichungen);
        }
    }

    /**
     * Guard-VORSCHAU (Abgleich-Dienst, Entscheid Dominique 25.10.2026): dieselbe Prüfung wie beim Verknüpfen, aber ohne zu werfen und ohne
     * etwas zu schreiben. Leer = der Artikel darf an das GP; sonst die Abweichungs-Labels gegenüber dem ersten widersprechenden Bestandsartikel.
     *
     * @return list<string>
     */
    public function guardAbweichungen(Team $team, FoodAlchemistGp $gp, int $neuerLaId): array
    {
        if ($gp->istNonFood()) {
            return [];   // Non-Food nimmt nicht an der Allergen-/Deklarations-Vererbung teil (Regelwerk §8.18.4)
        }
        $neu = FoodAlchemistSupplierItem::visibleToTeam($team)->with(['allergens', 'declarations'])->findOrFail($neuerLaId);
        $bestehend = FoodAlchemistSupplierItem::query()
            ->with(['allergens', 'declarations'])
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
            ->where('s.gp_id', $gp->id)->whereNull('s.deleted_at')
            ->where('foodalchemist_supplier_items.id', '!=', $neuerLaId)
            ->select('foodalchemist_supplier_items.*');
        // Spec 82 §12 A3: die Fehlermeldung nennt Bezeichnungen — Stamm-Paket-Artikel ohne Opt-in dürfen darin nicht vorkommen.
        $bestehend = StammPaket::begrenzen($bestehend, 'foodalchemist_supplier_items', $team)->get();

        foreach ($bestehend as $alt) {
            $abweichungen = DeklarationsProfil::abweichungen($alt->allergens, $alt->declarations, $neu->allergens, $neu->declarations);
            if ($abweichungen !== []) {
                return $abweichungen;
            }
        }

        return [];
    }

    // ── intern ───────────────────────────────────────────────────────────

    /**
     * Kandidaten-Menge (I2): alle LAs mit struktur.gp_id == gp, inkl. Preis-Annotation.
     *
     * Spec 82 Stufe 3a — bewusst OHNE Team: der Lead (foodalchemist_gps.lead_la_supplier_item_id)
     * ist EIN globales Feld und wird nach dem LISTENPREIS gewählt (PriceService::preisSichtFuer
     * ohne Team). Mandantenpreise beeinflussen die Lead-Wahl nicht; ein Team weicht über Pins
     * ab (Spec 77). Ein Lead je Team nach eigenem Preis ist Stufe 3b — kein Fehler.
     */
    /**
     * @param  ?Team  $sicht  Spec 85 PR-B: Leitartikel eines Teams — nur für das Team sichtbare Artikel (Stamm-Paket-Regel) zu SEINEN
     *                        Preisen (`preisSichtFuer` bleibt Riegel). Ohne Team wie bisher: alle verknüpften Artikel zum Listenpreis
     *                        (Wahl des globalen Leads durch den Besitzer).
     */
    private function kandidaten(FoodAlchemistGp $gp, ?Team $sicht = null): Collection
    {
        $las = ($sicht !== null ? FoodAlchemistSupplierItem::visibleToTeam($sicht) : FoodAlchemistSupplierItem::query())
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
            ->leftJoin('foodalchemist_suppliers AS sup', 'sup.id', '=', 'foodalchemist_supplier_items.supplier_id')
            ->where('s.gp_id', $gp->id)
            ->whereNull('s.deleted_at')
            ->select('foodalchemist_supplier_items.*', 'sup.name AS supplier_name', DB::raw(FoodAlchemistSupplier::nichtBestellbarSql() . ' AS lieferant_inaktiv'))
            ->selectSub($this->preise->activePriceSubquery('foodalchemist_supplier_items.id', $sicht)->toBase(), 'aktiver_preis')
            ->tap(fn ($q) => $this->preise->mitLetztemPreis($q, 'foodalchemist_supplier_items.id', $sicht))   // Rückfall eingestellter Artikel (27.10.)
            ->get();

        return $las->each(fn ($la) => $this->preisAnnotieren($la));
    }

    /**
     * #6.3: Kandidaten fuer VIELE GPs in EINEM Query (whereIn s.gp_id), gruppiert je gp_id. Spiegelt
     * {@see kandidaten()} inkl. Preis-Annotation. Eine LA an mehreren GPs erscheint je Struktur-Zeile
     * (eigenes _gp_id) — genau wie der Einzel-Pfad je GP.
     *
     * @param  list<int>  $gpIds
     * @return array<int, Collection<int, FoodAlchemistSupplierItem>>
     */
    private function kandidatenBulk(array $gpIds, ?Team $sicht = null): array
    {
        $las = ($sicht !== null ? FoodAlchemistSupplierItem::visibleToTeam($sicht) : FoodAlchemistSupplierItem::query())
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
            ->leftJoin('foodalchemist_suppliers AS sup', 'sup.id', '=', 'foodalchemist_supplier_items.supplier_id')
            ->whereIn('s.gp_id', $gpIds)
            ->whereNull('s.deleted_at')
            ->select('foodalchemist_supplier_items.*', 'sup.name AS supplier_name', DB::raw(FoodAlchemistSupplier::nichtBestellbarSql() . ' AS lieferant_inaktiv'), 's.gp_id AS _gp_id')
            ->selectSub($this->preise->activePriceSubquery('foodalchemist_supplier_items.id', $sicht)->toBase(), 'aktiver_preis')
            ->tap(fn ($q) => $this->preise->mitLetztemPreis($q, 'foodalchemist_supplier_items.id', $sicht))   // Rückfall eingestellter Artikel (27.10.)
            ->get();

        $las->each(fn ($la) => $this->preisAnnotieren($la));

        return $las->groupBy(fn ($la) => (int) $la->_gp_id)->map(fn ($grp) => $grp->values())->all();
    }

    /**
     * Preis-Annotation eines Kandidaten (Einzel- und Bulk-Pfad gleich): aktiver Preis (scopeAktiv, GL-11 I3) bzw. — nur für eingestellte Artikel — der letzte
     * bekannte Preis (Dominique 27.10.). `preis_effektiv` speist den Vergleichspreis; `hat_aktiven_preis` bleibt streng, damit ein bestellbarer Artikel mit
     * aktivem Preis immer vor einem eingestellten mit letztem Preis steht (Stufe 2 in {@see sortiere}).
     */
    private function preisAnnotieren(FoodAlchemistSupplierItem $la): void
    {
        $la->setAttribute('lieferant_inaktiv', (bool) $la->lieferant_inaktiv);   // Panel-Kennzeichen „Lieferant inaktiv“
        $hatAktiven = $la->aktiver_preis !== null;
        $hatLetzten = ! $hatAktiven && $la->letzter_preis !== null;
        $effektiv = $hatAktiven ? (float) $la->aktiver_preis : ($hatLetzten ? (float) $la->letzter_preis : null);
        $la->setAttribute('hat_aktiven_preis', $hatAktiven);
        $la->setAttribute('hat_letzten_preis', $hatLetzten);
        $la->setAttribute('preis_effektiv', $effektiv);
        $la->setAttribute('preis_quelle', $hatAktiven ? 'aktiv' : ($hatLetzten ? 'letzter_eingestellt' : null));
        $vp = $effektiv !== null ? $this->preise->vergleichspreis($la, $effektiv) : null;
        $la->setAttribute('vergleichspreis', $vp);
        $la->setAttribute('vergleichspreis_wert', $vp['value'] ?? null);   // qty NULL ⇒ NULL ⇒ ans Ende (A-2)
    }

    private function gehoertZuGp(FoodAlchemistGp $gp, int $laId): bool
    {
        return DB::table('foodalchemist_supplier_item_structures')
            ->where('gp_id', $gp->id)->where('supplier_item_id', $laId)->whereNull('deleted_at')->exists();
    }

    /** Zeilen ohne Wirkung (weder Sperre noch Pin) wieder entfernen — Overlay bleibt klein. */
    private function raeumeLeerePrefsAuf(Team $team, FoodAlchemistGp $gp): void
    {
        FoodAlchemistGpLaPreference::where('team_id', $team->id)->where('gp_id', $gp->id)
            ->where('locked', false)->where('gepinnt', false)->delete();
    }
}
