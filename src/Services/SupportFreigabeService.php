<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistSupportFreigabe;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 84 §4.4 (E3, Dominique 16.10.) · Support-Zugang auf Freigabe.
 *
 * Kein pauschaler Admin-Zugriff auf Mandanten-Inhalte (Spec 82 §13 G bleibt). Der Mandant — Owner/Admin seines Kunden-Hauptteams —
 * erteilt einem bestimmten oder allen Plattform-Admins befristet Zugang (Standard 48 h, einstellbar, höchstens 7 Tage), jederzeit
 * widerrufbar. Die Freigabe gilt für das Hauptteam und seine Unter-Teams. Freigabe, Widerruf und JEDER Zugriff im Support-Zugang
 * gehen als Plattform-Protokoll an die Freigabe-Zeile.
 *
 * Wirkung: {@see \Platform\FoodAlchemist\Support\InhaltsSperre::istGesperrt} hebt die Sperre für die Dauer auf; damit bekommt der Admin
 * über {@see FaRechte::rolle} seine Admin-Rolle im Mandanten (lesen und ändern). Ohne Freigabe: gesperrt wie bisher.
 *
 * Gemerkt je Request/Job (scoped): `rolle()` fragt die Sperre sehr oft.
 */
class SupportFreigabeService
{
    public const STUNDEN_MAX = 168;

    /** @var array<string, ?FoodAlchemistSupportFreigabe> "hauptteam:user" → aktive Freigabe */
    private array $merker = [];

    public function __construct(private FaRechte $rechte, private PlattformProtokoll $protokoll) {}

    public static function stundenStandard(): int
    {
        return max(1, min(self::STUNDEN_MAX, (int) config('foodalchemist.support_freigabe.stunden', 48)));
    }

    /** @throws FaRechtFehltException|\RuntimeException */
    public function erteilen(Team $team, User $actor, ?int $stunden = null, ?int $fuerUserId = null, ?string $grund = null): FoodAlchemistSupportFreigabe
    {
        $haupt = $this->hauptteamFuerVerwaltung($team, $actor, 'Support-Zugang erteilen');
        $stunden = $stunden ?? self::stundenStandard();
        if ($stunden < 1 || $stunden > self::STUNDEN_MAX) {
            throw new \RuntimeException('Dauer: 1 bis '.self::STUNDEN_MAX.' Stunden.');
        }
        $f = FoodAlchemistSupportFreigabe::create([
            'team_id' => $haupt->id,
            'fuer_user_id' => $fuerUserId,
            'erteilt_von' => $actor->id,
            'gueltig_bis' => now()->addHours($stunden),
            'grund' => $grund !== null && trim($grund) !== '' ? mb_substr(trim($grund), 0, 255) : null,
        ]);
        $this->protokoll->schreibe('support_zugang_erteilt', ['team_id' => $haupt->id, 'fuer_user_id' => $fuerUserId, 'stunden' => $stunden], $actor, $f);
        $this->merker = [];

        return $f;
    }

    /** @throws FaRechtFehltException|\RuntimeException */
    public function widerrufen(Team $team, User $actor, int $freigabeId): FoodAlchemistSupportFreigabe
    {
        $haupt = $this->hauptteamFuerVerwaltung($team, $actor, 'Support-Zugang widerrufen');
        $f = FoodAlchemistSupportFreigabe::where('team_id', $haupt->id)->find($freigabeId);
        if ($f === null) {
            throw new \RuntimeException('Freigabe nicht gefunden.');
        }
        if ($f->widerrufen_am === null) {
            $f->update(['widerrufen_am' => now(), 'widerrufen_von' => $actor->id]);
            $this->protokoll->schreibe('support_zugang_widerrufen', ['team_id' => $haupt->id], $actor, $f);
        }
        $this->merker = [];

        return $f;
    }

    /** @return \Illuminate\Support\Collection<int, FoodAlchemistSupportFreigabe> alle Freigaben des Hauptteams, neueste zuerst */
    public function liste(Team $team, User $actor): \Illuminate\Support\Collection
    {
        $haupt = $this->hauptteamFuerVerwaltung($team, $actor, 'Support-Zugang ansehen');

        return FoodAlchemistSupportFreigabe::where('team_id', $haupt->id)->orderByDesc('id')->get();
    }

    /** Aktive Freigabe, die `$admin` Zugang zu `$team` gibt — oder null. Ohne Rechteprüfung (für die Sperre). */
    public function aktiveFuer(Team $team, User $admin): ?FoodAlchemistSupportFreigabe
    {
        $haupt = $this->rechte->kundenHauptTeam($team);
        $schluessel = $haupt->id.':'.$admin->id;
        if (! array_key_exists($schluessel, $this->merker)) {
            $this->merker[$schluessel] = FoodAlchemistSupportFreigabe::where('team_id', $haupt->id)
                ->whereNull('widerrufen_am')->where('gueltig_bis', '>', now())
                ->where(fn ($q) => $q->whereNull('fuer_user_id')->orWhere('fuer_user_id', $admin->id))
                ->orderByDesc('gueltig_bis')->first();
        }

        return $this->merker[$schluessel];
    }

    /** Ein Zugriff im Support-Zugang (Seite, Livewire-Aktion, MCP-Tool) → Protokollzeile an der Freigabe. */
    public function protokolliereZugriff(FoodAlchemistSupportFreigabe $f, User $admin, Team $team, string $eingang, ?bool $schreibend = null): void
    {
        $this->protokoll->schreibe('support_zugriff', array_filter([
            'team_id' => $team->id, 'eingang' => $eingang, 'schreibend' => $schreibend,
        ], fn ($v) => $v !== null), $admin, $f);
    }

    /** Owner/Admin des Kunden-Hauptteams (nie das Master-Team, nie ein Plattform-Admin ohne echte Mitgliedschaft). */
    private function hauptteamFuerVerwaltung(Team $team, User $actor, string $wofuer): Team
    {
        $haupt = $this->rechte->kundenHauptTeam($team);
        if (MasterTeam::istMaster($haupt)) {
            throw new \RuntimeException('Das Master-Team braucht keinen Support-Zugang.');
        }
        if (! $this->rechte->istMitglied($actor, $haupt) || ! $this->rechte->darf($actor, $haupt, FaRolle::Admin)) {
            throw new FaRechtFehltException(FaRolle::Admin, $this->rechte->rolle($actor, $haupt), $wofuer.' (nur Owner/Admin des Hauptteams)');
        }

        return $haupt;
    }
}
