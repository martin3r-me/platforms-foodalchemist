<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Einstellungen › Übersicht (Neuordnung 23.10., E-2): was ist eingerichtet, was fehlt. Nur zählbare Fakten aus den Tabellen des
 * Teams (eigene Zeilen, nicht geerbte), keine geschätzten Prozente. Eine Zeile je Bereich, null = kein Status (nur Link).
 *
 * zustand: ok = eingerichtet · offen = fehlt noch · info = Vorgabe der Plattform bzw. reine Auskunft.
 */
final class EinrichtungsStatus
{
    /** @return array{text: string, zustand: string}|null */
    public static function fuer(string $sektion, Team $team): ?array
    {
        $tid = (int) $team->id;
        $eigene = fn (string $klasse) => $klasse::query()->withoutGlobalScopes()->where('team_id', $tid)
            ->when(in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($klasse), true), fn ($q) => $q->whereNull('deleted_at'))
            ->count();
        $zahl = fn (int $n, string $eins, string $mehr, string $keins) => $n > 0
            ? ['text' => $n . ' ' . ($n === 1 ? $eins : $mehr), 'zustand' => 'ok']
            : ['text' => $keins, 'zustand' => 'offen'];

        return match ($sektion) {
            'zugriffsrechte' => ['text' => count(app(FaRechte::class)->mitglieder($team)) . ' Mitglieder', 'zustand' => 'info'],
            'unter-teams' => ($n = Team::query()->where('parent_team_id', $tid)->count()) > 0
                ? ['text' => $n . ($n === 1 ? ' Unter-Team' : ' Unter-Teams'), 'zustand' => 'ok'] : ['text' => 'keine Unter-Teams', 'zustand' => 'info'],
            'teamprofil' => DB::table('foodalchemist_team_profiles')->where('team_id', $tid)->whereNull('deleted_at')->exists()
                ? ['text' => 'Profil angelegt', 'zustand' => 'ok'] : ['text' => 'noch nicht ausgefüllt', 'zustand' => 'offen'],
            'posten' => $zahl($eigene(\Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::class), 'Posten', 'Posten', 'keine Posten'),
            'rollen' => $zahl($eigene(\Platform\FoodAlchemist\Models\FoodAlchemistKitchenRole::class), 'Küchenrolle', 'Küchenrollen', 'keine Küchenrollen'),
            'einkauf' => $zahl($eigene(\Platform\FoodAlchemist\Models\FoodAlchemistStammLieferant::class), 'Stamm-Lieferant', 'Stamm-Lieferanten', 'keine Stamm-Lieferanten'),
            'betriebe' => $zahl($eigene(\Platform\FoodAlchemist\Models\FoodAlchemistOutlet::class), 'Kalkulationsprofil', 'Kalkulationsprofile', 'kein Kalkulationsprofil'),
            'food-dna' => self::dnaStatus($team),
            'schreibstile' => $zahl($eigene(\Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::class), 'Schreibstil', 'Schreibstile', 'kein eigener Schreibstil'),
            'praesentations-designs' => $zahl($eigene(\Platform\FoodAlchemist\Models\FoodAlchemistPresentationDesign::class), 'Design', 'Designs', 'kein eigenes Design'),
            'etiketten' => $zahl($eigene(\Platform\FoodAlchemist\Models\FoodAlchemistLabelTemplate::class), 'Etikett-Vorlage', 'Etikett-Vorlagen', 'keine Etikett-Vorlage'),
            'taxonomie' => $zahl($eigene(\Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory::class), 'eigene Rezept-Kategorie', 'eigene Rezept-Kategorien', 'nur Kategorien der Plattform'),
            'einheiten', 'warengruppen' => ['text' => 'Vorgabe der Plattform', 'zustand' => 'info'],
            'ki' => self::kiStatus($team),
            default => null,
        };
    }

    /** @return array{text: string, zustand: string} */
    private static function dnaStatus(Team $team): array
    {
        $svc = app(CanvasService::class);
        $canvas = $svc->find('food_dna', 'team', (int) $team->id);
        $n = $canvas === null ? 0 : collect($svc->werte($canvas, $team))->filter(fn ($w) => $w !== null && $w !== '' && $w !== [])->count();

        return $n > 0 ? ['text' => $n . ($n === 1 ? ' Feld ausgefüllt' : ' Felder ausgefüllt'), 'zustand' => 'ok'] : ['text' => 'noch leer', 'zustand' => 'offen'];
    }

    /** @return array{text: string, zustand: string} */
    private static function kiStatus(Team $team): array
    {
        if (! app(TeamSettingsService::class)->kiAktiv($team)) {
            return ['text' => 'ausgeschaltet', 'zustand' => 'offen'];
        }
        $n = DB::table('foodalchemist_ai_call_log')->where('team_id', $team->id)->where('created_at', '>=', now()->startOfMonth())->count();

        return ['text' => 'eingeschaltet · ' . number_format($n, 0, ',', '.') . ' Aufrufe diesen Monat', 'zustand' => 'ok'];
    }
}
