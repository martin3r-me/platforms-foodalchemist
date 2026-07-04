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
        'name', 'status', 'anlass', 'personen', 'budget', 'event_datum', 'location',
        'diaet_vorgabe', 'brief', 'gesamtpreis', 'valid_until', 'description', 'note',
        'crm_company_id', 'crm_contact_id', 'preis_modus',
    ];

    /** Leer („" / null) → NULL (optionale Zahlen/Daten/FKs). */
    private const FELDER_NULLBAR = ['personen', 'budget', 'event_datum', 'valid_until', 'gesamtpreis', 'crm_company_id', 'crm_contact_id'];

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistAngebot::visibleToTeam($team)
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $s = '%' . mb_strtolower($filters['search']) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(anlass, \'\')) LIKE ?', [$s]));
            })
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
                'referenzierteConcepts' => fn ($q) => $q->withCount('slots'),
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
            'anlass' => $in['anlass'] ?? null,
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

        return FoodAlchemistConcept::create([
            'team_id' => $team->id,
            'offer_id' => $angebot->id,
            'name' => trim((string) ($name ?? ($angebot->name . ' – Menü'))) ?: 'Menü',
            'status' => 'draft',
            'is_vorlage' => false,
            'anlass' => $angebot->anlass,
        ]);
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
    public function kalkulation(Team $team, FoodAlchemistAngebot $angebot): array
    {
        $kalk = app(KalkulationService::class);
        $conceptSvc = app(ConceptService::class);
        $pax = max(0, (int) ($angebot->personen ?? 0));
        $concepts = $this->menueConcepts($angebot);

        $vkPp = 0.0;
        $ekPp = 0.0;
        $hk2Pp = 0.0;
        $menue = [];
        $mengen = [];
        foreach ($concepts as $c) {
            $hk = $kalk->conceptHk($team, $c);
            $vkPp += (float) $hk['vk_pro_person'];
            $ekPp += (float) $hk['hk1_pro_person'];
            $hk2Pp += (float) $hk['hk2_pro_person'];
            $menue[] = [
                'id' => $c->id,
                'name' => $c->name,
                'vk_pro_person' => round((float) $hk['vk_pro_person'], 2),
                'hk2_pro_person' => round((float) $hk['hk2_pro_person'], 2),
            ];
            foreach ($conceptSvc->mengenHochrechnung($c, $pax > 0 ? $pax : null) as $z) {
                $mengen[] = $z + ['menue' => $c->name];
            }
        }

        $autoGesamt = round($vkPp * $pax, 2);
        $manuell = ($angebot->preis_modus ?? 'auto') === 'manuell' && $angebot->gesamtpreis !== null;
        $gesamt = $manuell ? round((float) $angebot->gesamtpreis, 2) : $autoGesamt;

        return [
            'pax' => $pax,
            'preis_modus' => $manuell ? 'manuell' : 'auto',
            'leer' => $concepts->isEmpty(),
            'vk_pro_person' => round($vkPp, 2),
            'ek_pro_person' => round($ekPp, 2),
            'hk2_pro_person' => round($hk2Pp, 2),
            'db_pro_person' => round($vkPp - $hk2Pp, 2),
            'wareneinsatz_pct' => $vkPp > 0 ? round($ekPp / $vkPp * 100, 1) : null,
            'auto_gesamt' => $autoGesamt,
            'gesamt_vk' => $gesamt,
            'gesamt_ek' => round($ekPp * $pax, 2),
            'gesamt_hk2' => round($hk2Pp * $pax, 2),
            'gesamt_db' => round($gesamt - $hk2Pp * $pax, 2),
            'menue' => $menue,
            'mengen' => $mengen,
        ];
    }

    /** auto-Modus: schreibt den berechneten Gesamtpreis zurück (Liste + Persistenz konsistent). */
    public function aktualisiereAutoPreis(Team $team, FoodAlchemistAngebot $angebot): void
    {
        if (($angebot->preis_modus ?? 'auto') !== 'auto') {
            return;
        }
        $auto = $this->kalkulation($team, $angebot)['auto_gesamt'];
        if (round((float) ($angebot->gesamtpreis ?? -1), 2) !== $auto) {
            $angebot->update(['gesamtpreis' => $auto]);
        }
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
                if (($z['typ'] ?? '') === 'leer') {
                    continue;
                }
                // Kundensicht: Brand-Voice-Wording bevorzugen (Concept-Schreibstil), sonst interner Name.
                $positionen[] = ['role' => $z['role'] ?? null, 'label' => ($z['wording'] ?? null) ?: ($z['label'] ?? '—')];
            }
            $menues[] = [
                'name' => $c->konsumenten_name ?: $c->name,
                'positionen' => $positionen,
            ];
        }

        return [
            'angebot' => $angebot,
            'kalk' => $kalk,
            'menues' => $menues,
            'kunde' => $angebot->crmCompany?->display_name ?? $angebot->crmContact?->display_name,
            'kontakt' => $angebot->crmContact?->display_name,
        ];
    }

    // ── #380 DoD-5: Katalog-Concepts referenzieren ─────────────────────────

    /** Alle Menüs eines Angebots: ad-hoc (offer_id) + referenzierte Katalog-Concepts. */
    public function menueConcepts(FoodAlchemistAngebot $angebot): Collection
    {
        $adhoc = $angebot->relationLoaded('concepts') ? $angebot->concepts : $angebot->concepts()->get();
        $ref = $angebot->relationLoaded('referenzierteConcepts') ? $angebot->referenzierteConcepts : $angebot->referenzierteConcepts()->get();

        return collect($adhoc)->merge($ref)->values();
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
        $angebot->referenzierteConcepts()->syncWithoutDetaching([$conceptId => ['team_id' => $team->id, 'position' => $pos]]);
        $this->aktualisiereAutoPreis($team, $angebot);
    }

    public function entferneReferenz(Team $team, int $angebotId, int $conceptId): void
    {
        $angebot = FoodAlchemistAngebot::visibleToTeam($team)->findOrFail($angebotId);
        $this->guardOwner($angebot, $team);
        $angebot->referenzierteConcepts()->detach($conceptId);
        $this->aktualisiereAutoPreis($team, $angebot);
    }

    /** Suche über standardisierte (echte) Katalog-Concepts für den Referenz-Picker. */
    public function katalogConcepts(Team $team, string $suche, ?int $kategorieId = null, int $limit = 50): Collection
    {
        return FoodAlchemistConcept::visibleToTeam($team)->standardisiert()->echte()
            ->when(trim($suche) !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower(trim($suche)) . '%']))
            ->when($kategorieId !== null, fn ($q) => $q->where('category_id', $kategorieId))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'preis_pro_person_cache', 'category_id']);
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
