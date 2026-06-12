<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamSetting;

/**
 * M1-05 + M1-07: Typisierter Zugriff auf die Team-Einstellungen.
 *
 * Fehlende Zeile/Felder ⇒ Code-Defaults (kein Pflicht-Seeding). Konsumenten:
 * LeadLaService (M3-06) liest leadLaStrategie()/leadLaPrioritaeten(),
 * RecomputeService (M4-03) liest garverlustDefault()/mwst()/rundung().
 */
class TeamSettingsService
{
    public const MWST_DEFAULTS = ['regulaer' => 19.0, 'ermaessigt' => 7.0, 'default_satz' => 'ermaessigt'];

    public const RUNDUNG_DEFAULTS = ['nachkommastellen' => 2, 'modus' => 'kaufmaennisch'];

    /** M7-07: Küchen-Typ-Vokabular (commands.rs:12590-Pendant, team-scoped statt global). */
    public const KUECHEN_TYPEN = [
        'restaurant' => 'Restaurant (à la carte, kleine Chargen, frische Technik)',
        'grosskueche' => 'Großküche (große Chargen, robuste Prozesse, Teil-Convenience üblich)',
        'catering' => 'Catering (transportstabil, regenerierbar, Chargen nach Auftrag)',
        'hotel' => 'Hotel (Bankett + à la carte gemischt, breites Spektrum)',
        'boutique_patisserie' => 'Boutique-Pâtisserie (Präzision, kleine Chargen, from scratch)',
    ];

    /** M7-08: Kill-Switch — false stoppt ALLE KI-Calls des Teams (Gateway-Guard). */
    public function kiAktiv(Team $team): bool
    {
        return (bool) ($this->for($team)->ki_aktiv ?? true);
    }

    public function kuechenTyp(Team $team): ?string
    {
        $typ = $this->for($team)->kuechen_typ;

        return isset(self::KUECHEN_TYPEN[$typ]) ? $typ : null;
    }

    public function for(Team $team): FoodAlchemistTeamSetting
    {
        return FoodAlchemistTeamSetting::firstOrNew(['team_id' => $team->id]);
    }

    public function update(Team $team, array $attributes): FoodAlchemistTeamSetting
    {
        $settings = $this->for($team);
        $settings->fill($attributes)->save();

        return $settings;
    }

    public function leadLaStrategie(Team $team): LeadLaStrategie
    {
        return $this->for($team)->lead_la_strategie ?? LeadLaStrategie::StammLieferant; // V-27-Default = Ist-Verhalten (GL-03 §6)
    }

    /** @return array<int> geordnete supplier_ids (nur Strategie prioritaets_kette) */
    public function leadLaPrioritaeten(Team $team): array
    {
        return $this->for($team)->lead_la_prioritaeten ?? [];
    }

    public function ausweichKetteAnzeigen(Team $team): bool
    {
        return $this->for($team)->ausweich_kette_anzeigen ?? false;
    }

    /** Garverlust-Default in % je GP-Klasse (Warengruppen-Code), '*' = global. */
    public function garverlustDefault(Team $team, ?string $warengruppeCode = null): ?float
    {
        $defaults = $this->for($team)->garverlust_defaults ?? [];

        $wert = $defaults[$warengruppeCode] ?? $defaults['*'] ?? null;

        return $wert === null ? null : (float) $wert;
    }

    /** @return array{regulaer: float, ermaessigt: float, default_satz: string} */
    public function mwst(Team $team): array
    {
        return array_replace(self::MWST_DEFAULTS, $this->for($team)->mwst_defaults ?? []);
    }

    /** @return array{nachkommastellen: int, modus: string} */
    public function rundung(Team $team): array
    {
        return array_replace(self::RUNDUNG_DEFAULTS, $this->for($team)->rundungsregeln ?? []);
    }
}
