<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistProjekt;
use Platform\FoodAlchemist\Models\FoodAlchemistProjektStandort;
use Platform\FoodAlchemist\Support\CrmKunden;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 §9 (Entscheidung Dominique 13.10.) — Projekte als AGENTUR-AKTE im Master-Team.
 *
 * Ein Projekt gehört dem Master-Team und hängt an einer CRM-Firma aus dem CRM des Master-Teams (nur verknüpfen, nie ins CRM schreiben,
 * Fremdmodul). Der Mandant entsteht erst bei der Lizenz-Übernahme; bis dahin hat das Projekt eigene KUNDEN-STANDORTE (ohne Team).
 * Lesen und Schreiben nur im Master-Team (Bereich „projekte“ ist Master-only), Schreiben ab Rolle „Kuratieren“. Es gibt kein
 * Cross-Team-Schreiben und keine Mandanten-Inhalte — nur Agentur-Daten.
 */
class ProjektService
{
    public function __construct(private readonly FaRechte $rechte)
    {
    }

    private function pruefeMaster(Team $team): void
    {
        if (! MasterTeam::istMaster($team)) {
            throw new \RuntimeException('Projekte gibt es nur im Master-Team (Agentur-Akte).');
        }
    }

    private function pruefeSchreiben(Team $team, ?User $user, string $wofuer): void
    {
        $this->pruefeMaster($team);
        $this->rechte->pruefe($user, $team, FaRolle::Kuratieren, $wofuer);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, FoodAlchemistProjekt> */
    public function liste(Team $team, ?string $status = null)
    {
        $this->pruefeMaster($team);

        return FoodAlchemistProjekt::where('team_id', $team->id)->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'aktiv' THEN 0 WHEN 'pausiert' THEN 1 ELSE 2 END")->orderBy('name')->get();
    }

    public function finde(Team $team, int $id): FoodAlchemistProjekt
    {
        $this->pruefeMaster($team);

        return FoodAlchemistProjekt::where('team_id', $team->id)->findOrFail($id);
    }

    /** Projekt anlegen: CRM-Firma muss im CRM des Master-Teams liegen. Der Projekt-Canvas entsteht beim ersten Öffnen. */
    public function anlegen(Team $team, ?User $user, int $crmCompanyId, string $name, ?string $ziel = null): FoodAlchemistProjekt
    {
        $this->pruefeSchreiben($team, $user, 'Projekt anlegen');
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Ein Projekt braucht einen Namen.');
        }
        if (CrmKunden::firma($team, $crmCompanyId) === null) {
            throw new \RuntimeException('Die CRM-Firma gibt es im CRM des Master-Teams nicht.');
        }

        return FoodAlchemistProjekt::create([
            'team_id' => $team->id, 'crm_company_id' => $crmCompanyId, 'name' => $name,
            'ziel' => $ziel !== null && trim($ziel) !== '' ? trim($ziel) : null, 'updated_by' => $user?->id,
        ])->refresh();   // DB-Standardwerte (Phase, Status) im Objekt
    }

    /**
     * Kopf pflegen: name, ziel, phase, status, planner_projekt_id, pilot_standort_id. Nicht übergebene Felder bleiben.
     *
     * @param  array<string,mixed>  $felder
     */
    public function setze(Team $team, ?User $user, int $id, array $felder): FoodAlchemistProjekt
    {
        $this->pruefeSchreiben($team, $user, 'Projekt pflegen');
        $p = $this->finde($team, $id);
        $werte = [];
        if (array_key_exists('name', $felder)) {
            $name = trim((string) $felder['name']);
            if ($name === '') {
                throw new \RuntimeException('Ein Projekt braucht einen Namen.');
            }
            $werte['name'] = $name;
        }
        if (array_key_exists('ziel', $felder)) {
            $werte['ziel'] = trim((string) $felder['ziel']) !== '' ? trim((string) $felder['ziel']) : null;
        }
        if (array_key_exists('phase', $felder)) {
            if (! array_key_exists((string) $felder['phase'], FoodAlchemistProjekt::PHASEN)) {
                throw new \RuntimeException('Phase: '.implode(' | ', array_keys(FoodAlchemistProjekt::PHASEN)).'.');
            }
            $werte['phase'] = (string) $felder['phase'];
        }
        if (array_key_exists('status', $felder)) {
            if (! in_array((string) $felder['status'], FoodAlchemistProjekt::STATUS, true)) {
                throw new \RuntimeException('Status: '.implode(' | ', FoodAlchemistProjekt::STATUS).'.');
            }
            $werte['status'] = (string) $felder['status'];
        }
        if (array_key_exists('planner_projekt_id', $felder)) {
            $id2 = $felder['planner_projekt_id'];
            if ($id2 !== null && $id2 !== '' && (! is_numeric($id2) || (int) $id2 < 1)) {
                throw new \RuntimeException('planner_projekt_id: ganze Zahl ab 1 (oder leer).');
            }
            $werte['planner_projekt_id'] = $id2 === null || $id2 === '' ? null : (int) $id2;   // nur Verweis auf den Office-Planner
        }
        if (array_key_exists('pilot_standort_id', $felder)) {
            $this->pilotSetzen($team, $user, $p->id, $felder['pilot_standort_id'] === null || $felder['pilot_standort_id'] === '' ? null : (int) $felder['pilot_standort_id']);
        }
        $p->update($werte + ['updated_by' => $user?->id]);

        return $p->refresh();
    }

    public function standortAnlegen(Team $team, ?User $user, int $projektId, string $name, ?string $notiz = null): FoodAlchemistProjektStandort
    {
        $this->pruefeSchreiben($team, $user, 'Kunden-Standort anlegen');
        $p = $this->finde($team, $projektId);
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Ein Standort braucht einen Namen.');
        }
        if (FoodAlchemistProjektStandort::where('projekt_id', $p->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new \RuntimeException('Diesen Standort gibt es im Projekt schon.');
        }
        $erster = ! FoodAlchemistProjektStandort::where('projekt_id', $p->id)->exists();
        $standort = FoodAlchemistProjektStandort::create([
            'team_id' => $team->id, 'projekt_id' => $p->id, 'name' => $name, 'notiz' => $notiz !== null && trim($notiz) !== '' ? trim($notiz) : null,
            'sort_order' => (int) (FoodAlchemistProjektStandort::where('projekt_id', $p->id)->max('sort_order') ?? 0) + 10,
            'ist_pilot' => $erster,   // der erste Standort ist der Pilot, bis ein anderer gewählt wird
        ]);
        if ($erster) {
            $p->update(['pilot_standort_id' => $standort->id, 'updated_by' => $user?->id]);
        }

        return $standort;
    }

    /** Pilot-Standort setzen (genau einer je Projekt) oder null = keiner. */
    public function pilotSetzen(Team $team, ?User $user, int $projektId, ?int $standortId): FoodAlchemistProjekt
    {
        $this->pruefeSchreiben($team, $user, 'Pilot-Standort setzen');
        $p = $this->finde($team, $projektId);
        if ($standortId !== null && ! FoodAlchemistProjektStandort::where('projekt_id', $p->id)->whereKey($standortId)->exists()) {
            throw new \RuntimeException('Dieser Standort gehört nicht zum Projekt.');
        }
        DB::transaction(function () use ($p, $standortId, $user) {
            FoodAlchemistProjektStandort::where('projekt_id', $p->id)->update(['ist_pilot' => false]);
            if ($standortId !== null) {
                FoodAlchemistProjektStandort::where('projekt_id', $p->id)->whereKey($standortId)->update(['ist_pilot' => true]);
            }
            $p->update(['pilot_standort_id' => $standortId, 'updated_by' => $user?->id]);
        });

        return $p->refresh();
    }

    public function standortLoeschen(Team $team, ?User $user, int $projektId, int $standortId): void
    {
        $this->pruefeSchreiben($team, $user, 'Kunden-Standort löschen');
        $p = $this->finde($team, $projektId);
        $s = FoodAlchemistProjektStandort::where('projekt_id', $p->id)->findOrFail($standortId);
        $warPilot = (int) $p->pilot_standort_id === (int) $s->id;
        $s->delete();
        app(TeamProfilService::class)->loescheStandortProfil($team, (int) $s->id);   // Standortprofil geht mit dem Standort
        if ($warPilot) {
            $naechster = FoodAlchemistProjektStandort::where('projekt_id', $p->id)->orderBy('sort_order')->orderBy('id')->first();
            $this->pilotSetzen($team, $user, $p->id, $naechster?->id);
        }
    }

    // ── Paketlogik, hartes Gate, Foodbook (Spec 82 §9, PR 4) ──────────────────────────────

    /**
     * Was für das Gate noch fehlt: mindestens eine Paketstufe, ein Anlass und die Musterseite im Projekt-Canvas.
     *
     * @return list<string>
     */
    public function gateFehlt(FoodAlchemistProjekt $p): array
    {
        $canvas = app(CanvasService::class)->find('projekt', 'projekt', (int) $p->id);
        $eintraege = $canvas?->entries ?? collect();
        $fehlt = [];
        if ($eintraege->where('field_key', 'paket_stufen')->filter(fn ($e) => trim((string) $e->value) !== '')->isEmpty()) {
            $fehlt[] = 'mindestens eine Paketstufe';
        }
        if ($eintraege->where('field_key', 'paket_anlaesse')->filter(fn ($e) => trim((string) $e->value) !== '')->isEmpty()) {
            $fehlt[] = 'mindestens ein Anlass';
        }
        if (trim((string) $eintraege->firstWhere('field_key', 'musterseite')?->value) === '') {
            $fehlt[] = 'die Musterseite';
        }

        return $fehlt;
    }

    /** Gate: Struktur + Musterseite freigeben. Die Person ist ein CRM-Kontakt; ohne vollständige Paketlogik gibt es keine Freigabe. */
    public function strukturFreigeben(Team $team, ?User $user, int $projektId, int $kontaktId): FoodAlchemistProjekt
    {
        $this->pruefeSchreiben($team, $user, 'Struktur + Musterseite freigeben');
        $p = $this->finde($team, $projektId);
        $fehlt = $this->gateFehlt($p);
        if ($fehlt !== []) {
            throw new \RuntimeException('Freigabe nicht möglich, es fehlt noch: '.implode(', ', $fehlt).'.');
        }
        CrmKunden::pruefe($team, null, $kontaktId);
        $kontakt = class_exists(\Platform\Crm\Models\CrmContact::class) ? \Platform\Crm\Models\CrmContact::find($kontaktId) : null;
        if ($kontakt === null) {
            throw new \RuntimeException('Der CRM-Kontakt für die Freigabe fehlt.');
        }
        $p->update([
            'struktur_freigegeben_at' => now(), 'struktur_freigegeben_von_contact_id' => $kontakt->id,
            'struktur_freigegeben_von_name' => (string) ($kontakt->full_name ?? trim(($kontakt->first_name ?? '').' '.($kontakt->last_name ?? ''))), 'updated_by' => $user?->id,
        ]);

        return $p->refresh();
    }

    /** „Zurücknehmen“: das Gate ist wieder zu. Bereits angelegte Foodbooks bleiben, neue entstehen erst nach neuer Freigabe. */
    public function strukturZuruecknehmen(Team $team, ?User $user, int $projektId): FoodAlchemistProjekt
    {
        $this->pruefeSchreiben($team, $user, 'Freigabe zurücknehmen');
        $p = $this->finde($team, $projektId);
        $p->update(['struktur_freigegeben_at' => null, 'struktur_freigegeben_von_contact_id' => null, 'struktur_freigegeben_von_name' => null, 'updated_by' => $user?->id]);

        return $p->refresh();
    }

    /** Foodbooks dieser Akte (im Master-Team). */
    public function foodbooks(Team $team, int $projektId)
    {
        $p = $this->finde($team, $projektId);

        return FoodAlchemistFoodbook::where('team_id', $team->id)->where('projekt_id', $p->id)->orderBy('id')->get();
    }

    /**
     * Phase 5: Foodbook im Master-Team aus der Akte anlegen und vorbefüllen (Leitidee, Zweck, Pflicht-Konzepte, Spektrum, Umfang).
     * Das Gate steht am Modell; hier wird es vorab geprüft, damit die Meldung klar ist.
     */
    public function foodbookAnlegen(Team $team, ?User $user, int $projektId): FoodAlchemistFoodbook
    {
        $this->pruefeSchreiben($team, $user, 'Foodbook aus dem Projekt anlegen');
        $p = $this->finde($team, $projektId);
        if (! $p->strukturFreigegeben()) {
            throw new \RuntimeException('Gate: Struktur + Musterseite sind noch nicht freigegeben.');
        }
        $fb = app(FoodbookService::class)->create($team, ['label' => $p->name, 'crm_company_id' => $p->crm_company_id, 'projekt_id' => $p->id]);

        $canvasSvc = app(CanvasService::class);
        $akte = $canvasSvc->find('projekt', 'projekt', (int) $p->id);
        $liste = fn (string $key) => ($akte?->entries ?? collect())->where('field_key', $key)->sortBy('position')->pluck('value')->filter()->values();
        $skalar = fn (string $key) => $akte?->entries->firstWhere('field_key', $key)?->value;
        $stufen = $liste('paket_stufen');
        $anlaesse = $liste('paket_anlaesse');
        $vorbefuellung = [
            'leitidee' => $skalar('zielbild_wortlaut'),
            'zweck_anforderungen' => $p->ziel,
            'pflicht_konzepte' => $stufen->isEmpty() ? null : 'Paketstufen: '.$stufen->implode(', ').($anlaesse->isEmpty() ? '' : ' · Anlässe: '.$anlaesse->implode(', ')),
            'struktur_kapitel' => $skalar('musterseite') ? 'Musterseite: '.$skalar('musterseite') : null,
            'spektrum' => $anlaesse->isEmpty() ? null : $anlaesse->implode(', '),
            'umfang_rahmen' => $liste('umfang_linien')->isEmpty() ? null : $liste('umfang_linien')->implode(', '),
        ];
        $canvas = $canvasSvc->canvasFor($team, 'foodbook', 'foodbook', (int) $fb->id);
        foreach ($vorbefuellung as $key => $wert) {
            if ($wert !== null && trim((string) $wert) !== '') {
                $canvasSvc->setSkalar($canvas, $key, (string) $wert);
            }
        }
        if ($p->phase !== 'foodbook') {
            $p->update(['phase' => 'foodbook', 'updated_by' => $user?->id]);
        }

        return $fb->refresh();
    }
}
