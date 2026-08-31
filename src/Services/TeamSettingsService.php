<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistOutletSetting;
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
     * Niveau-Aliasse: der Concepter-Editor speichert `haute` (concept.level), Segment +
     * Rezept-Niveau-Eignung nutzen `haute_cuisine`. Kanonisiert auf haute_cuisine, damit die
     * Leitplanken-Kaskade (Kapitel → Foodbook → Segment) ein Vokabular spricht.
     */
    public const NIVEAU_ALIAS = ['haute' => 'haute_cuisine'];

    /**
     * Enterprise-Kundentyp am Foodbook (kreative Leitplanke: für wen wird geschrieben).
     * Fließt als Kontext in die KI-Erstellung. Bewusst als editierbare Liste — bei neuen
     * Kunden-Segmenten hier ergänzen (kein Schema-Change nötig, kundentyp ist freier varchar).
     */
    public const KUNDENTYPEN = [
        'bhg_gruppe' => 'BHG-Gruppe (intern / gruppenweit)',
        'enterprise_kette' => 'Enterprise / Kette',
        'einzelkunde' => 'Einzelkunde / individuell',
        'hotellerie' => 'Hotellerie & Bankett',
        'betriebsgastro' => 'Betriebsgastronomie',
        'care_klinik' => 'Care / Klinik / Senioren',
        'bildung' => 'Bildung / Mensa',
        'event_privat' => 'Event / Privat',
    ];

    /** Concept-/Foodbook-Niveau auf das kanonische Vokabular normalisieren (haute → haute_cuisine). */
    public static function normNiveau(?string $niveau): ?string
    {
        if ($niveau === null || trim($niveau) === '') {
            return null;
        }

        return self::NIVEAU_ALIAS[$niveau] ?? $niveau;
    }

    /** Umkehrung: kanonisches Niveau → Concepter-Vokabular (haute_cuisine → haute) fürs Schreiben nach concept.level. */
    public static function denormNiveauFuerConcept(?string $canonical): ?string
    {
        if ($canonical === null || trim($canonical) === '') {
            return null;
        }

        return array_flip(self::NIVEAU_ALIAS)[$canonical] ?? $canonical;
    }

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
     * Ebene 2 (Betrieb): Outlet-Settings-Zeile des Teams — NUR wenn das Outlet zum Team
     * gehört (Tenancy-Guard); sonst NULL. firstOrNew: fehlende Zeile = keine Overrides.
     */
    private function outletRow(Team $team, ?FoodAlchemistOutlet $outlet): ?FoodAlchemistOutletSetting
    {
        if ($outlet === null || (int) $outlet->team_id !== (int) $team->id) {
            return null;
        }

        return FoodAlchemistOutletSetting::firstOrNew(['outlet_id' => $outlet->id, 'team_id' => $team->id]);
    }

    /**
     * Ebene 2: Skalar-Kaskade Outlet-Override → Team (rohWert, inkl. ORG_VERERBT) → NULL.
     * outlet=null ⇒ skalar() === rohWert() (byte-identisch zum bisherigen Team-Pfad).
     */
    private function skalar(Team $team, string $spalte, ?FoodAlchemistOutlet $outlet): mixed
    {
        $o = $this->outletRow($team, $outlet);
        if ($o !== null && $o->{$spalte} !== null && $o->{$spalte} !== []) {
            return $o->{$spalte};
        }

        return $this->rohWert($team, $spalte);
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

    /** Trendradar: 08:00-Konzept-Automatisierung für dieses Team (Default AUS — opt-in). */
    public function trendAutoAktiv(Team $team): bool
    {
        return (bool) ($this->for($team)->trend_auto_enabled ?? false);
    }

    /** Trendradar: Anzahl Top-Trends je Lauf; ungesetzt ⇒ Config-Default. */
    public function trendAutoLimit(Team $team): int
    {
        $v = (int) ($this->for($team)->trend_auto_limit ?? 0);

        return $v > 0 ? $v : (int) config('foodalchemist.scheduler.trend_konzepte_limit', 3);
    }

    /** Trendradar: den Vorschlag als Signal in die Inbox legen (Default AN). */
    public function trendSignalAktiv(Team $team): bool
    {
        return (bool) ($this->for($team)->trend_signal_enabled ?? true);
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

    /** Team-lokale Standard-Preisklasse; geerbte und globale Klassen duerfen referenziert werden. */
    public function defaultMarkupClassId(Team $team): ?int
    {
        $id = (int) ($this->for($team)->default_markup_class_id ?? 0);
        if ($id <= 0) {
            return null;
        }

        return FoodAlchemistMarkupClass::visibleToTeam($team)
            ->whereKey($id)->where('is_inactive', false)->exists() ? $id : null;
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
    public function hk2Zuschlag(Team $team, ?FoodAlchemistOutlet $outlet = null): float
    {
        return (float) ($this->skalar($team, 'hk2_surcharge_pct', $outlet) ?? 0);
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
    public function kalkulationSchema(Team $team, ?FoodAlchemistOutlet $outlet = null): array
    {
        $erlaubteTypen = ['pct_mek', 'pct_fek', 'pct_hk', 'eur_pro_portion', 'arbeitszeit', 'pct_we'];
        $schema = $this->skalar($team, 'calculation_schema', $outlet);
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
    public function stundensatz(Team $team, ?FoodAlchemistOutlet $outlet = null): float
    {
        $v = $this->skalar($team, 'stundensatz_eur', $outlet);

        return $v !== null && (float) $v > 0 ? (float) $v : self::STUNDENSATZ_DEFAULT;
    }

    /** Lohnquelle für Aufträge: flacher Team-Satz oder gewichtete Rollen des Postens. */
    /** Lohnquelle team_flat|station_roles — Ebene 2: Outlet-Override → Team → 'team_flat'. */
    public function laborCostSource(Team $team, ?FoodAlchemistOutlet $outlet = null): string
    {
        $value = (string) ($this->skalar($team, 'labor_cost_source', $outlet) ?? 'team_flat');

        return in_array($value, ['team_flat', 'station_roles'], true) ? $value : 'team_flat';
    }

    /** Marge % auf die HK → VK-Vorschlag (Doc 16). */
    public function margePct(Team $team, ?FoodAlchemistOutlet $outlet = null): float
    {
        $v = $this->skalar($team, 'margin_pct', $outlet);

        return $v !== null ? (float) $v : self::MARGE_DEFAULT;
    }

    // ── Standard-Topf-Deckel (Produktionszeit-Fallback, Spec „realistische Zeit") ────────────

    /**
     * Standard-Topf-Deckel je Koch-Vorgang (kg) — Fallback für die Arbeitszeit-Rechnung, wenn WEDER
     * Rezept noch Posten einen eigenen Deckel pflegen. Code-Konstante als letzter Fallback.
     */
    public function defaultTopfDeckelKg(Team $team): float
    {
        $v = $this->for($team)->default_batch_max_kg;

        return $v !== null && (float) $v > 0 ? (float) $v : FoodAlchemistRecipe::DEFAULT_BATCH_MAX_KG;
    }

    /** Standard-Topf-Deckel je Koch-Vorgang (Stück) — Fallback, Code-Konstante als letzter Fallback. */
    public function defaultTopfDeckelStueck(Team $team): float
    {
        $v = $this->for($team)->default_batch_max_pieces;

        return $v !== null && (float) $v > 0 ? (float) $v : FoodAlchemistRecipe::DEFAULT_BATCH_MAX_PIECES;
    }

    /**
     * Fallback-Topf-Deckel für EIN Rezept auf der passenden Achse (kg oder Stück). Greift nur, wenn
     * weder Rezept- noch Posten-Deckel gesetzt ist. (Ein Warengruppen-Override wird hier später
     * vorgeschaltet — dann: Warengruppe ?? Team-Default ?? Code-Konstante.)
     */
    public function topfDeckelFuer(Team $team, FoodAlchemistRecipe $recipe, ?bool $stueck = null): float
    {
        $stueck ??= $recipe->istStueckErtrag();

        return $stueck ? $this->defaultTopfDeckelStueck($team) : $this->defaultTopfDeckelKg($team);
    }

    /** #379+: Ziel-Wareneinsatzquote (Food-Cost-%) — Controlling-Ziel + Break-even-Treiber. */
    public function zielWareneinsatzPct(Team $team, ?FoodAlchemistOutlet $outlet = null): float
    {
        $v = $this->skalar($team, 'target_food_cost_pct', $outlet);

        return $v !== null && (float) $v > 0 ? (float) $v : self::ZIEL_WARENEINSATZ_DEFAULT;
    }

    /** R2.1: Preis-Alarm-Schwelle in % (relative LA-Preisänderung). Team-Wert vor Code-Default. */
    public function preisAlarmSchwellePct(Team $team): float
    {
        $v = $this->for($team)->price_alarm_threshold_pct;

        return $v !== null && (float) $v > 0 ? (float) $v : self::PREIS_ALARM_SCHWELLE_DEFAULT;
    }

    /** Einkauf E2: Ab welchem Bestell-Status wird ins Einkaufsjournal gebucht — 'sent' | 'delivered' (Default). */
    public function purchaseJournalTrigger(Team $team): string
    {
        $v = (string) ($this->for($team)->purchase_journal_trigger ?? '');

        return in_array($v, ['sent', 'delivered'], true) ? $v : 'delivered';
    }

    /**
     * Spec 32 C4: ab wie vielen PROZENTPUNKTEN (bezogen auf den Umsatz) die Abweichung
     * zwischen eingekauftem und theoretisch nötigem Wareneinsatz gemeldet wird. Default 3 pp.
     *
     * In pp statt in Euro, weil derselbe Euro-Betrag bei kleinem und großem Umsatz etwas
     * völlig anderes bedeutet.
     */
    public function wareneinsatzAbweichungSchwellePp(Team $team): float
    {
        $v = $this->for($team)->we_deviation_threshold_pp ?? null;

        return $v !== null && (float) $v > 0 ? (float) $v : 3.0;
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
    public function lohnnebenkostenPct(Team $team, ?FoodAlchemistOutlet $outlet = null): float
    {
        $v = $this->skalar($team, 'labor_overhead_pct', $outlet);

        return $v !== null && (float) $v >= 0 ? (float) $v : self::LOHNNEBENKOSTEN_DEFAULT;
    }

    /**
     * Bezugsbasen je Periode (monatlich) für die Fixkosten-Ableitung (M-K6):
     * mek = erwarteter Wareneinsatz, fek = erwartete Fertigungslöhne, hk = erwartete
     * Herstellkosten. 0 = nicht gepflegt (Ableitung dann 0 für diese Basis).
     *
     * @return array{mek: float, fek: float, hk: float}
     */
    public function bezugsbasen(Team $team, ?FoodAlchemistOutlet $outlet = null): array
    {
        $b = $this->skalar($team, 'calculation_reference_bases', $outlet) ?? [];

        return [
            'mek' => (float) ($b['mek'] ?? 0),
            'fek' => (float) ($b['fek'] ?? 0),
            'hk' => (float) ($b['hk'] ?? 0),
        ];
    }
}
