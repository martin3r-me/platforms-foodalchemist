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

    public const RUNDUNG_DEFAULTS = ['nachkommastellen' => 2, 'mode' => 'kaufmaennisch'];

    /** Phase 5: Default-Typ-Farben (GP violett · Basisrezept teal · Gericht amber) — Hex. */
    public const TYP_FARBEN_DEFAULTS = ['gp' => '#7c3aed', 'basisrezept' => '#0d9488', 'gericht' => '#d97706'];

    /** M7-07: Küchen-Typ-Vokabular (commands.rs:12590-Pendant, team-scoped statt global). */
    public const KUECHEN_TYPEN = [
        'restaurant' => 'Restaurant (à la carte, kleine Chargen, frische Technik)',
        'grosskueche' => 'Großküche (große Chargen, robuste Prozesse, Teil-Convenience üblich)',
        'catering' => 'Catering (transportstabil, regenerierbar, Chargen nach Auftrag)',
        'hotel' => 'Hotel (Bankett + à la carte gemischt, breites Spektrum)',
        'boutique_patisserie' => 'Boutique-Pâtisserie (Präzision, kleine Chargen, from scratch)',
    ];

    /**
     * Segment (Bespielung) je Küchen-Typ — die Achse, an der bei der Planung alles hängt
     * (Portionen, Preis, Komplexität, Ton). Abgeleitet aus kitchen_type; null wenn ungesetzt.
     * niveau/convenience = Default-Erwartung des Segments (Vokabular der KI-Rezept-Regler,
     * GeneratorModal: niveau ∈ haute_cuisine|gehoben|klassisch, convenience ∈
     * from_scratch|teil_convenience|voll_convenience) — als Planungs-/Generierungs-Leitplanke.
     */
    public const SEGMENTE = [
        'restaurant' => ['key' => 'fine_dining', 'label' => 'Fine Dining / à la carte', 'niveau' => 'gehoben', 'convenience' => 'from_scratch'],
        'boutique_patisserie' => ['key' => 'fine_dining', 'label' => 'Fine Dining / Pâtisserie', 'niveau' => 'haute_cuisine', 'convenience' => 'from_scratch'],
        'catering' => ['key' => 'event_catering', 'label' => 'Event-Catering', 'niveau' => 'gehoben', 'convenience' => 'teil_convenience'],
        'hotel' => ['key' => 'event_catering', 'label' => 'Event-Catering / Bankett', 'niveau' => 'gehoben', 'convenience' => 'teil_convenience'],
        'grosskueche' => ['key' => 'volumen', 'label' => 'Volumen / Gemeinschaftsverpflegung', 'niveau' => 'klassisch', 'convenience' => 'teil_convenience'],
    ];

    /** Menschliche Labels fürs Segment-Badge (Vokabular = KI-Rezept-Generator). */
    public const NIVEAU_LABEL = ['haute_cuisine' => 'Haute Cuisine', 'gehoben' => 'Gehoben', 'klassisch' => 'Klassisch'];

    public const CONVENIENCE_LABEL = ['from_scratch' => 'From Scratch', 'teil_convenience' => 'Teil-Convenience', 'voll_convenience' => 'Voll-Convenience'];

    /**
     * #390 (2026-06-17): Per-Setting-Vererbungs-Policy über die Team-Hierarchie (Org→Team).
     * Hier gelistete DB-Spalten werden vererbt: leeres Feld am Team erbt vom nächsten Vorfahr
     * (Org), erstes Nicht-NULL gewinnt, Code-Default als Boden. NICHT gelistete Spalten sind
     * team-lokal (lesen NUR die eigene Zeile — z. B. Marge/Stundensatz/Küchen-Profil, Dominique).
     * Projekt-Ebene bewusst noch nicht (kommt mit #389 Food DNA Canvas).
     *
     * @var array<string, true>
     */
    public const ORG_VERERBT = [
        'vat_defaults'  => true,   // MwSt-Sätze: org-weit einheitlich (Dominique-Beispiel)
        'rundungsregeln' => true,   // Rundungs-Konvention: org-weite Buchhaltungsregel
        'type_colors'     => true,   // Branding-Farben: org-weit konsistent
    ];

    /**
     * #390: Roh-Wert einer Settings-Spalte mit Per-Setting-Vererbungs-Policy.
     * Org-vererbte Spalte ⇒ self→…→root, erstes Nicht-NULL (≠[]); team-lokale Spalte ⇒ nur eigene Zeile.
     * Rückgabe NULL = nicht gesetzt → Aufrufer setzt seinen Code-Default.
     */
    public function rohWert(Team $team, string $spalte): mixed
    {
        if (! array_key_exists($spalte, self::ORG_VERERBT)) {
            return $this->for($team)->{$spalte};
        }
        foreach ($this->ahnenZeilen($team) as $row) {
            $wert = $row->{$spalte};
            if ($wert !== null && $wert !== []) {
                return $wert;
            }
        }

        return null;
    }

    /**
     * #390: Welches Team liefert den Wert einer (org-vererbten) Spalte? Für UI-Badges
     * („geerbt von Org" vs „eigener Override"). NULL = niemand → Code-Default greift.
     */
    public function quelleTeamId(Team $team, string $spalte): ?int
    {
        if (! array_key_exists($spalte, self::ORG_VERERBT)) {
            $wert = $this->for($team)->{$spalte};

            return ($wert !== null && $wert !== []) ? (int) $team->id : null;
        }
        foreach ($this->ahnenZeilen($team) as $row) {
            $wert = $row->{$spalte};
            if ($wert !== null && $wert !== []) {
                return (int) $row->team_id;
            }
        }

        return null;
    }

    /**
     * Gespeicherte Settings-Zeilen entlang der Team-Ahnenkette, geordnet self→root
     * (für den Resolver). Eine Query, dann in Ketten-Reihenfolge sortiert.
     *
     * @return list<FoodAlchemistTeamSetting>
     */
    private function ahnenZeilen(Team $team): array
    {
        $kette = FoodAlchemistTeamSetting::teamAncestryIds($team);   // [self, parent, …, root]
        $rows = FoodAlchemistTeamSetting::whereIn('team_id', $kette)->get()->keyBy('team_id');

        return array_values(array_filter(array_map(fn ($tid) => $rows->get($tid), $kette)));
    }

    /** M7-08: Kill-Switch — false stoppt ALLE KI-Calls des Teams (Gateway-Guard). */
    public function kiAktiv(Team $team): bool
    {
        return (bool) ($this->for($team)->ai_active ?? true);
    }

    /**
     * Phase 5: Typ-Farben (GP / Basisrezept / Gericht) als Hex, gemerged mit den Defaults.
     * Nur valide #rrggbb-Werte überschreiben — Müll/Teil-Konfig fällt auf Default zurück.
     *
     * @return array{gp: string, basisrezept: string, gericht: string}
     */
    public function typFarben(Team $team): array
    {
        $gespeichert = $this->rohWert($team, 'type_colors') ?? [];   // #390: org-vererbt
        $farben = self::TYP_FARBEN_DEFAULTS;
        foreach (array_keys($farben) as $key) {
            $wert = $gespeichert[$key] ?? null;
            if (is_string($wert) && preg_match('/^#[0-9a-fA-F]{6}$/', $wert)) {
                $farben[$key] = strtolower($wert);
            }
        }

        return $farben;
    }

    public function kuechenTyp(Team $team): ?string
    {
        $typ = $this->for($team)->kitchen_type;

        return isset(self::KUECHEN_TYPEN[$typ]) ? $typ : null;
    }

    /** Segment (Bespielung) aus dem Küchen-Typ ableiten. @return array{key:string, label:string}|null */
    public function segment(Team $team): ?array
    {
        $typ = $this->kuechenTyp($team);

        return $typ !== null ? (self::SEGMENTE[$typ] ?? null) : null;
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

    /**
     * Lead-LA-Strategie — mit optionalem WG-Override (Phase 3): existiert für die
     * Warengruppe eine eigene Strategie, gewinnt sie vor der globalen Team-Strategie.
     */
    public function leadLaStrategie(Team $team, ?string $warengruppeCode = null): LeadLaStrategie
    {
        $settings = $this->for($team);
        if ($warengruppeCode !== null) {
            $override = ($settings->lead_la_strategie_per_wg ?? [])[$warengruppeCode] ?? null;
            if ($override !== null && ($enum = LeadLaStrategie::tryFrom($override)) !== null) {
                return $enum;
            }
        }

        return $settings->lead_la_strategie ?? LeadLaStrategie::StammLieferant; // V-27-Default = Ist-Verhalten (GL-03 §6)
    }

    /** @return array<string, string> WG-Code => Strategie-Wert (nur gesetzte Overrides). */
    public function leadLaStrategiePerWg(Team $team): array
    {
        $map = $this->for($team)->lead_la_strategie_per_wg ?? [];

        return is_array($map) ? $map : [];
    }

    /** @return array<int> geordnete supplier_ids (nur Strategie prioritaets_kette) */
    public function leadLaPrioritaeten(Team $team): array
    {
        return $this->for($team)->lead_la_prioritaeten ?? [];
    }

    public function ausweichKetteAnzeigen(Team $team): bool
    {
        return $this->for($team)->show_fallback_chain ?? false;
    }

    /** Garverlust-Default in % je GP-Klasse (Warengruppen-Code), '*' = global. */
    public function garverlustDefault(Team $team, ?string $warengruppeCode = null): ?float
    {
        $defaults = $this->for($team)->cooking_loss_defaults ?? [];

        $wert = $defaults[$warengruppeCode] ?? $defaults['*'] ?? null;

        return $wert === null ? null : (float) $wert;
    }

    /** Putzverlust-Default in % je GP-Klasse (Warengruppen-Code), '*' = global (Phase 2). */
    public function putzverlustDefault(Team $team, ?string $warengruppeCode = null): ?float
    {
        $defaults = $this->for($team)->trimming_loss_defaults ?? [];

        $wert = $defaults[$warengruppeCode] ?? $defaults['*'] ?? null;

        return $wert === null ? null : (float) $wert;
    }

    /** @return array{regulaer: float, ermaessigt: float, default_satz: string} */
    public function mwst(Team $team): array
    {
        return array_replace(self::MWST_DEFAULTS, $this->rohWert($team, 'vat_defaults') ?? []);   // #390: org-vererbt
    }

    /** @return array{nachkommastellen: int, modus: string} */
    public function rundung(Team $team): array
    {
        return array_replace(self::RUNDUNG_DEFAULTS, $this->rohWert($team, 'rundungsregeln') ?? []);   // #390: org-vererbt
    }

    /** M12: Gemeinkosten-Zuschlag % auf den Wareneinsatz (HK1 → HK2, D-HK-1). */
    public function hk2Zuschlag(Team $team): float
    {
        return (float) ($this->for($team)->hk2_surcharge_pct ?? 0);
    }

    // ── M-K1 / Doc 16: Kalkulations-Block-Schema ─────────────────────────────

    public const STUNDENSATZ_DEFAULT = 35.0;

    public const MARGE_DEFAULT = 15.0;

    /** #379+: Ziel-Wareneinsatzquote (Food-Cost-%) — gastro-üblich 28–35 %, Default 30 %. */
    public const ZIEL_WARENEINSATZ_DEFAULT = 30.0;

    /** #379+: Lohnnebenkosten-Zuschlag % (AG-Anteil auf Produktionslohn). Default 0 = nur Brutto-Lohn. */
    public const LOHNNEBENKOSTEN_DEFAULT = 0.0;

    /** R2.1: Preis-Alarm-Schwelle — relative LA-Preisänderung in %, ab der ein Signal entsteht. Default 15 %. */
    public const PREIS_ALARM_SCHWELLE_DEFAULT = 15.0;

    /**
     * Kanonisches Default-Schema — mehrstufige Zuschlagskalkulation (D-K8, produzierendes
     * Gewerbe). Stufen: MEK + MGK(%·MEK) + FEK + FGK(%·FEK) = HK → +VwGK/Logistik(%·HK)
     * = Selbstkosten(HK2). Typen:
     *   arbeitszeit   — Fertigungseinzelkosten (FEK), min/60 × Stundensatz
     *   eur_pro_portion — direkter Fixbetrag/Portion (Verpackung)
     *   pct_mek       — % auf Wareneinsatz (Material-Gemeinkosten, Schwund)
     *   pct_fek       — % auf Fertigungslohn (Fertigungs-Gemeinkosten)
     *   pct_hk        — % auf Herstellkosten (Verwaltung/Vertrieb, Logistik)
     * `modus` (manuell|abgeleitet) steuert, ob der %-Satz aus Fixkosten kommt (M-K6).
     *
     * @return list<array{key:string,label:string,typ:string,wert:float,aktiv:bool,sort:int,modus:string}>
     */
    public function defaultSchema(Team $team): array
    {
        return [
            ['key' => 'lohn', 'label' => 'Lohn / Produktion (FEK)', 'type' => 'arbeitszeit', 'value' => 0.0, 'active' => true, 'sort' => 10, 'mode' => 'manuell'],
            ['key' => 'verpackung', 'label' => 'Verpackung (direkt)', 'type' => 'eur_pro_portion', 'value' => 0.25, 'active' => false, 'sort' => 20, 'mode' => 'manuell'],
            ['key' => 'schwund', 'label' => 'Schwund (auf Wareneinsatz)', 'type' => 'pct_mek', 'value' => 0.0, 'active' => true, 'sort' => 30, 'mode' => 'manuell'],
            // „gemeinkosten" = Material-GK; erbt den M12-Wert (rückwärtskompatibel: % auf MEK).
            ['key' => 'gemeinkosten', 'label' => 'Material-Gemeinkosten (Einkauf/Lager/Warenannahme)', 'type' => 'pct_mek', 'value' => $this->hk2Zuschlag($team), 'active' => true, 'sort' => 40, 'mode' => 'manuell'],
            ['key' => 'fertigungs_gk', 'label' => 'Fertigungs-Gemeinkosten (Spüle/Energie/Maschinen)', 'type' => 'pct_fek', 'value' => 0.0, 'active' => true, 'sort' => 50, 'mode' => 'manuell'],
            ['key' => 'verwaltung', 'label' => 'Verwaltung & Vertrieb', 'type' => 'pct_hk', 'value' => 0.0, 'active' => true, 'sort' => 60, 'mode' => 'manuell'],
            ['key' => 'logistik', 'label' => 'Logistik', 'type' => 'pct_hk', 'value' => 0.0, 'active' => true, 'sort' => 70, 'mode' => 'manuell'],
        ];
    }

    /**
     * Aktives Kalkulations-Schema (gespeichert oder Default), nach `sort` geordnet,
     * normalisiert. Legacy `pct_we` → `pct_mek`. arbeitszeit-Block ohne Wert →
     * Default-Stundensatz (in der Berechnung).
     *
     * @return list<array{key:string,label:string,typ:string,wert:float,aktiv:bool,sort:int,modus:string}>
     */
    public function kalkulationSchema(Team $team): array
    {
        $erlaubteTypen = ['pct_mek', 'pct_fek', 'pct_hk', 'eur_pro_portion', 'arbeitszeit', 'pct_we'];
        $schema = $this->for($team)->calculation_schema;
        if (! is_array($schema) || $schema === []) {
            $schema = $this->defaultSchema($team);
        }
        $norm = [];
        foreach ($schema as $b) {
            if (! is_array($b) || ! in_array($b['type'] ?? '', $erlaubteTypen, true)) {
                continue;
            }
            $typ = $b['type'] === 'pct_we' ? 'pct_mek' : $b['type'];   // Legacy-Alias
            $modus = $b['mode'] ?? 'manuell';
            $norm[] = [
                'key' => (string) ($b['key'] ?? ''),
                'label' => (string) ($b['label'] ?? ($b['key'] ?? 'Block')),
                'type' => $typ,
                'value' => (float) ($b['value'] ?? 0),
                'active' => (bool) ($b['active'] ?? true),
                'sort' => (int) ($b['sort'] ?? 100),
                'mode' => in_array($modus, ['manuell', 'abgeleitet'], true) ? $modus : 'manuell',
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
        $v = $this->for($team)->margin_pct;

        return $v !== null ? (float) $v : self::MARGE_DEFAULT;
    }

    /** #379+: Ziel-Wareneinsatzquote (Food-Cost-%) — Controlling-Ziel + Break-even-Treiber. */
    public function zielWareneinsatzPct(Team $team): float
    {
        $v = $this->for($team)->target_food_cost_pct;

        return $v !== null && (float) $v > 0 ? (float) $v : self::ZIEL_WARENEINSATZ_DEFAULT;
    }

    /** R2.1: Preis-Alarm-Schwelle in % (relative LA-Preisänderung). Team-Wert vor Code-Default. */
    public function preisAlarmSchwellePct(Team $team): float
    {
        $v = $this->for($team)->price_alarm_threshold_pct;

        return $v !== null && (float) $v > 0 ? (float) $v : self::PREIS_ALARM_SCHWELLE_DEFAULT;
    }

    /** R2.5: max. relatives VK-Delta (%) ggü. freigegebenem Snapshot, ab dem „VK-Anpassung empfohlen" feuert. Default 5 %. */
    public function maxVkDeltaPct(Team $team): float
    {
        $v = $this->for($team)->max_vk_delta_pct;

        return $v !== null && (float) $v > 0 ? (float) $v : 5.0;
    }

    /** R2.5: Mindestmarge (%) — Untergrenze, unter der ein VK-Vorschlag kritisch ist. Null = nicht gepflegt. */
    public function mindestMarginPct(Team $team): ?float
    {
        $v = $this->for($team)->min_margin_pct;

        return $v !== null && (float) $v > 0 ? (float) $v : null;
    }

    /** R2.5: Margen-Zielband [min,max] in % (Saison-Auto-Pricing). Null-Elemente = Bandseite nicht gepflegt. */
    public function seasonMarginBand(Team $team): array
    {
        $s = $this->for($team);

        return [
            'min' => $s->season_margin_band_min_pct !== null ? (float) $s->season_margin_band_min_pct : null,
            'max' => $s->season_margin_band_max_pct !== null ? (float) $s->season_margin_band_max_pct : null,
        ];
    }

    /** #379+: Lohnnebenkosten-Zuschlag % auf den Produktionslohn (AG-/Sozialabgaben). */
    public function lohnnebenkostenPct(Team $team): float
    {
        $v = $this->for($team)->labor_overhead_pct;

        return $v !== null && (float) $v >= 0 ? (float) $v : self::LOHNNEBENKOSTEN_DEFAULT;
    }

    /**
     * Bezugsbasen je Periode (monatlich) für die Fixkosten-Ableitung (M-K6):
     * mek = erwarteter Wareneinsatz, fek = erwartete Fertigungslöhne, hk = erwartete
     * Herstellkosten. 0 = nicht gepflegt (Ableitung dann 0 für diese Basis).
     *
     * @return array{mek: float, fek: float, hk: float}
     */
    public function bezugsbasen(Team $team): array
    {
        $b = $this->for($team)->calculation_reference_bases ?? [];

        return [
            'mek' => (float) ($b['mek'] ?? 0),
            'fek' => (float) ($b['fek'] ?? 0),
            'hk' => (float) ($b['hk'] ?? 0),
        ];
    }
}
