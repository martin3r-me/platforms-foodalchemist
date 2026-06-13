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

    /** M12: Gemeinkosten-Zuschlag % auf den Wareneinsatz (HK1 → HK2, D-HK-1). */
    public function hk2Zuschlag(Team $team): float
    {
        return (float) ($this->for($team)->hk2_zuschlag_pct ?? 0);
    }

    // ── M-K1 / Doc 16: Kalkulations-Block-Schema ─────────────────────────────

    public const STUNDENSATZ_DEFAULT = 35.0;

    public const MARGE_DEFAULT = 15.0;

    /**
     * Kanonisches Default-Schema (feste benannte Blöcke, D-K1). Reihenfolge = `sort`.
     * Typen: pct_we (% auf Wareneinsatz) · pct_hk (% auf laufende HK) ·
     * eur_pro_portion (Fixbetrag/Portion) · arbeitszeit (min/60 × Stundensatz).
     *
     * @return list<array{key:string, label:string, typ:string, wert:float, aktiv:bool, sort:int}>
     */
    public function defaultSchema(Team $team): array
    {
        return [
            ['key' => 'lohn', 'label' => 'Lohn / Produktion', 'typ' => 'arbeitszeit', 'wert' => 0.0, 'aktiv' => true, 'sort' => 10],
            ['key' => 'verpackung', 'label' => 'Verpackung', 'typ' => 'eur_pro_portion', 'wert' => 0.25, 'aktiv' => false, 'sort' => 20],
            ['key' => 'schwund', 'label' => 'Schwund', 'typ' => 'pct_we', 'wert' => 0.0, 'aktiv' => true, 'sort' => 30],
            ['key' => 'lager', 'label' => 'Lager', 'typ' => 'eur_pro_portion', 'wert' => 0.0, 'aktiv' => false, 'sort' => 40],
            // Gemeinkosten erbt den M12-Wert (rückwärtskompatibel).
            ['key' => 'gemeinkosten', 'label' => 'Gemeinkosten', 'typ' => 'pct_hk', 'wert' => $this->hk2Zuschlag($team), 'aktiv' => true, 'sort' => 50],
        ];
    }

    /**
     * Aktives Kalkulations-Schema (gespeichert oder Default), nach `sort` geordnet,
     * nur normalisierte Blöcke. arbeitszeit-Block ohne Wert → Default-Stundensatz.
     *
     * @return list<array{key:string, label:string, typ:string, wert:float, aktiv:bool, sort:int}>
     */
    public function kalkulationSchema(Team $team): array
    {
        $erlaubteTypen = ['pct_we', 'pct_hk', 'eur_pro_portion', 'arbeitszeit'];
        $schema = $this->for($team)->kalkulation_schema;
        if (! is_array($schema) || $schema === []) {
            $schema = $this->defaultSchema($team);
        }
        $norm = [];
        foreach ($schema as $b) {
            if (! is_array($b) || ! in_array($b['typ'] ?? '', $erlaubteTypen, true)) {
                continue;
            }
            $norm[] = [
                'key' => (string) ($b['key'] ?? ''),
                'label' => (string) ($b['label'] ?? ($b['key'] ?? 'Block')),
                'typ' => $b['typ'],
                'wert' => (float) ($b['wert'] ?? 0),
                'aktiv' => (bool) ($b['aktiv'] ?? true),
                'sort' => (int) ($b['sort'] ?? 100),
            ];
        }
        usort($norm, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        return $norm;
    }

    /** Default-Lohnsatz €/h für den arbeitszeit-Block (D-K2: ein Team-Satz). */
    public function stundensatz(Team $team): float
    {
        $v = $this->for($team)->stundensatz_eur;

        return $v !== null && (float) $v > 0 ? (float) $v : self::STUNDENSATZ_DEFAULT;
    }

    /** Marge % auf die HK → VK-Vorschlag (Doc 16). */
    public function margePct(Team $team): float
    {
        $v = $this->for($team)->marge_pct;

        return $v !== null ? (float) $v : self::MARGE_DEFAULT;
    }
}
