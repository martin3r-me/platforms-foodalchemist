<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\AngebotStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * #380 — Kunden-Modul „Angebote": brief-getriebene, kundengebundene Instanz neben
 * Foodbook (Portfolio). Gebaut wird im Concepter — dieses Modul ist der Kunden- &
 * Vertriebs-Mantel (Anfrage-Intake, CRM-Verknüpfung, Lifecycle).
 *
 * Spiegelt ConceptService-Konventionen: visibleToTeam in JEDER Query, Schreiben
 * nur durchs Besitzer-Team (D1/Curate), team_id NOT NULL im Service.
 *
 * CRM (MVP): nur Kontakt/Firma verlinken — Lese-Picker über die CRM-Lese-Services,
 * class_exists-geschützt, damit das Modul auch ohne crm nicht bricht.
 */
class AngebotService
{
    /** Editierbare Felder (Anfrage/Briefing + kommerziell + CRM-Verknüpfung). */
    private const FELDER = [
        'name', 'status', 'occasion', 'personen', 'budget', 'event_date', 'location',
        'diet_requirement', 'brief', 'total_price', 'valid_until', 'description', 'note',
        'crm_company_id', 'crm_contact_id', 'price_mode', 'price_override_reason', 'price_override_expires_at',
    ];

    /** Leer („" / null) → NULL (optionale Zahlen/Daten/FKs). */
    private const FELDER_NULLBAR = ['personen', 'budget', 'event_date', 'valid_until', 'total_price', 'crm_company_id', 'crm_contact_id'];

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistAngebot::visibleToTeam($team)
            ->when(($filters['search'] ?? '') !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::likeAny($q, ['name', "COALESCE(occasion, '')"], $filters['search']))
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    public function detail(Team $team, int $id): ?FoodAlchemistAngebot
    {
        return FoodAlchemistAngebot::visibleToTeam($team)
            ->with([
                'crmCompany', 'crmContact',
                'concepts' => fn ($q) => $q->withCount('slots')->orderBy('name'),
                'referencedConcepts' => fn ($q) => $q->withCount('slots'),
                'pakete:id,name,offer_id',
            ])
            ->find($id);
    }

    public function create(Team $team, array $in = []): FoodAlchemistAngebot
    {
        return FoodAlchemistAngebot::create([
            'team_id' => $team->id,
            'name' => trim((string) ($in['name'] ?? 'Neue Anfrage')) ?: 'Neue Anfrage',
            'status' => $in['status'] ?? AngebotStatus::Anfrage->value,
            'occasion' => $in['occasion'] ?? null,
            'personen' => $in['personen'] ?? null,
            'created_by_user_id' => Auth::id(),
        ]);
    }

    public function update(Team $team, int $id, array $in): FoodAlchemistAngebot
    {
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($angebot, $team);

        $update = array_intersect_key($in, array_flip(self::FELDER));
        foreach (self::FELDER_NULLBAR as $feld) {
            if (array_key_exists($feld, $update) && ($update[$feld] === '' || $update[$feld] === null)) {
                $update[$feld] = null;
            }
        }
        $mode = $update['price_mode'] ?? $angebot->price_mode;
        if ($mode === 'fixed') {
            $effective = $update['total_price'] ?? $angebot->total_price;
            $reason = trim((string) ($update['price_override_reason'] ?? $angebot->price_override_reason));
            if (! is_numeric($effective) || $reason === '') {
                throw new \RuntimeException('Ein fixierter Angebotspreis benötigt Preis und Begründung.');
            }
            $update['price_override_reason'] = $reason;
            $update['price_override_user_id'] = Auth::id();
            $update['price_override_at'] = now();
        } elseif ($mode === 'auto') {
            $update += ['price_override_reason' => null, 'price_override_user_id' => null,
                'price_override_at' => null, 'price_override_expires_at' => null];
        }
        $angebot->update($update);
        $this->aktualisiereAutoPreis($team, $angebot);

        return $angebot->refresh();
    }

    public function setStatus(Team $team, int $id, string $status): FoodAlchemistAngebot
    {
        if (AngebotStatus::tryFrom($status) === null) {
            throw new \RuntimeException('Unbekannter Status.');
        }

        return $this->update($team, $id, ['status' => $status]);
    }

    /** CRM-Verknüpfung setzen/lösen (MVP: nur Firma/Kontakt verlinken). */
    public function verknuepfeKunde(Team $team, int $id, ?int $companyId, ?int $contactId): FoodAlchemistAngebot
    {
        return $this->update($team, $id, ['crm_company_id' => $companyId, 'crm_contact_id' => $contactId]);
    }

    public function delete(Team $team, int $id): void
    {
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($id);
        $this->guardOwner($angebot, $team);
        $angebot->delete();
    }

    // ── Menü-Composer: angebots-lokale Concepts (#380) ─────────────────────
    // Gebaut wird mit der Concepter-Slot-Engine (ConceptService), aber als
    // LOKALER Entwurf (offer_id gesetzt) — bleibt aus dem Katalog gefiltert.

    /** Legt einen angebots-lokalen Menü-Entwurf an (Concept mit offer_id). */
    public function neuesConcept(Team $team, int $angebotId, ?string $name = null): FoodAlchemistConcept
    {
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($angebotId);
        $this->guardOwner($angebot, $team);

        $concept = FoodAlchemistConcept::create([
            'team_id' => $team->id,
            'offer_id' => $angebot->id,
            'name' => trim((string) ($name ?? ($angebot->name . ' – Menü'))) ?: 'Menü',
            'status' => 'draft',
            'is_template' => false,
            'occasion' => $angebot->occasion,
        ]);
        // #380 Composer: der lokale Entwurf erscheint als concept_ref-Block in der Komposition.
        $this->ensureConceptBlock($team, $angebot->id, (int) $concept->id);

        return $concept;
    }

    /**
     * Idempotent: legt für ein Concept einen concept_ref-Block im Default-Kapitel des Angebots an,
     * falls noch keiner existiert. Verbindet die Menü-Anbindung (neuesConcept/referenziereConcept +
     * Voll-Kaskade) mit der Kapitel/Block-Komposition (autoritative Preis-/Anzeige-Quelle).
     */
    private function ensureConceptBlock(Team $team, int $angebotId, int $conceptId): void
    {
        $vorhanden = \Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock::where('type', 'concept_ref')
            ->where('concept_id', $conceptId)
            ->whereHas('chapter', fn ($q) => $q->where('offer_id', $angebotId))
            ->exists();
        if ($vorhanden) {
            return;
        }
        $comp = app(OfferCompositionService::class);
        $kapitel = $comp->defaultKapitel($team, $angebotId);
        $comp->addBlock($team, (int) $kapitel->id, ['type' => 'concept_ref', 'concept_id' => $conceptId]);
    }

    /**
     * „In Concepter übernehmen / live gehen" — angebots-lokalen Entwurf zum
     * standardisierten Katalog-Concept machen (offer_id → NULL). Die
     * kommerzielle Schicht bleibt am Angebot.
     */
    public function promoteConcept(Team $team, int $conceptId): FoodAlchemistConcept
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->whereNotNull('offer_id')->findOrFail($conceptId);
        if (! $concept->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Concept — Pflege nur durchs Besitzer-Team (D1).');
        }
        $angebotId = (int) $concept->offer_id;
        $concept->update(['offer_id' => null]);
        $this->recomputeAngebot($team, $angebotId);

        return $concept->refresh();
    }

    /** Entfernt einen angebots-lokalen Menü-Entwurf (nur lokale, nie Katalog-Concepts). */
    public function entferneConcept(Team $team, int $conceptId): void
    {
        $concept = FoodAlchemistConcept::visibleToTeam($team)->whereNotNull('offer_id')->findOrFail($conceptId);
        if (! $concept->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Concept — Pflege nur durchs Besitzer-Team (D1).');
        }
        $angebotId = (int) $concept->offer_id;
        $concept->delete();
        $this->recomputeAngebot($team, $angebotId);
    }

    /** Lädt das Angebot und schreibt im auto-Modus den Gesamtpreis neu (nach Menü-Änderungen). */
    public function recomputeAngebot(Team $team, int $angebotId): void
    {
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->find($angebotId);
        if ($angebot !== null) {
            $this->aktualisiereAutoPreis($team, $angebot);
        }
    }

    /** @return list<array{value:string,label:string}> */
    public function statusWerte(): array
    {
        return array_map(
            fn (AngebotStatus $s) => ['value' => $s->value, 'label' => $s->label()],
            AngebotStatus::cases()
        );
    }

    // ── #383: Pax-getriebene Kalkulation (aggregiert die Concepter-Engine) ──

    /**
     * Angebots-Kalkulation = Σ über die Menüs (angebots-lokale Concepts) der €/Person
     * via KalkulationService::conceptHk (= ConceptService::preisCockpit + Vollkosten),
     * × Pax. Plus Mengen-Hochrechnung je Gericht für die Pax. Eine Regel-Stelle — kein
     * eigenes Preismodell.
     *
     * @return array{pax:int, preis_modus:string, leer:bool, vk_pro_person:float, ek_pro_person:float,
     *   hk2_pro_person:float, db_pro_person:float, wareneinsatz_pct:?float, auto_gesamt:float,
     *   gesamt_vk:float, gesamt_ek:float, gesamt_hk2:float, gesamt_db:float,
     *   menue:list<array>, mengen:list<array>}
     */
    public function kalkulation(Team $team, FoodAlchemistAngebot $angebot, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): array
    {
        $orderCosting = app(OrderCostingService::class);
        $conceptSvc = app(ConceptService::class);
        $comp = app(OfferCompositionService::class);
        $pax = max(0, (int) ($angebot->personen ?? 0));

        // #380 Composer / Per-Kapitel-Pax (Q1): Kopf-Totale kommen pax-korrekt aus der Komposition
        // (Σ Kapitel-Pax × €/P + Pauschalen; kapitelAggregat deckt concept_ref+recipe_ref+header_preis ab).
        // Die Rich-KPIs (Zielpreis/HK2/Zeit) kommen aus costConcept je Concept-Einheit × ihrer Kapitel-Pax.
        $komposition = $comp->komposition($team, $angebot, $outlet, true);
        $einheiten = $comp->preisEinheiten($team, $angebot, $outlet);
        $units = $einheiten['units'];
        $summe = $komposition['summe'];
        $gesamtVkAuto = round((float) $summe['gesamt_vk'], 2);
        $gesamtEk = round((float) $summe['gesamt_ek'], 2);
        // Kopf-€/Person = Gesamt ÷ Angebots-Pax (Mischwert bei unterschiedlichen Kapitel-Pax).
        $vkPp = $pax > 0 ? round($gesamtVkAuto / $pax, 2) : round((float) $summe['vk_pro_person'], 2);
        $ekPp = $pax > 0 ? round($gesamtEk / $pax, 2) : round((float) $summe['ek_pro_person'], 2);

        $hk2Gesamt = 0.0;
        $zielGesamt = 0.0;
        $aktiveMinuten = 0.0;
        $warnungen = [];
        $menue = [];
        $mengen = [];
        foreach ($units as $u) {
            $c = $u['concept'];
            $uPax = max(0, (int) $u['pax']);
            $orderCost = $uPax > 0 ? $orderCosting->costConcept($team, $c, $uPax, $outlet) : null;
            $hk2Gesamt += $orderCost !== null ? (float) $orderCost['hk2'] : 0.0;
            $zielGesamt += (float) ($orderCost['target_price'] ?? 0);
            $aktiveMinuten += (float) ($orderCost['active_person_minutes'] ?? 0);
            $warnungen = array_merge($warnungen, $orderCost['warnings'] ?? []);
            $menue[] = [
                'id' => $c->id, 'name' => $c->name, 'pax' => $uPax,
                'zielpreis_pro_person' => $orderCost['target_price_per_person'] ?? null,
                'unwirtschaftlich' => (bool) ($orderCost['unprofitable'] ?? false),
            ];
            foreach ($conceptSvc->mengenHochrechnung($c, $uPax > 0 ? $uPax : null) as $z) {
                $mengen[] = $z + ['menue' => $c->name];
            }
        }

        // Per-Kapitel-Aufschlüsselung (Kalkulation-Tab + Karte + Präsentations-Block „Preis-Aufschlüsselung").
        $kapitelBreakdown = [];
        foreach ($komposition['kapitel'] as $kap) {
            $kapitelBreakdown[] = [
                'id' => $kap['id'],
                'titel' => $kap['title_intern'] ?? $kap['title'],
                'consumer_title' => $kap['title'],
                'pax' => $kap['pax'],
                'eigene_pax' => $kap['eigene_pax'] ?? false,
                'vk_pro_person' => $kap['vk_pro_person'],
                'ek_pro_person' => $kap['ek_pro_person'] ?? null,
                'gesamt' => $kap['gesamt'],
                'preis_range' => $kap['preis_range'],
                'ist_format' => $kap['ist_format'],
                'format_price_mode' => $kap['format_price_mode'] ?? null,
            ];
        }

        $expired = $angebot->price_override_expires_at?->isPast() ?? false;
        $manuell = in_array(($angebot->price_mode ?? 'auto'), ['fixed', 'manuell'], true)
            && ! $expired && $angebot->total_price !== null;
        $gesamt = $manuell ? round((float) $angebot->total_price, 2) : $gesamtVkAuto;

        return [
            'pax' => $pax,
            'price_mode' => $manuell ? ($angebot->price_mode === 'fixed' ? 'fixed' : 'manuell') : 'auto',
            'leer' => $gesamtVkAuto <= 0.0 && count($units) === 0,
            'alternativen' => $einheiten['alternativen'],
            'vk_pro_person' => $vkPp,
            'ek_per_person' => $ekPp,
            'hk2_pro_person' => $pax > 0 ? round($hk2Gesamt / $pax, 2) : 0.0,
            'db_pro_person' => $pax > 0 ? round($vkPp - $hk2Gesamt / $pax, 2) : $vkPp,
            'wareneinsatz_pct' => $gesamtVkAuto > 0 ? round($gesamtEk / $gesamtVkAuto * 100, 1) : null,
            'auto_gesamt' => $gesamtVkAuto,
            'gesamt_vk' => round($gesamt, 2),
            'gesamt_ek' => $gesamtEk,
            'gesamt_hk2' => round($hk2Gesamt, 2),
            'gesamt_db' => round($gesamt - $hk2Gesamt, 2),
            'mindestpreis' => round($hk2Gesamt, 2),
            'zielpreis' => round($zielGesamt, 2),
            'zielpreis_pro_person' => $pax > 0 ? round($zielGesamt / $pax, 2) : null,
            'zielabweichung' => round($zielGesamt - $gesamt, 2),
            'unwirtschaftlich' => $gesamt + 0.005 < $zielGesamt,
            'aktive_personenminuten' => round($aktiveMinuten, 2),
            'warnungen' => array_values(array_unique($warnungen)),
            'kapitel' => $kapitelBreakdown,
            'menue' => $menue,
            'mengen' => $mengen,
        ];
    }

    /** auto-Modus: schreibt den berechneten Gesamtpreis zurück (Liste + Persistenz konsistent). */
    public function aktualisiereAutoPreis(Team $team, FoodAlchemistAngebot $angebot): void
    {
        $auto = $this->kalkulation($team, $angebot)['auto_gesamt'];
        $oldCalculated = $angebot->calculated_total_price !== null ? (float) $angebot->calculated_total_price : null;
        $oldEffective = $angebot->total_price !== null ? (float) $angebot->total_price : null;
        $expired = $angebot->price_override_expires_at?->isPast() ?? false;
        $autoMode = ($angebot->price_mode ?? 'auto') === 'auto' || $expired;
        $update = [
            'calculated_total_price' => $auto,
            'price_calculation_source' => 'concept_catalog_sum',
            'price_calculation_version' => CatalogPricingService::VERSION,
            'price_calculated_at' => now(),
        ];
        if ($autoMode) {
            $update += ['price_mode' => 'auto', 'total_price' => $auto, 'price_override_reason' => null,
                'price_override_user_id' => null, 'price_override_at' => null, 'price_override_expires_at' => null];
        }
        $angebot->update($update);
        app(PriceAuditService::class)->record(
            $angebot, 'offer', $oldCalculated, $auto, $oldEffective,
            $angebot->total_price !== null ? (float) $angebot->total_price : null,
            'concept_catalog_sum', ['calculation_version' => CatalogPricingService::VERSION],
        );
    }

    /**
     * #384: Daten fürs versendbare Kunden-Dokument (Druckansicht/PDF) — Kopf
     * (Kunde/Anlass/Pax/Gültigkeit), Menü(s) mit Positionen, Preis aus kalkulation().
     * Eine Quelle für Blade + PDF.
     *
     * @return array{angebot:FoodAlchemistAngebot, kalk:array, menues:list<array>, kunde:?string, kontakt:?string}
     */
    public function dokumentDaten(Team $team, FoodAlchemistAngebot $angebot): array
    {
        $conceptSvc = app(ConceptService::class);
        $angebot->loadMissing(['crmCompany', 'crmContact', 'concepts', 'referenzierteConcepts']);
        $kalk = $this->kalkulation($team, $angebot);

        $menues = [];
        foreach ($this->menueConcepts($angebot) as $c) {
            $positionen = [];
            foreach ($conceptSvc->preisCockpit($c)['zeilen'] as $z) {
                if (($z['type'] ?? '') === 'leer') {
                    continue;
                }
                // Kundensicht: Brand-Voice-Wording bevorzugen (Concept-Schreibstil), sonst interner Name.
                $positionen[] = ['role' => $z['role'] ?? null, 'label' => ($z['wording'] ?? null) ?: ($z['label'] ?? '—')];
            }
            $menues[] = [
                'name' => $c->consumer_name ?: $c->name,
                'positionen' => $positionen,
            ];
        }

        return [
            'angebot' => $angebot,
            'kalk' => $kalk,
            'menues' => $menues,
            'customer' => $angebot->crmCompany?->display_name ?? $angebot->crmContact?->display_name,
            'kontakt' => $angebot->crmContact?->display_name,
        ];
    }

    /**
     * #380 Composer: Daten für die schöne Angebots-KARTE (Kundenausgabe, Foodbook-Look).
     * Interna-frei (komposition intern=false → kein EK/Marge) + Preis-Footer netto+MwSt+brutto.
     *
     * @return array{angebot:FoodAlchemistAngebot, titel:string, komposition:array, kalk:array,
     *   customer:?string, kontakt:?string, mwstSatz:float, netto_gesamt:float, mwst_betrag:float,
     *   brutto_gesamt:float, pax:int, vk_pro_person:float}
     */
    /**
     * #380 Composer: Vollkosten-/Zuschlagskalkulation (Wasserfall MEK→FEK→…→HK2→Preisempfehlung)
     * fürs GANZE Angebot × Pax — aggregiert OrderCostingService::costConcept über alle
     * Concept-Einheiten der Komposition (concept_ref + additiv-Format-Editionen). cost_breakdown
     * wird per `key` summiert (Wasserfall bleibt konsistent), *_per_person = Summe/Pax. Für das
     * Zuschlagskalkulation-Partial (livewire.angebote.partials.zuschlagskalkulation).
     *
     * @return ?array costConcept-förmiges Aggregat; null wenn kein Pax oder keine Concepts.
     */
    public function auftragsKalkulation(Team $team, FoodAlchemistAngebot $angebot, ?\Platform\FoodAlchemist\Models\FoodAlchemistOutlet $outlet = null): ?array
    {
        $pax = max(0, (int) ($angebot->personen ?? 0));
        $units = app(OfferCompositionService::class)->preisEinheiten($team, $angebot, $outlet)['units'];
        if ($units === []) {
            return null; // keine voll bekosteten Concept-Einheiten → kein Zuschlags-Wasserfall
        }
        $refPax = $pax > 0 ? $pax : 1; // Per-Person-Referenz (Mischwert bei unterschiedlichen Kapitel-Pax)
        $orderCosting = app(OrderCostingService::class);
        $sum = ['mek' => 0.0, 'fek' => 0.0, 'direct_costs' => 0.0, 'mgk' => 0.0, 'fgk' => 0.0, 'hk' => 0.0, 'hk2' => 0.0,
            'minimum_price' => 0.0, 'target_price' => 0.0, 'contribution_margin' => 0.0, 'active_person_minutes' => 0.0,
            'catalog_price_total' => 0.0];
        $breakdown = []; // key => ['key','label','stage','amount']
        $timeRows = [];
        $warnings = [];
        $complete = true;
        foreach ($units as $u) {
            $c = $u['concept'];
            $uPax = max(1, (int) $u['pax']);
            $cc = $orderCosting->costConcept($team, $c, $uPax, $outlet);
            foreach ($sum as $k => $_) {
                $sum[$k] += (float) ($cc[$k] ?? 0);
            }
            foreach (($cc['cost_breakdown'] ?? []) as $row) {
                $key = (string) ($row['key'] ?? $row['label'] ?? '?');
                $breakdown[$key] ??= ['key' => $key, 'label' => $row['label'] ?? $key, 'stage' => $row['stage'] ?? 'cost', 'amount' => 0.0];
                $breakdown[$key]['amount'] += (float) ($row['amount'] ?? 0);
            }
            foreach (($cc['time_breakdown'] ?? []) as $tr) {
                $timeRows[] = $tr;
            }
            $warnings = array_merge($warnings, $cc['warnings'] ?? []);
            $complete = $complete && (bool) ($cc['complete'] ?? false);
        }
        $targetTotal = round($sum['target_price'], 2);
        $catalogTotal = round($sum['catalog_price_total'], 2);

        return [
            'pax' => $pax,
            'catalog_price_per_person' => round($catalogTotal / $refPax, 2),
            'catalog_price_total' => $catalogTotal,
            'mek' => round($sum['mek'], 2), 'fek' => round($sum['fek'], 2), 'direct_costs' => round($sum['direct_costs'], 2),
            'mgk' => round($sum['mgk'], 2), 'fgk' => round($sum['fgk'], 2),
            'hk' => round($sum['hk'], 2), 'hk2' => round($sum['hk2'], 2),
            'minimum_price' => round($sum['minimum_price'], 2),
            'target_price' => $targetTotal,
            'target_price_per_person' => round($targetTotal / $refPax, 2),
            'contribution_margin' => round($sum['contribution_margin'], 2),
            'contribution_margin_pct' => $targetTotal > 0 ? round($sum['contribution_margin'] / $targetTotal * 100, 1) : null,
            'target_gap' => round($targetTotal - $catalogTotal, 2),
            'unprofitable' => $catalogTotal + 0.005 < $targetTotal,
            'complete' => $complete,
            'active_person_minutes' => round($sum['active_person_minutes'], 2),
            'cost_breakdown' => array_values($breakdown),
            'time_breakdown' => $timeRows,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function karteDaten(Team $team, int $id): array
    {
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->with(['crmCompany', 'crmContact'])->findOrFail($id);
        $komposition = app(OfferCompositionService::class)->komposition($team, $angebot, null, false);
        $kalk = $this->kalkulation($team, $angebot);
        $mwst = app(TeamSettingsService::class)->mwst($team);
        $satz = ($mwst['default_satz'] ?? 'ermaessigt') === 'regulaer'
            ? (float) ($mwst['regulaer'] ?? 19.0)
            : (float) ($mwst['ermaessigt'] ?? 7.0);
        $nettoGesamt = (float) $kalk['gesamt_vk'];

        return [
            'angebot' => $angebot,
            'titel' => $angebot->name,
            'komposition' => $komposition,
            'kalk' => $kalk,
            'customer' => $angebot->crmCompany?->display_name ?? $angebot->crmContact?->display_name,
            'kontakt' => $angebot->crmContact?->display_name,
            'mwstSatz' => $satz,
            'netto_gesamt' => round($nettoGesamt, 2),
            'mwst_betrag' => round($nettoGesamt * $satz / 100, 2),
            'brutto_gesamt' => round($nettoGesamt * (1 + $satz / 100), 2),
            'pax' => (int) $kalk['pax'],
            'vk_pro_person' => (float) $kalk['vk_pro_person'],
        ];
    }

    // ── #380 DoD-5: Katalog-Concepts referenzieren ─────────────────────────

    /** Alle Menüs eines Angebots: ad-hoc (offer_id) + referenzierte Katalog-Concepts. */
    public function menueConcepts(FoodAlchemistAngebot $angebot): Collection
    {
        $adhoc = $angebot->relationLoaded('concepts') ? $angebot->concepts : $angebot->concepts()->get();
        $ref = $angebot->relationLoaded('referencedConcepts') ? $angebot->referencedConcepts : $angebot->referencedConcepts()->get();

        return collect($adhoc)->merge($ref)->values();
    }

    /**
     * Stufe 3 — Angebot → Produktion. Baut aus den Concepts des Angebots `targets` (concept × Pax)
     * und legt EINEN Produktionsauftrag am Event-Tag an (Fallback: heute). Spiegelt
     * `SpeiseplanService::wocheAnProduktion` — Ziel ist, dass ein Angebot direkt in den Auto-Planer fließt.
     *
     * @return array{order_id: ?int, ziele: int}
     */
    public function anProduktion(Team $team, int $angebotId, ?int $userId = null): array
    {
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($angebotId);
        $pax = max(1, (int) ($angebot->personen ?? 0));
        $datum = $angebot->event_date
            ? \Illuminate\Support\Carbon::parse($angebot->event_date)->toDateString()
            : now()->toDateString();

        $targets = [];
        // #380 Composer: Produktions-Ziele aus der Komposition (concept_ref + additiv-Format-Editionen);
        // je Concept einmal (dedupliziert), unabhängig davon in wie vielen Kapiteln es referenziert ist.
        $conceptUnits = app(OfferCompositionService::class)->preisEinheiten($team, $angebot)['concepts']->unique('id');
        foreach ($conceptUnits as $c) {
            $targets[] = ['concept_id' => (int) $c->id, 'persons' => $pax, 'source_ref' => 'angebot:' . $angebot->id . ':c' . $c->id];
        }
        if ($targets === []) {
            return ['order_id' => null, 'ziele' => 0];
        }

        $name = 'Angebot: ' . $angebot->name . ' (' . $pax . ' Pers.)';
        $order = app(\Platform\FoodAlchemist\Services\ProductionOrderService::class)
            ->saveNew($team, $datum, $name, $targets, 'Angebot #' . $angebot->id, null, $userId);

        return ['order_id' => $order->id, 'ziele' => count($targets)];
    }

    /** Verknüpft ein STANDARDISIERTES Katalog-Concept mit dem Angebot (geteilt, nicht besessen). */
    public function referenziereConcept(Team $team, int $angebotId, int $conceptId): void
    {
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($angebotId);
        $this->guardOwner($angebot, $team);

        $concept = FoodAlchemistConcept::visibleToTeam($team)->whereNull('offer_id')->find($conceptId);
        if ($concept === null) {
            throw new \RuntimeException('Nur standardisierte Katalog-Concepts können referenziert werden.');
        }
        $pos = (int) (DB::table('foodalchemist_offer_concept')->where('offer_id', $angebot->id)->max('position') ?? -1) + 1;
        $angebot->referencedConcepts()->syncWithoutDetaching([$conceptId => ['team_id' => $team->id, 'position' => $pos]]);
        // #380 Composer: referenziertes Katalog-Concept erscheint als concept_ref-Block in der Komposition.
        $this->ensureConceptBlock($team, $angebot->id, $conceptId);
        $this->aktualisiereAutoPreis($team, $angebot);
    }

    public function entferneReferenz(Team $team, int $angebotId, int $conceptId): void
    {
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($angebotId);
        $this->guardOwner($angebot, $team);
        $angebot->referencedConcepts()->detach($conceptId);
        // #380 Composer: zugehörige concept_ref-Blöcke mitentfernen (Komposition = autoritativ).
        \Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock::where('type', 'concept_ref')
            ->where('concept_id', $conceptId)
            ->whereHas('chapter', fn ($q) => $q->where('offer_id', $angebot->id))
            ->get()->each->delete();
        $this->aktualisiereAutoPreis($team, $angebot);
    }

    /** Suche über standardisierte (echte) Katalog-Concepts für den Referenz-Picker. */
    /**
     * UX 2026-07-25 (Dominique): Angebots-Katalog filtert auf die Concepter-DIMENSIONEN
     * (Eventtyp/Servierform/Einsatzmoment/Saison) — Konzept-Taxonomie ausgemustert. Spiegelt
     * FoodbookService::conceptKandidaten / ConceptService::paginateBrowser.
     *
     * @param array{eventtyp?:?int, servierform?:?int, einsatzmoment?:?int, season?:?int} $facetten
     */
    public function katalogConcepts(Team $team, string $suche, int $limit = 50, array $facetten = []): Collection
    {
        // Kaskade: Ausgabe-Form → Konzepte UND Pakete buchbar (Paket = kind=paket-Concept).
        return FoodAlchemistConcept::visibleToTeam($team)->standardisiert()->echte()
            ->where('status', 'active') // Picker zeigt nur aktive (keine Entwürfe/archivierten; Status berücksichtigt)
            ->when(trim($suche) !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->when(! empty($facetten['eventtyp']), fn ($q) => $q->where('event_type_id', (int) $facetten['eventtyp']))
            ->when(! empty($facetten['servierform']), fn ($q) => $q->where('serving_form_id', (int) $facetten['servierform']))
            ->when(! empty($facetten['einsatzmoment']), fn ($q) => $q->whereHas('serviceMoments', fn ($w) => $w->where('foodalchemist_service_moments.id', (int) $facetten['einsatzmoment'])))
            ->when(! empty($facetten['season']), fn ($q) => $q->whereHas('seasons', fn ($w) => $w->where('foodalchemist_seasons.id', (int) $facetten['season'])))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'price_per_person_cache', 'event_type_id', 'serving_form_id']);
    }

    /** #380 Composer: Formate (Marken-Container) für den „+ Format"-Picker (team-sichtbar, nicht archiviert). Spiegelt SpeisekarteService::formatKandidaten. */
    public function formatKandidaten(Team $team, string $suche = '', int $limit = 50): Collection
    {
        return \Platform\FoodAlchemist\Models\FoodAlchemistFormat::visibleToTeam($team)
            ->where('status', '!=', 'archiviert')
            ->when(trim($suche) !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'consumer_name', 'status']);
    }

    /** Concept-Katalog-Facetten (Eventtypen · Servierformen · Momente · Saisons) für die Angebot-Editor-Filterleiste. */
    public function facetten(Team $team): array
    {
        return [
            'eventtypen' => \Platform\FoodAlchemist\Models\FoodAlchemistEventtyp::visibleToTeam($team)->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
            'servierformen' => \Platform\FoodAlchemist\Models\FoodAlchemistServierform::where('is_inactive', false)->orderBy('sort_order')->get(['id', 'label']),
            'momente' => \Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::visibleToTeam($team)->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
            'saisons' => \Platform\FoodAlchemist\Models\FoodAlchemistSaison::visibleToTeam($team)->where('is_inactive', false)->orderBy('sort_order')->get(['id', 'name']),
        ];
    }

    // ── CRM-Lese-Picker (MVP) — class_exists-geschützt (Modul läuft ohne crm) ──

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

    // ── A3 · Picker-Parität (spiegelt FoodbookService::paketKandidaten/gerichtKandidaten) ──

    /**
     * Paket-Kandidaten (kind=paket-Concepts) für den Angebot-Picker — eigener Reiter neben
     * Concept (katalogConcepts) + Format (formatKandidaten). Dieselbe Filterkette wie
     * {@see katalogConcepts}; zeigt `consumer_name` (Kundenname) statt des internen Namens.
     * Ein Paket wird wie ein Concept als concept_ref-Block gebucht (concept_id trägt beide Arten).
     * Spiegelt FoodbookService::paketKandidaten.
     *
     * @param array{eventtyp?:?int, servierform?:?int, einsatzmoment?:?int, season?:?int} $facetten
     */
    public function paketKandidaten(Team $team, string $suche, int $limit = 20, array $facetten = []): Collection
    {
        return FoodAlchemistConcept::visibleToTeam($team)->echte()->pakete()
            ->where('status', 'active')
            ->when(trim($suche) !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::likeAny($q, ['name', "COALESCE(consumer_name, '')"], $suche))
            ->when(! empty($facetten['eventtyp']), fn ($q) => $q->where('event_type_id', (int) $facetten['eventtyp']))
            ->when(! empty($facetten['servierform']), fn ($q) => $q->where('serving_form_id', (int) $facetten['servierform']))
            ->when(! empty($facetten['einsatzmoment']), fn ($q) => $q->whereHas('serviceMoments', fn ($w) => $w->where('foodalchemist_service_moments.id', (int) $facetten['einsatzmoment'])))
            ->when(! empty($facetten['season']), fn ($q) => $q->whereHas('seasons', fn ($w) => $w->where('foodalchemist_seasons.id', (int) $facetten['season'])))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'consumer_name', 'price_per_person_cache', 'event_type_id', 'serving_form_id']);
    }

    /**
     * Einzelne Gerichte (VK-Rezepte) für den recipe_ref-Picker. Spiegelt FoodbookService::gerichtKandidaten.
     * Modell A: HG = Kategorie (recipes.dish_main_group_id), Untergruppe = Diät-Klasse
     * (recipes.dish_class_id); beide Achsen filtern den Picker (dishClassId ist die feinere).
     * Slot-Varianten (variant_source_recipe_id) sind konzept-lokal, nicht pickbar (R4.4).
     */
    public function gerichtKandidaten(Team $team, string $suche, int $limit = 20, ?int $hauptgruppe = null, ?int $dishClassId = null): Collection
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id')
            ->when(trim($suche) !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->when($hauptgruppe !== null, fn ($q) => $q->where('dish_main_group_id', $hauptgruppe))
            ->when($dishClassId !== null, fn ($q) => $q->where('dish_class_id', $dishClassId))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'sales_net']);
    }

    // ── A3 · Branding (pro Angebot) — spiegelt FoodbookService::setBranding/storeLogo/… ──
    //
    // UI-agnostische API: der Branding/CI-Tab UND MCP/Console rufen dieselben Methoden.
    // Owner-Guard wie überall (D1). Bilder laufen über Core ContextFiles (FoodAlchemistMediaService).

    /** Setzt Farb-/Text-Marke. $in: brand_color, band_color, footer_text (jeweils optional). */
    public function setBranding(Team $team, int $offerId, array $in): FoodAlchemistAngebot
    {
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($offerId);
        $this->guardOwner($angebot, $team);

        $daten = [];
        if (array_key_exists('brand_color', $in)) {
            $daten['brand_color'] = $this->normHexOderThrow($in['brand_color'], 'brand_color') ?? '#6d28d9';
        }
        if (array_key_exists('band_color', $in)) {
            // Leer → null (Blade leitet dann aus brand_color ab).
            $daten['band_color'] = $this->normHexOderThrow($in['band_color'], 'band_color', erlaubeLeer: true);
        }
        if (array_key_exists('footer_text', $in)) {
            $t = trim((string) $in['footer_text']);
            $daten['footer_text'] = $t !== '' ? $t : null;
        }
        if ($daten !== []) {
            $angebot->update($daten);
        }

        return $angebot->refresh();
    }

    public function storeLogo(Team $team, int $offerId, UploadedFile $file): string
    {
        return $this->speichereBrandingBild($team, $offerId, $file, 'logo_path');
    }

    public function storeCover(Team $team, int $offerId, UploadedFile $file): string
    {
        return $this->speichereBrandingBild($team, $offerId, $file, 'cover_image_path');
    }

    public function clearLogo(Team $team, int $offerId): FoodAlchemistAngebot
    {
        return $this->loescheBrandingBild($team, $offerId, 'logo_path');
    }

    public function clearCover(Team $team, int $offerId): FoodAlchemistAngebot
    {
        return $this->loescheBrandingBild($team, $offerId, 'cover_image_path');
    }

    private function speichereBrandingBild(Team $team, int $offerId, UploadedFile $file, string $spalte): string
    {
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($offerId);
        $this->guardOwner($angebot, $team);

        $contextSpalte = $this->brandingContextSpalte($spalte);
        $alt = (string) $angebot->{$spalte};
        app(FoodAlchemistMediaService::class)->delete($angebot->{$contextSpalte}, $alt, $team);

        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $file,
            $team,
            'foodalchemist.offer',
            $offerId,
            "foodalchemist/offer-branding/{$offerId}",
        );
        $pfad = $media['path'];
        $angebot->update([
            $spalte => $pfad,
            $contextSpalte => $media['context_file_id'],
        ]);

        return $pfad;
    }

    private function loescheBrandingBild(Team $team, int $offerId, string $spalte): FoodAlchemistAngebot
    {
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($offerId);
        $this->guardOwner($angebot, $team);

        $contextSpalte = $this->brandingContextSpalte($spalte);
        $alt = (string) $angebot->{$spalte};
        app(FoodAlchemistMediaService::class)->delete($angebot->{$contextSpalte}, $alt, $team);
        $angebot->update([
            $spalte => null,
            $contextSpalte => null,
        ]);

        return $angebot->refresh();
    }

    private function brandingContextSpalte(string $spalte): string
    {
        return match ($spalte) {
            'logo_path' => 'logo_context_file_id',
            'cover_image_path' => 'cover_context_file_id',
            default => throw new \InvalidArgumentException("Unbekannte Branding-Bildspalte: {$spalte}"),
        };
    }

    /** Hex-Validierung wie Settings\Kueche::sanitizeFarben. erlaubeLeer=true → '' ⇒ null. */
    private function normHexOderThrow($wert, string $feld, bool $erlaubeLeer = false): ?string
    {
        $v = trim((string) $wert);
        if ($v === '') {
            if ($erlaubeLeer) {
                return null;
            }
            throw new \RuntimeException("Farbe {$feld} darf nicht leer sein.");
        }
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
            throw new \RuntimeException("Ungültige Farbe für {$feld}: \"{$v}\" (erwartet #RRGGBB).");
        }

        return strtolower($v);
    }

    // ── A3 · KI-Kundentext-Vorschlag (spiegelt FoodbookService::kiKundentextVorschlag) ──
    //
    // Nur Vorschlag — persistiert NICHTS (Übernehmen bleibt ein menschlicher Akt, Backup-Lehre).
    // Anders als im Foodbook: OHNE gebundenen Provider NIE werfen → graceful leerer Vorschlag,
    // damit der Angebot-Editor nicht bricht.

    /**
     * Kundentext-Vorschlag für die Angebots-Einleitung. Kontext aus Angebot/Concepts.
     *
     * @return array{text: string, confidence: ?float, call_log_id: ?int}
     */
    public function kiKundentextVorschlag(Team $team, int $offerId): array
    {
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($offerId);
        $this->guardOwner($angebot, $team);

        try {
            $wissen = app(\Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::class)
                ->contextFor('foodbook.kundentext', (string) ($angebot->name ?: 'Angebot'));

            $proposal = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose(
                'foodbook.kundentext',
                $this->kundentextKontext($team, $angebot),
                [
                    'food_dna_crm_company_id' => $angebot->crm_company_id !== null ? (int) $angebot->crm_company_id : null,
                    'target_table' => 'foodalchemist_offers',
                    'target_id' => (int) $angebot->id,
                    'knowledge' => $wissen['block'] ?? null,
                    'knowledge_used' => $wissen['files_used'] ?? null,
                ],
            );
            $text = trim((string) ($proposal->werte['text'] ?? ''));

            return ['text' => $text, 'confidence' => $proposal->confidence, 'call_log_id' => $proposal->callLogId];
        } catch (\Throwable $e) {
            // Kein Provider / KI deaktiviert / Fehler → graceful leerer Vorschlag (nie werfen).
            return ['text' => '', 'confidence' => null, 'call_log_id' => null];
        }
    }

    /**
     * Dasselbe für die Kapitel-Ebene (`foodalchemist_offer_chapters.description`). Eigener
     * Einstieg, geteilter Prompt-Key — die Ebene steht im Kontext (`ebene`), nicht im Key.
     * Ebenfalls graceful (nie werfen).
     *
     * @return array{text: string, confidence: ?float, call_log_id: ?int}
     */
    public function kiKapitelKundentextVorschlag(Team $team, int $chapterId): array
    {
        $kapitel = \Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter::visibleToTeam($team)->findOrFail($chapterId);
        if (! $kapitel->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Angebot — Pflege nur durchs Besitzer-Team (D1).');
        }
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($kapitel->offer_id);

        try {
            $wissen = app(\Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::class)
                ->contextFor('foodbook.kundentext', (string) ($kapitel->title ?: $angebot->name ?: 'Kapitel'));

            $proposal = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose(
                'foodbook.kundentext',
                $this->kapitelKundentextKontext($team, $angebot, $kapitel),
                [
                    'food_dna_crm_company_id' => $angebot->crm_company_id !== null ? (int) $angebot->crm_company_id : null,
                    'target_table' => 'foodalchemist_offer_chapters',
                    'target_id' => (int) $kapitel->id,
                    'knowledge' => $wissen['block'] ?? null,
                    'knowledge_used' => $wissen['files_used'] ?? null,
                ],
            );
            $text = trim((string) ($proposal->werte['text'] ?? ''));

            return ['text' => $text, 'confidence' => $proposal->confidence, 'call_log_id' => $proposal->callLogId];
        } catch (\Throwable $e) {
            return ['text' => '', 'confidence' => null, 'call_log_id' => null];
        }
    }

    /**
     * Kontext-Vertrag des Kundentexts (Buch-Ebene): WAS im Angebot steht (Gliederung über die
     * Kapitel + sichtbare Positionen) + das Roh-Briefing (description) als Umformungs-Vorlage.
     *
     * @return array<string, mixed>
     */
    private function kundentextKontext(Team $team, FoodAlchemistAngebot $angebot): array
    {
        $angebot->loadMissing(['crmCompany', 'chapters.blocks']);

        $gliederung = [];
        foreach ($angebot->chapters as $k) {
            $gliederung[] = $this->kundentextKapitelZeile($k);
            if (count($gliederung) >= 20) {
                break;
            }
        }
        $briefing = trim((string) $angebot->description);

        return [
            'ebene' => 'foodbook',
            'titel' => $angebot->name,
            'kunde' => $angebot->crmCompany?->display_name,
            'personen' => $angebot->personen,
            'briefing_ist' => $briefing !== '' ? $briefing : null,
            'gliederung' => $gliederung,
        ];
    }

    /**
     * Kontext-Vertrag der Kapitel-Ebene: Gliederung auf DIESES Kapitel (+ Unterkapitel) geschnitten;
     * `briefing_ist` ist der Kapitel-Text (Umformen statt Neuschreiben); das Angebot-Briefing kommt
     * getrennt als `rahmen_einleitung` mit.
     *
     * @return array<string, mixed>
     */
    private function kapitelKundentextKontext(Team $team, FoodAlchemistAngebot $angebot, \Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter $kapitel): array
    {
        $gliederung = [$this->kundentextKapitelZeile($kapitel)];
        foreach ($kapitel->children()->limit(19)->get() as $kind) {
            $gliederung[] = $this->kundentextKapitelZeile($kind);
        }
        $kapitelText = trim((string) $kapitel->description);
        $rahmen = trim((string) $angebot->description);

        return [
            'ebene' => 'kapitel',
            'titel' => trim((string) ($kapitel->consumer_title ?: $kapitel->title)),
            'foodbook_titel' => $angebot->name,
            'kunde' => $angebot->crmCompany?->display_name,
            'personen' => $angebot->personen,
            'briefing_ist' => $kapitelText !== '' ? $kapitelText : null,
            'rahmen_einleitung' => $rahmen !== '' ? $rahmen : null,
            'gliederung' => $gliederung,
        ];
    }

    /**
     * Eine Kapitel-Zeile der KI-Gliederung: Kunden-Label des Kapitels + seine sichtbaren
     * Positionen (Concept-/Rezept-Namen). Deckel gegen Prompt-Aufblähung.
     *
     * @return array{kapitel: string, positionen: list<string>}
     */
    private function kundentextKapitelZeile(\Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter $kapitel): array
    {
        $positionen = [];
        foreach ($kapitel->blocks as $b) {
            if (! $b->visible) {
                continue;
            }
            // Kundensicht-Wording bevorzugen (Slot-Analog), sonst Concept-Name, sonst interner Label.
            $label = trim((string) ($b->wording ?: $b->concept?->consumer_name ?: $b->concept?->name ?: $b->label ?: ''));
            if ($label !== '') {
                $positionen[] = $label;
            }
        }

        return [
            'kapitel' => trim((string) ($kapitel->consumer_title ?: $kapitel->title)),
            'positionen' => array_slice(array_values(array_unique($positionen)), 0, 12),
        ];
    }

    private function guardOwner(FoodAlchemistAngebot $angebot, Team $team): void
    {
        if (! $angebot->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Angebot — Pflege nur durchs Besitzer-Team (D1).');
        }
    }
}
