<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Support\CrmKunden;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 §9/§13 B3 + G — Mandanten-Grundlage: Status, Modus (kuratiert|autark), CRM-Firma, Notiz je Kunden-Hauptteam.
 *
 * Rechte (über FaRechte, nie im Host):
 *  - Lesen sowie Status/CRM-Firma/Notiz pflegen: Plattform-Admin oder FA-Admin im Master-Team (B3 „Plattform-Admin bzw. Master“).
 *  - Modus umschalten: NUR Plattform-Admin, mit Protokoll (§13 G 2). Ein Master-Admin schaltet den Modus nicht um, weil er sich sonst
 *    selbst Einblick in einen autarken Mandanten verschaffen könnte.
 *
 * Es sind reine Verwaltungsdaten (§13 G): Diese Klasse liest nie Rezepte, Preise, Foodbooks, DNA oder Teamprofile eines Mandanten.
 * Ohne Zeile gilt aktiv + autark (Default autark). Der CRM-Firmen-Bezug kommt aus dem CRM des Master-Teams (wie CrmKunden: root-scoped, nur aktive).
 */
class MandantenService
{
    use PlattformAdminPruefung;

    public function __construct(
        private readonly FaRechte $rechte,
        private readonly PlattformProtokoll $protokoll,
    ) {}

    /** Lesen und Verwaltungsfelder pflegen: Plattform-Admin oder FA-Admin im Master-Team. */
    public function pruefeVerwaltung(?User $user, string $wofuer): User
    {
        if ($user === null || $user->isAiUser()) {
            throw new FaRechtFehltException(FaRolle::Admin, null, $wofuer.' (nur Plattform-Admin oder Admin im Master-Team)');
        }
        if ($this->rechte->istPlattformAdmin($user)) {
            return $user;
        }
        $masterId = MasterTeam::id();
        $master = $masterId !== null ? Team::find($masterId) : null;
        if ($master === null || ! $this->rechte->darf($user, $master, FaRolle::Admin)) {
            throw new FaRechtFehltException(FaRolle::Admin, $master !== null ? $this->rechte->rolle($user, $master) : null, $wofuer.' (nur Plattform-Admin oder Admin im Master-Team)');
        }

        return $user;
    }

    /**
     * Alle Mandanten (Kunden-Hauptteams ohne das Master-Team) mit ihren Verwaltungsdaten.
     *
     * @return list<array<string,mixed>>
     */
    public function uebersicht(): array
    {
        $zeilen = FoodAlchemistMandant::all()->keyBy('team_id');
        $master = MasterTeam::id();

        return Team::whereNull('parent_team_id')->orderBy('name')->get(['id', 'name'])
            ->reject(fn ($t) => $master !== null && (int) $t->id === $master)
            ->map(fn ($t) => $this->darstellung($t, $zeilen->get($t->id)))
            ->values()->all();
    }

    /** @return array<string,mixed> */
    public function zeige(Team $mandant): array
    {
        $this->pruefeMandant($mandant);

        return $this->darstellung($mandant, FoodAlchemistMandant::where('team_id', $mandant->id)->first());
    }

    /**
     * Modus des Kunden-Hauptteams, zu dem `$team` gehört (Unter-Teams erben). Ohne Zeile: autark.
     * Das Master-Team selbst zählt als „kuratiert“ (es sieht seine eigenen Inhalte).
     */
    public function modusVon(Team $team): string
    {
        if (MasterTeam::istMaster($team)) {
            return 'kuratiert';
        }
        $haupt = $this->rechte->kundenHauptTeam($team);

        return (string) (FoodAlchemistMandant::where('team_id', $haupt->id)->value('modus') ?? FoodAlchemistMandant::MODUS_STANDARD);
    }

    /**
     * Pflegt Verwaltungsfelder eines Mandanten. `$felder` kennt: status, modus, crm_company_id (null = lösen), notiz.
     * Nicht übergebene Felder bleiben unverändert. Modus nur durch Plattform-Admin.
     *
     * @param  array<string,mixed>  $felder
     * @return array<string,mixed>
     */
    public function setze(Team $mandant, array $felder, ?User $user): array
    {
        $this->pruefeMandant($mandant);
        $akteur = $this->pruefeVerwaltung($user, 'Mandant pflegen');
        $aendert = [];

        $zeile = FoodAlchemistMandant::firstOrNew(['team_id' => $mandant->id]);
        $vorher = ['status' => $zeile->status ?? FoodAlchemistMandant::STATUS_STANDARD, 'modus' => $zeile->modus ?? FoodAlchemistMandant::MODUS_STANDARD,
            'crm_company_id' => $zeile->crm_company_id, 'notiz' => $zeile->notiz];

        if (array_key_exists('status', $felder)) {
            $status = (string) $felder['status'];
            if (! in_array($status, FoodAlchemistMandant::STATUS, true)) {
                throw new \RuntimeException('Status muss projekt, aktiv oder beendet sein.');
            }
            $aendert['status'] = $status;
        }
        if (array_key_exists('modus', $felder)) {
            $modus = (string) $felder['modus'];
            if (! in_array($modus, FoodAlchemistMandant::MODI, true)) {
                throw new \RuntimeException('Modus muss kuratiert oder autark sein.');
            }
            $this->nurPlattformAdmin($akteur, 'Mandanten-Modus umschalten');
            $aendert['modus'] = $modus;
        }
        if (array_key_exists('crm_company_id', $felder)) {
            $firma = $felder['crm_company_id'] === null || $felder['crm_company_id'] === '' ? null : (int) $felder['crm_company_id'];
            $this->pruefeCrmFirma($firma);
            $aendert['crm_company_id'] = $firma;
        }
        if (array_key_exists('notiz', $felder)) {
            $notiz = trim((string) $felder['notiz']);
            $aendert['notiz'] = $notiz === '' ? null : $notiz;
        }

        $geaendert = array_filter($aendert, fn ($wert, $feld) => ($vorher[$feld] ?? null) !== $wert, ARRAY_FILTER_USE_BOTH);
        if ($geaendert === [] && $zeile->exists) {
            return $this->darstellung($mandant, $zeile);
        }

        \Platform\FoodAlchemist\Support\TeamSperre::flush();   // Status „beendet“ = Vollsperre; der Merker darf nicht veralten
        DB::transaction(function () use ($zeile, $aendert, $geaendert, $akteur, $mandant) {
            $zeile->fill($aendert);
            $zeile->team_id = $mandant->id;
            $zeile->status ??= FoodAlchemistMandant::STATUS_STANDARD;
            $zeile->modus ??= FoodAlchemistMandant::MODUS_STANDARD;
            $zeile->geaendert_von = $akteur->id;
            if (array_key_exists('modus', $geaendert)) {
                $zeile->modus_geaendert_von = $akteur->id;
                $zeile->modus_geaendert_at = now();
            }
            $zeile->save();
        });

        if (array_key_exists('modus', $geaendert)) {
            $this->protokoll->schreibe('mandant_modus_geaendert', ['team_id' => $mandant->id, 'team' => $mandant->name, 'von' => $vorher['modus'], 'nach' => $geaendert['modus']], $akteur, $zeile);
        }
        $uebrige = array_diff(array_keys($geaendert), ['modus']);
        if ($uebrige !== []) {
            // Nur die Feldnamen und die Verwaltungswerte; die Notiz bleibt als Freitext aus dem Protokoll heraus.
            $this->protokoll->schreibe('mandant_gepflegt', ['team_id' => $mandant->id, 'team' => $mandant->name, 'felder' => array_values($uebrige),
                'status' => $geaendert['status'] ?? null, 'crm_company_id' => array_key_exists('crm_company_id', $geaendert) ? $geaendert['crm_company_id'] : null], $akteur, $zeile);
        }

        return $this->zeige($mandant);
    }

    /** CRM-Firma muss im CRM des Master-Teams liegen (root-scoped, aktiv). null = lösen. */
    private function pruefeCrmFirma(?int $firmaId): void
    {
        if ($firmaId === null) {
            return;
        }
        if (! CrmKunden::verfuegbar()) {
            throw new \RuntimeException('Das CRM ist nicht verfügbar, die Firma lässt sich nicht verknüpfen.');
        }
        $masterId = MasterTeam::id();
        $master = $masterId !== null ? Team::find($masterId) : null;
        if ($master === null) {
            throw new \RuntimeException('Es ist kein Master-Team festgelegt. Die CRM-Firma stammt aus dem CRM des Master-Teams.');
        }
        $gefunden = \Platform\Crm\Models\CrmCompany::query()
            ->where('team_id', $master->getRootTeam()->id)->where('is_active', true)->whereKey($firmaId)->exists();
        if (! $gefunden) {
            throw new \RuntimeException('Die CRM-Firma gibt es im CRM des Master-Teams nicht (oder sie ist inaktiv).');
        }
    }

    private function pruefeMandant(Team $mandant): void
    {
        if (MasterTeam::istMaster($mandant)) {
            throw new \RuntimeException('Das Master-Team ist kein Mandant.');
        }
        if ((int) $this->rechte->kundenHauptTeam($mandant)->id !== (int) $mandant->id) {
            throw new \RuntimeException('Mandant ist das Haupt-Team des Kunden. Unter-Teams gehören zu ihrem Mandanten.');
        }
    }

    /** @return array<string,mixed> */
    private function darstellung(Team $team, ?FoodAlchemistMandant $z): array
    {
        $firma = null;
        if ($z?->crm_company_id !== null && CrmKunden::verfuegbar()) {
            $firma = \Platform\Crm\Models\CrmCompany::query()->whereKey($z->crm_company_id)->first()?->display_name;
        }

        return [
            'team_id' => (int) $team->id,
            'name' => (string) $team->name,
            'status' => $z->status ?? FoodAlchemistMandant::STATUS_STANDARD,
            'modus' => $z->modus ?? FoodAlchemistMandant::MODUS_STANDARD,
            'crm_company_id' => $z?->crm_company_id !== null ? (int) $z->crm_company_id : null,
            'crm_firma' => $firma,
            'notiz' => $z?->notiz,
            'modus_geaendert_von' => $z?->modus_geaendert_von,
            'modus_geaendert_am' => $z?->modus_geaendert_at?->toDateString(),
            'hat_zeile' => $z !== null,
        ];
    }
}
