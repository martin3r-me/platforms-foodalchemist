<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\RecomputeTeamRecipesJob;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\VocabularyService;

/**
 * M1-07: Kalkulations-Defaults je Team auf GL-02-Buchungsebene —
 * Garverlust + Putzverlust (je WG, Recompute-Kaskade), MwSt, Rundung.
 * Die Herstellkosten (Zuschlagsschema/Fixkosten/Bezugsbasen/Marge) wohnen
 * seit Phase 4 (2026-06-15) in der eigenen Sektion `Herstellkosten`.
 */
class Kalkulation extends Component
{
    /** @var array<string, string> WG-Code|'*' => Prozent */
    public array $garverlust = [];

    /** @var array<string, string> WG-Code|'*' => Prozent */
    public array $putzverlust = [];

    public array $mwst = [];

    public array $rundung = [];

    public ?string $meldung = null;

    public function mount(): void
    {
        $settings = app(TeamSettingsService::class)->for($this->team());
        $this->garverlust = array_map(strval(...), $settings->cooking_loss_defaults ?? []);
        $this->putzverlust = array_map(strval(...), $settings->trimming_loss_defaults ?? []);
        $this->mwst = array_replace(TeamSettingsService::MWST_DEFAULTS, $settings->vat_defaults ?? []);
        $this->rundung = array_replace(TeamSettingsService::RUNDUNG_DEFAULTS, $settings->rundungsregeln ?? []);
    }

    public function speichern(): void
    {
        $verlustClean = fn (array $werte) => collect($werte)
            ->map(fn ($v) => trim(str_replace(',', '.', (string) $v)))
            ->filter(fn ($v) => $v !== '' && is_numeric($v))
            ->map(fn ($v) => (float) $v)->all();

        $svc = app(TeamSettingsService::class);
        $team = $this->team();
        $vorher = $svc->for($team);
        $gar = $verlustClean($this->garverlust) ?: null;
        $putz = $verlustClean($this->putzverlust) ?: null;
        // Nur eine echte Änderung der Verluste rechnet Rezepte neu — MwSt/Rundung allein nicht.
        $verlustGeaendert = self::normiert($gar) !== self::normiert($vorher->cooking_loss_defaults)
            || self::normiert($putz) !== self::normiert($vorher->trimming_loss_defaults);

        $svc->update($team, [
            'cooking_loss_defaults' => $gar,
            'trimming_loss_defaults' => $putz,
            'vat_defaults' => [
                'regulaer' => (float) str_replace(',', '.', (string) $this->mwst['regulaer']),
                'ermaessigt' => (float) str_replace(',', '.', (string) $this->mwst['ermaessigt']),
                'default_satz' => in_array($this->mwst['default_satz'], ['regulaer', 'ermaessigt'], true) ? $this->mwst['default_satz'] : 'ermaessigt',
            ],
            'rundungsregeln' => [
                'nachkommastellen' => max(0, min(4, (int) $this->rundung['nachkommastellen'])),
                'mode' => in_array($this->rundung['mode'], \Platform\FoodAlchemist\Services\CatalogPricingService::ROUNDING_MODES, true)
                    ? $this->rundung['mode'] : 'kaufmaennisch',
            ],
        ]);

        if ($verlustGeaendert) {
            // Rezepte (Ausbeute → EK/kg) des Teams + der erbenden Kind-Teams, danach die Preis-Kaskade.
            $n = RecomputeTeamRecipesJob::anzahlRezepte($team->id);
            RecomputeTeamRecipesJob::dispatch($team->id);
            $this->meldung = "Gespeichert — {$n} Rezepte (inkl. erbender Teams) werden mit den neuen Verlusten neu gerechnet.";

            return;
        }
        app(\Platform\FoodAlchemist\Services\PricingCascadeService::class)->recomputeTeam($team);
        $this->meldung = 'Gespeichert — Preise neu gerechnet.';
    }

    /** Vergleichsform einer Verlust-Map: Schlüssel als String sortiert, Werte als float. */
    private static function normiert(?array $map): array
    {
        $out = [];
        foreach ($map ?? [] as $k => $v) {
            $out[(string) $k] = (float) $v;
        }
        ksort($out);

        return $out;
    }

    public function render(VocabularyService $vocab)
    {
        $svc = app(TeamSettingsService::class);
        $team = $this->team();
        // Eigene Map leer + Vorfahr hat eine → geerbt. Die geerbten Werte stehen als Platzhalter
        // in den Feldern; sobald das Team einen eigenen Wert speichert, gilt NUR seine Map.
        $geerbt = function (string $spalte) use ($svc, $team): ?array {
            $quelle = $svc->quelleTeamId($team, $spalte);
            if ($quelle === null || $quelle === (int) $team->id) {
                return null;
            }

            return ['von' => Team::find($quelle)?->name ?? "Team {$quelle}", 'werte' => (array) $svc->rohWert($team, $spalte)];
        };

        return view('foodalchemist::livewire.settings.kalkulation', [
            'warengruppen' => $vocab->listWarengruppen($team),
            'geerbtGar' => $geerbt('cooking_loss_defaults'),
            'geerbtPutz' => $geerbt('trimming_loss_defaults'),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
