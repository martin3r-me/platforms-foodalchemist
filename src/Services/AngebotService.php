<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\AngebotStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;

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
        $kalk = app(KalkulationService::class);
        $orderCosting = app(OrderCostingService::class);
        $conceptSvc = app(ConceptService::class);
        $pax = max(0, (int) ($angebot->personen ?? 0));
        // #380 Composer: Preis-Einheiten aus der Kapitel/Block-Komposition (concept_ref +
        // additiv-Format-Editionen = voll bekostete Concepts; recipe_ref/header_preis =
        // einfache Zuschläge; alternativen-Formate = Range, nicht additiv).
        $einheiten = app(OfferCompositionService::class)->preisEinheiten($team, $angebot, $outlet);
        $concepts = $einheiten['concepts'];

        $vkPp = 0.0;
        $ekPp = 0.0;
        $hk2Pp = 0.0;
        $menue = [];
        $mengen = [];
        $aktiveMinuten = 0.0;
        $zielGesamt = 0.0;
        $warnungen = [];
        foreach ($concepts as $c) {
            $hk = $kalk->conceptHk($team, $c, $outlet);
            $orderCost = $pax > 0 ? $orderCosting->costConcept($team, $c, $pax, $outlet) : null;
            $vkPp += (float) $hk['vk_pro_person'];
            $ekPp += $orderCost !== null ? (float) $orderCost['mek'] / $pax : (float) $hk['hk1_pro_person'];
            $hk2Pp += $orderCost !== null ? (float) $orderCost['hk2'] / $pax : 0.0;
            $aktiveMinuten += (float) ($orderCost['active_person_minutes'] ?? 0);
            $zielGesamt += (float) ($orderCost['target_price'] ?? 0);
            $warnungen = array_merge($warnungen, $orderCost['warnings'] ?? []);
            $menue[] = [
                'id' => $c->id,
                'name' => $c->name,
                'vk_pro_person' => round((float) $hk['vk_pro_person'], 2),
                'hk2_pro_person' => $orderCost !== null ? round((float) $orderCost['hk2'] / $pax, 2) : null,
                'zielpreis_pro_person' => $orderCost['target_price_per_person'] ?? null,
                'unwirtschaftlich' => (bool) ($orderCost['unprofitable'] ?? false),
            ];
            foreach ($conceptSvc->mengenHochrechnung($c, $pax > 0 ? $pax : null) as $z) {
                $mengen[] = $z + ['menue' => $c->name];
            }
        }

        // Einfache Zuschläge aus der Komposition: recipe_ref/header_preis(person) je Person,
        // header_preis(pauschal)/recipe_ref(pauschal) als flacher Anteil (kein ×Pax).
        $vkPp += (float) $einheiten['vk_pp_extra'];
        $ekPp += (float) $einheiten['ek_pp_extra'];
        $hk2Pp += (float) $einheiten['ek_pp_extra']; // Näherung: einfache Posten ohne Arbeitszeit → HK2≈EK (kein DB-Overstate)

        $autoGesamt = round($vkPp * $pax, 2) + (float) $einheiten['flat_total'];
        $expired = $angebot->price_override_expires_at?->isPast() ?? false;
        $manuell = in_array(($angebot->price_mode ?? 'auto'), ['fixed', 'manuell'], true)
            && ! $expired && $angebot->total_price !== null;
        $gesamt = $manuell ? round((float) $angebot->total_price, 2) : $autoGesamt;

        return [
            'pax' => $pax,
            'price_mode' => $manuell ? ($angebot->price_mode === 'fixed' ? 'fixed' : 'manuell') : 'auto',
            'leer' => $concepts->isEmpty() && (float) $einheiten['vk_pp_extra'] === 0.0 && (float) $einheiten['flat_total'] === 0.0,
            'alternativen' => $einheiten['alternativen'],
            'vk_pro_person' => round($vkPp, 2),
            'ek_per_person' => round($ekPp, 2),
            'hk2_pro_person' => round($hk2Pp, 2),
            'db_pro_person' => round($vkPp - $hk2Pp, 2),
            'wareneinsatz_pct' => $vkPp > 0 ? round($ekPp / $vkPp * 100, 1) : null,
            'auto_gesamt' => $autoGesamt,
            'gesamt_vk' => $gesamt,
            'gesamt_ek' => round($ekPp * $pax, 2),
            'gesamt_hk2' => round($hk2Pp * $pax, 2),
            'gesamt_db' => round($gesamt - $hk2Pp * $pax, 2),
            'mindestpreis' => round($hk2Pp * $pax, 2),
            'zielpreis' => round($zielGesamt, 2),
            'zielpreis_pro_person' => $pax > 0 ? round($zielGesamt / $pax, 2) : null,
            'zielabweichung' => round($zielGesamt - $gesamt, 2),
            'unwirtschaftlich' => $pax > 0 && $gesamt + 0.005 < $zielGesamt,
            'aktive_personenminuten' => round($aktiveMinuten, 2),
            'warnungen' => array_values(array_unique($warnungen)),
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

    private function guardOwner(FoodAlchemistAngebot $angebot, Team $team): void
    {
        if (! $angebot->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Angebot — Pflege nur durchs Besitzer-Team (D1).');
        }
    }
}
