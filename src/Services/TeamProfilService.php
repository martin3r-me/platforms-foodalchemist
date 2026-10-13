<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamProfile;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamProfileArea;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamProfileEquipment;
use Platform\FoodAlchemist\Models\FoodAlchemistProjektStandort;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\Profiltraeger;

/**
 * Spec 82 §6 — Teamprofil: was die Küche kann (Fakten, keine DNA).
 *
 * Wer pflegt: jedes Team sein EIGENES Profil (Rolle Kuratieren im Team). Das Oberteam liest die Profile seiner
 * Unter-Teams (Brille) und bearbeitet sie im jeweiligen Unter-Team nach dem Wechsel, so wie sein Oberhaupt in allen
 * Unter-Teams arbeitet (Spec 61). Schreiben geht deshalb nie fremd, nur ins übergebene Team. Das Modul „Mandanten"
 * des Masters liest später quer (Schritt 8) und ist hier nicht abgebildet.
 *
 * Dreiwertig (§12 E): vorhanden | nicht_vorhanden | unbekannt. Unbekannt = KEINE Zeile. Standardausstattung (Flag am
 * Vokabular) zählt ohne Zeile als vorhanden. Equipment-Zeilen werden hart gelöscht (unique je Team + Equipment).
 */
class TeamProfilService
{
    public const STATUS_VORHANDEN = 'vorhanden';

    public const STATUS_NICHT_VORHANDEN = 'nicht_vorhanden';

    public const STATUS_UNBEKANNT = 'unbekannt';

    /** Wie die DNA-Parameter der Konfiguration (Planung, Speisekarte, Generator). */
    public const CONVENIENCE = ['from_scratch', 'teil_convenience', 'voll_convenience'];

    public function __construct(private readonly FaRechte $rechte) {}

    /** Darf das Team `$betrachter` das Profil von `$ziel` lesen? Sich selbst oder ein Unter-Team (Brille). */
    public function darfLesen(Team $betrachter, Team|Profiltraeger $ziel): bool
    {
        $ziel = Profiltraeger::von($ziel)->team;

        return (int) $betrachter->id === (int) $ziel->id || in_array((int) $betrachter->id, $this->rechte->teamKette($ziel), true);
    }

    /**
     * Profil mit Flächen und Ausstattung. Leeres Profil, wenn noch nichts gepflegt ist.
     *
     * @return array{team_id:int, profil:array<string,mixed>, flaechen:list<array<string,mixed>>, ausstattung:list<array<string,mixed>>}
     */
    public function lies(Team $betrachter, Team|Profiltraeger $ziel): array
    {
        $t = $this->traeger($ziel);
        if (! $this->darfLesen($betrachter, $t)) {
            throw new FaRechtFehltException(FaRolle::Lesen, null, 'Teamprofil eines fremden Teams lesen');
        }
        $profil = FoodAlchemistTeamProfile::where($t->schluessel())->first();

        return [
            'team_id' => (int) $t->team->id,
            'standort_id' => $t->standortId,
            'profil' => [
                'max_pax_parallel' => $profil?->max_pax_parallel,
                'convenience_grad' => $profil?->convenience_grad,
                'notes' => $profil?->notes,
            ],
            'flaechen' => FoodAlchemistTeamProfileArea::where($t->schluessel())->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'max_pax' => $f->max_pax, 'saison_von' => $f->saison_von, 'saison_bis' => $f->saison_bis])->all(),
            'ausstattung' => $this->ausstattung($t)->values()->all(),
        ];
    }

    /**
     * Jeder sichtbare, aktive Vokabular-Eintrag mit Zustand im Profil.
     * `status` = was das Team angegeben hat (oder unbekannt), `wirksam` = was der Check daraus macht.
     *
     * @return Collection<int, array<string,mixed>>
     */
    public function ausstattung(Team|Profiltraeger $ziel): Collection
    {
        $t = $this->traeger($ziel);
        $zeilen = FoodAlchemistTeamProfileEquipment::where($t->schluessel())->get()->keyBy('equipment_id');

        return FoodAlchemistVocabKochequipment::visibleToTeam($t->team)->where('is_inactive', false)
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(function ($e) use ($zeilen) {
                $zeile = $zeilen->get($e->id);
                $status = $zeile?->status ?? self::STATUS_UNBEKANNT;

                return [
                    'equipment_id' => $e->id, 'name' => $e->name, 'gruppe' => $e->group_name,
                    'standard' => (bool) $e->is_standard, 'status' => $status,
                    'wirksam' => $status === self::STATUS_UNBEKANNT && $e->is_standard ? self::STATUS_VORHANDEN : $status,
                    'anzahl' => $zeile?->anzahl, 'note' => $zeile?->note,
                ];
            });
    }

    /** Wirksamer Zustand je Equipment-ID für den Machbarkeits-Check (mit Standardausstattung). @return array<int, string> */
    public function wirksameAusstattung(Team|Profiltraeger $ziel): array
    {
        return $this->ausstattung($ziel)->mapWithKeys(fn ($a) => [$a['equipment_id'] => $a['wirksam']])->all();
    }

    /** Profilfelder setzen (max_pax_parallel, convenience_grad, notes). Fehlende Schlüssel bleiben, null leert. */
    public function setzeProfil(Team|Profiltraeger $ziel, ?User $user, array $felder): FoodAlchemistTeamProfile
    {
        $t = $this->traeger($ziel);
        $this->rechte->pruefe($user, $t->team, FaRolle::Kuratieren, 'Teamprofil pflegen');
        $werte = [];
        if (array_key_exists('max_pax_parallel', $felder)) {
            $pax = $felder['max_pax_parallel'];
            if ($pax !== null && (! is_numeric($pax) || (int) $pax < 1)) {
                throw new \RuntimeException('max_pax_parallel muss eine ganze Zahl ab 1 sein (oder leer).');
            }
            $werte['max_pax_parallel'] = $pax === null ? null : (int) $pax;
        }
        if (array_key_exists('convenience_grad', $felder)) {
            $grad = $felder['convenience_grad'];
            if ($grad !== null && ! in_array($grad, self::CONVENIENCE, true)) {
                throw new \RuntimeException('convenience_grad: ' . implode(' | ', self::CONVENIENCE) . ' (oder leer).');
            }
            $werte['convenience_grad'] = $grad;
        }
        if (array_key_exists('notes', $felder)) {
            $werte['notes'] = $felder['notes'] === null ? null : (string) $felder['notes'];
        }
        $werte['updated_by'] = $user?->id;

        $profil = FoodAlchemistTeamProfile::withTrashed()->firstOrNew($t->schluessel());
        $profil->fill($werte);
        $profil->deleted_at = null;
        $profil->save();

        return $profil;
    }

    /** Equipment-Zustand setzen. `$status` null = unbekannt (Zeile wird hart gelöscht). */
    public function setzeEquipment(Team|Profiltraeger $ziel, ?User $user, int $equipmentId, ?string $status, ?int $anzahl = null, ?string $note = null): void
    {
        $t = $this->traeger($ziel);
        $this->rechte->pruefe($user, $t->team, FaRolle::Kuratieren, 'Teamprofil pflegen');
        if (! FoodAlchemistVocabKochequipment::visibleToTeam($t->team)->where('is_inactive', false)->whereKey($equipmentId)->exists()) {
            throw new \RuntimeException("Equipment {$equipmentId} ist im Vokabular nicht sichtbar oder inaktiv.");
        }
        $vorhanden = FoodAlchemistTeamProfileEquipment::withTrashed()->where($t->schluessel())->where('equipment_id', $equipmentId);

        if ($status === null || $status === self::STATUS_UNBEKANNT) {
            $vorhanden->get()->each->forceDelete();

            return;
        }
        if (! in_array($status, [self::STATUS_VORHANDEN, self::STATUS_NICHT_VORHANDEN], true)) {
            throw new \RuntimeException('status: vorhanden | nicht_vorhanden | null (= unbekannt).');
        }
        $zeile = $vorhanden->first() ?? new FoodAlchemistTeamProfileEquipment($t->schluessel() + ['equipment_id' => $equipmentId]);
        $zeile->fill(['status' => $status, 'anzahl' => $anzahl, 'note' => $note]);
        $zeile->deleted_at = null;
        $zeile->save();
    }

    /** Fläche anlegen (id null) oder ändern. */
    public function setzeFlaeche(Team|Profiltraeger $ziel, ?User $user, ?int $id, string $name, ?int $maxPax = null, ?int $saisonVon = null, ?int $saisonBis = null): FoodAlchemistTeamProfileArea
    {
        $t = $this->traeger($ziel);
        $this->rechte->pruefe($user, $t->team, FaRolle::Kuratieren, 'Teamprofil pflegen');
        if (trim($name) === '') {
            throw new \RuntimeException('Eine Fläche braucht einen Namen.');
        }
        if ($maxPax !== null && $maxPax < 1) {
            throw new \RuntimeException('max_pax muss ab 1 sein (oder leer).');
        }
        foreach ([$saisonVon, $saisonBis] as $m) {
            if ($m !== null && ($m < 1 || $m > 12)) {
                throw new \RuntimeException('Saison-Monate liegen zwischen 1 und 12.');
            }
        }
        $flaeche = $id !== null
            ? FoodAlchemistTeamProfileArea::where($t->schluessel())->findOrFail($id)
            : new FoodAlchemistTeamProfileArea($t->schluessel());
        $flaeche->fill(['name' => trim($name), 'max_pax' => $maxPax, 'saison_von' => $saisonVon, 'saison_bis' => $saisonBis]);
        $flaeche->save();

        return $flaeche;
    }

    public function loescheFlaeche(Team|Profiltraeger $ziel, ?User $user, int $id): void
    {
        $t = $this->traeger($ziel);
        $this->rechte->pruefe($user, $t->team, FaRolle::Kuratieren, 'Teamprofil pflegen');
        FoodAlchemistTeamProfileArea::where($t->schluessel())->findOrFail($id)->forceDelete();
    }

    /**
     * Lizenz-Übernahme (Spec 82 §9): Profil eines Kunden-Standorts als TEAMPROFIL in ein Team kopieren (Kennzahlen, Ausstattung, Flächen).
     * Kopie, nicht live. Hat das Zielteam schon Profildaten, bleiben sie unberührt (wirft). Ohne Rechteprüfung: der Aufrufer
     * (ProjektUebernahmeService) ist Plattform-Admin.
     *
     * @return array{kennzahlen: bool, geraete: int, flaechen: int}
     */
    public function kopiereStandortProfil(Team $master, int $standortId, Team $ziel): array
    {
        $quelle = $this->traeger(Profiltraeger::standort($master, $standortId));
        $ziel = Profiltraeger::team($ziel);
        if (FoodAlchemistTeamProfile::withTrashed()->where($ziel->schluessel())->exists()
            || FoodAlchemistTeamProfileEquipment::withTrashed()->where($ziel->schluessel())->exists()
            || FoodAlchemistTeamProfileArea::withTrashed()->where($ziel->schluessel())->exists()) {
            throw new \RuntimeException('Das Zielteam hat schon ein Teamprofil, es wird nicht überschrieben.');
        }
        $profil = FoodAlchemistTeamProfile::where($quelle->schluessel())->first();
        if ($profil !== null) {
            FoodAlchemistTeamProfile::create($ziel->schluessel() + $profil->only(['max_pax_parallel', 'convenience_grad', 'notes']));
        }
        $geraete = FoodAlchemistTeamProfileEquipment::where($quelle->schluessel())->get();
        foreach ($geraete as $g) {
            FoodAlchemistTeamProfileEquipment::create($ziel->schluessel() + $g->only(['equipment_id', 'status', 'anzahl', 'note']));
        }
        $flaechen = FoodAlchemistTeamProfileArea::where($quelle->schluessel())->get();
        foreach ($flaechen as $f) {
            FoodAlchemistTeamProfileArea::create($ziel->schluessel() + $f->only(['name', 'max_pax', 'saison_von', 'saison_bis', 'sort_order']));
        }

        return ['kennzahlen' => $profil !== null, 'geraete' => $geraete->count(), 'flaechen' => $flaechen->count()];
    }

    /** Alle Profildaten eines Kunden-Standorts entfernen (beim Löschen des Standorts). Hart, es gibt kein Zurück. */
    public function loescheStandortProfil(Team $master, int $standortId): void
    {
        $schluessel = Profiltraeger::standort($master, $standortId)->schluessel();
        foreach ([FoodAlchemistTeamProfile::class, FoodAlchemistTeamProfileEquipment::class, FoodAlchemistTeamProfileArea::class] as $modell) {
            $modell::withTrashed()->where($schluessel)->get()->each->forceDelete();
        }
    }

    /**
     * Team oder Kunden-Standort als Träger prüfen: ein Kunden-Standort gibt es nur im Master-Team und nur, wenn er dort angelegt ist.
     * Sonst könnte ein Aufrufer mit erfundener standort_id Profilzeilen in ein fremdes Team schreiben.
     */
    private function traeger(Team|Profiltraeger $ziel): Profiltraeger
    {
        $t = Profiltraeger::von($ziel);
        if ($t->istStandort() && (! MasterTeam::istMaster($t->team)
            || ! FoodAlchemistProjektStandort::where('team_id', $t->team->id)->whereKey($t->standortId)->exists())) {
            throw new \RuntimeException('Kunden-Standort nicht gefunden (nur im Master-Team).');
        }

        return $t;
    }
}
