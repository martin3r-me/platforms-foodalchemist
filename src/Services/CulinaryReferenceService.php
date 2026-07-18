<?php

namespace Platform\FoodAlchemist\Services;

/**
 * #513 Tier 1 / Punkt 2 — Kerntemperatur-REFERENZ (Landeplatz C), Gastronomie-Qualität.
 *
 * WICHTIG (Dominique 2026-07-19): Das sind QUALITÄTS-Zielwerte für Textur/Saftigkeit,
 * KEINE harte Sicherheitsvorschrift. In der Qualitätsküche gart man Rind rosa auf 52 °C
 * und Geflügel auf 68 °C — bewusst UNTER den amtlichen „Sofort-sicher"-Werten. Grund:
 * Lebensmittelsicherheit ist ZEIT-TEMPERATUR (Pasteurisierung/Sous-vide), keine einzelne
 * Zahl. 68 °C lang genug gehalten pasteurisiert; 52 °C bei ganzem Muskel ist sicher, weil
 * die Keime an der Oberfläche sitzen und beim Anbraten sterben.
 *
 * DESHALB weich modelliert: `target_c` = Qualitäts-Ziel; `classic_safe_c` = klassische
 * Sofort-Service-Kerntemp (Kontext); `is_hard_safety` = true NUR, wo die Temperatur ein
 * echter Boden ist — Hackfleisch/Geflügel-Hack (Keime durchmischt → durcherhitzen).
 *
 * KEINE ERFINDUNGEN: Werte aus Belitz-Grosch-Schieberle (Lebensmittelchemie) + Modernist
 * Cuisine (Sous-vide-Pasteurisierungs-Tabellen). Amtliche/lokale HACCP-Grenzwerte UND die
 * Zeit-Temperatur-Kombination haben immer Vorrang — dies ersetzt keine Betriebs-Doku.
 * `evidence` = 'med' (etablierte Gastronomie-Praxis; keine amtliche Rechtsquelle).
 */
class CulinaryReferenceService
{
    /**
     * Qualitäts-Kerntemperaturen. Felder je Zeile:
     *  protein, label, cut (Zuschnitt/Teilstück), doneness (Garstufe|null),
     *  target_c (Qualitäts-Ziel °C), range_c (Praxis-Spanne),
     *  classic_safe_c (klassische Sofort-Kerntemp °C|null, Kontext),
     *  is_hard_safety (bool — nur echte Sicherheitsböden), safety (Zeit-Temperatur-/
     *  Ausnahme-Hinweis), source.
     *
     * @var list<array<string, mixed>>
     */
    public const KERNTEMPERATUREN = [
        // ── Rind (ganzer Muskel — Oberflächenkeime sterben beim Anbraten) ──
        ['protein' => 'rind', 'label' => 'Rind', 'cut' => 'kurzbraten', 'doneness' => 'bleu', 'doneness_label' => 'bleu / blau',
            'target_c' => 48, 'range_c' => '47–49', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Ganzer Muskel: Kern roh, Oberfläche scharf angebraten. Nicht für Risikogruppen/Hackfleisch.', 'source' => 'BGS · Modernist Cuisine'],
        ['protein' => 'rind', 'label' => 'Rind', 'cut' => 'kurzbraten', 'doneness' => 'medium_rare', 'doneness_label' => 'rosa / medium rare',
            'target_c' => 52, 'range_c' => '52–54', 'classic_safe_c' => 55, 'is_hard_safety' => false,
            'safety' => 'Qualitäts-Standard. Sicher bei ganzem Muskel (Oberfläche gegart). Sous-vide: Pasteurisierung über Haltezeit.', 'source' => 'BGS · Modernist Cuisine'],
        ['protein' => 'rind', 'label' => 'Rind', 'cut' => 'kurzbraten', 'doneness' => 'medium', 'doneness_label' => 'medium',
            'target_c' => 57, 'range_c' => '56–58', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Ganzer Muskel.', 'source' => 'BGS'],
        ['protein' => 'rind', 'label' => 'Rind', 'cut' => 'kurzbraten', 'doneness' => 'well_done', 'doneness_label' => 'durch',
            'target_c' => 63, 'range_c' => '62–68', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Ganzer Muskel.', 'source' => 'BGS'],
        ['protein' => 'rind', 'label' => 'Rind', 'cut' => 'schmoren', 'doneness' => null, 'doneness_label' => 'Schmorstück',
            'target_c' => 88, 'range_c' => '85–90', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Kollagen→Gelatine: hohe Kerntemp über lange Zeit ist hier Textur-Ziel, kein Sicherheitswert.', 'source' => 'Modernist Cuisine'],

        // ── Kalb / Lamm ──
        ['protein' => 'kalb', 'label' => 'Kalb', 'cut' => 'kurzbraten', 'doneness' => 'medium', 'doneness_label' => 'rosa / medium',
            'target_c' => 60, 'range_c' => '58–62', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Ganzer Muskel.', 'source' => 'BGS'],
        ['protein' => 'lamm', 'label' => 'Lamm', 'cut' => 'kurzbraten', 'doneness' => 'medium_rare', 'doneness_label' => 'rosa / medium rare',
            'target_c' => 55, 'range_c' => '54–56', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Ganzer Muskel (Rücken/Filet).', 'source' => 'BGS'],

        // ── Schwein (EU-Trichinellen-Kontrolle → rosa vertretbar; Wild/Hausschlachtung anders) ──
        ['protein' => 'schwein', 'label' => 'Schwein', 'cut' => 'kurzbraten', 'doneness' => 'medium', 'doneness_label' => 'rosa / à point',
            'target_c' => 60, 'range_c' => '58–62', 'classic_safe_c' => 70, 'is_hard_safety' => false,
            'safety' => 'Modern rosa vertretbar (EU-Trichinellen-Kontrolle). Wild-/Hausschlachtung + Risikogruppen: durchgaren.', 'source' => 'BGS · Modernist Cuisine'],

        // ── Geflügel (Brust weich, Keule höher wg. Kollagen) ──
        ['protein' => 'gefluegel', 'label' => 'Geflügel', 'cut' => 'brust', 'doneness' => null, 'doneness_label' => 'Brust',
            'target_c' => 68, 'range_c' => '62–68', 'classic_safe_c' => 72, 'is_hard_safety' => false,
            'safety' => 'Qualitäts-Ziel 68 °C (Catering, haltbar). À la carte saftiger: 62–64 °C sous-vide bei ausreichender Haltezeit. Sicher NUR über Zeit-Temperatur (Pasteurisierung: 60 °C ~35 min, 65 °C ~10 min). Sofort-Service klassisch 72–74 °C.', 'source' => 'Modernist Cuisine (Pasteurisierung)'],
        ['protein' => 'gefluegel', 'label' => 'Geflügel', 'cut' => 'keule', 'doneness' => null, 'doneness_label' => 'Keule / Schenkel',
            'target_c' => 75, 'range_c' => '72–80', 'classic_safe_c' => 74, 'is_hard_safety' => false,
            'safety' => 'Höher als Brust: Kollagen/Bindegewebe braucht Zeit + Temperatur.', 'source' => 'Modernist Cuisine'],
        ['protein' => 'ente', 'label' => 'Ente', 'cut' => 'brust', 'doneness' => 'medium_rare', 'doneness_label' => 'rosa',
            'target_c' => 56, 'range_c' => '54–58', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Entenbrust wird wie Rind rosa behandelt (ganzer Muskel).', 'source' => 'BGS'],

        // ── Fisch ──
        ['protein' => 'fisch', 'label' => 'Fisch', 'cut' => 'filet', 'doneness' => 'glasig', 'doneness_label' => 'à point / glasig',
            'target_c' => 48, 'range_c' => '45–50', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Roh/glasig: Parasiten-Vorbehandlung (TK −20 °C ≥24 h) für Sushi-Qualität beachten.', 'source' => 'BGS · Modernist Cuisine'],
        ['protein' => 'fisch', 'label' => 'Fisch', 'cut' => 'filet', 'doneness' => 'durch', 'doneness_label' => 'durchgegart',
            'target_c' => 58, 'range_c' => '55–60', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Fest durchgegart.', 'source' => 'BGS'],

        // ── ECHTE Sicherheitsböden (Keime durchmischt → durcherhitzen) ──
        ['protein' => 'hackfleisch', 'label' => 'Hackfleisch (Rind/gemischt)', 'cut' => null, 'doneness' => null, 'doneness_label' => 'durcherhitzt',
            'target_c' => 72, 'range_c' => '≥70–72', 'classic_safe_c' => 72, 'is_hard_safety' => true,
            'safety' => 'HART: Keime sind durch die ganze Masse verteilt (nicht nur Oberfläche) → Kern muss durcherhitzt werden. Kein rosa im Volumen-/Gemeinschaftscatering.', 'source' => 'HACCP-Grundsatz · BGS'],
        ['protein' => 'gefluegel_hack', 'label' => 'Geflügel-Hack / Brät', 'cut' => null, 'doneness' => null, 'doneness_label' => 'durcherhitzt',
            'target_c' => 74, 'range_c' => '≥72–74', 'classic_safe_c' => 74, 'is_hard_safety' => true,
            'safety' => 'HART: durcherhitzen (Salmonellen/Campylobacter, durchmischt).', 'source' => 'HACCP-Grundsatz'],

        // ── Wild (Reh/Hirsch ganzer Muskel wie Rind; Wildschwein Parasiten-Vorbehalt) ──
        ['protein' => 'reh', 'label' => 'Reh / Hirsch', 'cut' => 'ruecken', 'doneness' => 'medium_rare', 'doneness_label' => 'rosa',
            'target_c' => 55, 'range_c' => '54–56', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Rücken/Filet wie Rind rosa (ganzer Muskel).', 'source' => 'BGS'],
        ['protein' => 'reh', 'label' => 'Reh / Hirsch', 'cut' => 'keule', 'doneness' => 'medium', 'doneness_label' => 'rosa/medium',
            'target_c' => 58, 'range_c' => '56–60', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Keule etwas höher (mehr Bindegewebe).', 'source' => 'BGS'],
        ['protein' => 'wildschwein', 'label' => 'Wildschwein', 'cut' => null, 'doneness' => 'durch', 'doneness_label' => 'durchgegart',
            'target_c' => 70, 'range_c' => '70–72', 'classic_safe_c' => 70, 'is_hard_safety' => false,
            'safety' => 'PARASITEN (Trichinella): durchgaren ODER vorher TK −20 °C. Nicht rosa servieren ohne amtliche Trichinellen-Freigabe.', 'source' => 'HACCP · BGS'],
        ['protein' => 'kaninchen', 'label' => 'Kaninchen', 'cut' => 'ruecken', 'doneness' => 'medium', 'doneness_label' => 'saftig',
            'target_c' => 60, 'range_c' => '58–62', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Rücken saftig; Keule geschmort höher.', 'source' => 'BGS'],

        // ── Geflügel-Varianten ──
        ['protein' => 'pute', 'label' => 'Pute', 'cut' => 'brust', 'doneness' => null, 'doneness_label' => 'Brust',
            'target_c' => 70, 'range_c' => '68–72', 'classic_safe_c' => 72, 'is_hard_safety' => false,
            'safety' => 'Magerer als Huhn → minimal höher, sonst trocken. Sicherheit = Zeit-Temperatur (Pasteurisierung).', 'source' => 'Modernist Cuisine'],
        ['protein' => 'ente', 'label' => 'Ente', 'cut' => 'keule', 'doneness' => null, 'doneness_label' => 'Keule / Confit',
            'target_c' => 78, 'range_c' => '75–82', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Confit low & slow: hohe Kerntemp löst Kollagen — Textur-Ziel.', 'source' => 'Modernist Cuisine'],
        ['protein' => 'gans', 'label' => 'Gans', 'cut' => 'brust', 'doneness' => 'medium_rare', 'doneness_label' => 'rosa',
            'target_c' => 56, 'range_c' => '55–58', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Brust wie Ente rosa (ganzer Muskel).', 'source' => 'BGS'],
        ['protein' => 'gans', 'label' => 'Gans', 'cut' => 'keule', 'doneness' => null, 'doneness_label' => 'Keule / Confit',
            'target_c' => 82, 'range_c' => '80–85', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Confit — Kollagen/Textur.', 'source' => 'Modernist Cuisine'],

        // ── Schwein — Langzeit/Schmoren ──
        ['protein' => 'schwein', 'label' => 'Schwein', 'cut' => 'schmoren', 'doneness' => null, 'doneness_label' => 'Pulled / Schmorstück',
            'target_c' => 90, 'range_c' => '88–92', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Kollagen→Gelatine (Pulled Pork/Bäckchen): Kerntemp ist Textur-Ziel über Zeit.', 'source' => 'Modernist Cuisine'],
        ['protein' => 'schwein', 'label' => 'Schwein', 'cut' => 'bauch', 'doneness' => null, 'doneness_label' => 'Bauch confit',
            'target_c' => 78, 'range_c' => '75–82', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Bauch sous-vide/confit zart-schmelzend.', 'source' => 'Modernist Cuisine'],

        // ── Fisch-Typen ──
        ['protein' => 'lachs', 'label' => 'Lachs / fetter Fisch', 'cut' => 'filet', 'doneness' => 'glasig', 'doneness_label' => 'à point / glasig',
            'target_c' => 45, 'range_c' => '42–50', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Fetter Fisch bleibt à point saftig. Roh/glasig: Parasiten-TK −20 °C ≥24 h.', 'source' => 'Modernist Cuisine'],
        ['protein' => 'weissfisch', 'label' => 'Weißfisch (Kabeljau/Zander)', 'cut' => 'filet', 'doneness' => 'blaettrig', 'doneness_label' => 'saftig-blättrig',
            'target_c' => 52, 'range_c' => '50–54', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Mager → Fenster eng; über 55 °C schnell trocken.', 'source' => 'BGS · Modernist Cuisine'],
        ['protein' => 'thunfisch', 'label' => 'Thunfisch', 'cut' => 'loin', 'doneness' => 'bleu', 'doneness_label' => 'roh/angebraten (Kern kühl)',
            'target_c' => 42, 'range_c' => '40–46', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Sashimi-Qualität: Kern (fast) roh, außen kurz angebraten. Frische-/TK-Vorgabe für roh.', 'source' => 'Modernist Cuisine'],

        // ── Meeresfrüchte ──
        ['protein' => 'jakobsmuschel', 'label' => 'Jakobsmuschel', 'cut' => null, 'doneness' => 'glasig', 'doneness_label' => 'glasig',
            'target_c' => 48, 'range_c' => '45–50', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Kern glasig-zart; über 52 °C gummig.', 'source' => 'Modernist Cuisine'],
        ['protein' => 'garnele', 'label' => 'Garnele / Langustine', 'cut' => null, 'doneness' => 'a_point', 'doneness_label' => 'à point (opak)',
            'target_c' => 54, 'range_c' => '52–56', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Gerade opak/saftig; überkocht schnell mehlig.', 'source' => 'Modernist Cuisine'],

        // ── Innereien / Foie gras ──
        ['protein' => 'kalbsleber', 'label' => 'Kalbsleber', 'cut' => null, 'doneness' => 'medium', 'doneness_label' => 'rosa',
            'target_c' => 56, 'range_c' => '55–58', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Rosa = Qualität; ganzes Organstück (Oberfläche anbraten).', 'source' => 'BGS'],
        ['protein' => 'foie_gras', 'label' => 'Foie gras', 'cut' => null, 'doneness' => null, 'doneness_label' => 'Terrine / à la minute',
            'target_c' => 56, 'range_c' => '55–58', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Terrine Kern 55–58 °C (mi-cuit); zu heiß → zerläuft/Fettverlust.', 'source' => 'Modernist Cuisine'],

        // ── ECHTER Sicherheitsboden: Brät/Wurstmasse (emulgiert → durchmischt) ──
        ['protein' => 'braet', 'label' => 'Brät / Wurstmasse', 'cut' => null, 'doneness' => null, 'doneness_label' => 'durcherhitzt',
            'target_c' => 72, 'range_c' => '≥72', 'classic_safe_c' => 72, 'is_hard_safety' => true,
            'safety' => 'HART: emulgierte/durchmischte Masse → Kern durcherhitzen (wie Hackfleisch).', 'source' => 'HACCP-Grundsatz'],

        // ── Ei ──
        ['protein' => 'ei', 'label' => 'Ei', 'cut' => null, 'doneness' => null, 'doneness_label' => 'pochiert / onsen',
            'target_c' => 64, 'range_c' => '63–65', 'classic_safe_c' => null, 'is_hard_safety' => false,
            'safety' => 'Weich/roh: Salmonellen-Kontext für Risikogruppen — dann durchgaren oder pasteurisierte Eier.', 'source' => 'BGS'],
    ];

    /**
     * Kerntemperatur-Referenz, optional nach Protein gefiltert (slug, case-insensitiv).
     * Reine Nachschlage-Fakten — deterministisch, kein Team-Scope (universelles Wissen).
     *
     * @return list<array<string, mixed>>
     */
    public function kerntemperaturen(?string $protein = null): array
    {
        $rows = self::KERNTEMPERATUREN;
        if ($protein !== null && trim($protein) !== '') {
            $p = mb_strtolower(trim($protein));
            $rows = array_values(array_filter($rows, fn ($r) => $r['protein'] === $p));
        }

        // Provenienz-/Weichheits-Disclaimer je Zeile mitgeben (nie „hart" ohne Kontext).
        return array_map(fn ($r) => $r + [
            'evidence' => 'med',
            'hinweis' => 'Qualitäts-Zielwert, keine Vorschrift. Sicherheit = Zeit-Temperatur; amtliche/lokale HACCP-Grenzwerte haben Vorrang.',
        ], $rows);
    }

    /** Bekannte Protein-Slugs (für Tool-Enum / Validierung). @return list<string> */
    public function proteine(): array
    {
        return array_values(array_unique(array_map(fn ($r) => $r['protein'], self::KERNTEMPERATUREN)));
    }

    // ════════════════════════════════════════════════════════════════════
    //  #513 Punkt 3+7 — Hydrokolloid-Dosierungen + HLB (Landeplatz C)
    //  Der praktische Kern: reale Dosier-Ranges, damit der Generator ein Gel/
    //  Espuma/Sphäre baut statt zu raten. Verzahnt mit Punkt-1-Extraprozent
    //  (Dosierung IST ein Extraprozent aufs Gesamtgewicht). Werte = publizierte
    //  Ranges (Modernist Cuisine Bd. 4 / Herstellerangaben) — keine Erfindung,
    //  konkrete Charge/Marke kann abweichen (Herstellerangabe hat Vorrang).
    // ════════════════════════════════════════════════════════════════════

    /**
     * Hydrokolloid-Dosierungen. Felder: agent, label, application (Zweck),
     * dose_min/dose_max (% vom Ansatzgewicht), dose_note (Range), needs
     * (Cofaktor/Bedingung), thermoreversible (bool|null), source.
     *
     * @var list<array<string, mixed>>
     */
    public const HYDROCOLLOID_DOSAGES = [
        ['agent' => 'agar', 'label' => 'Agar-Agar', 'application' => 'festes Gel', 'dose_min' => 0.5, 'dose_max' => 2.0, 'dose_note' => '0,5–2 %', 'needs' => 'aufkochen ~90 °C, geliert beim Abkühlen ~35–40 °C', 'thermoreversible' => true, 'source' => 'Modernist Cuisine'],
        ['agent' => 'agar', 'label' => 'Agar-Agar', 'application' => 'weiches Gel / Fluid Gel', 'dose_min' => 0.2, 'dose_max' => 0.8, 'dose_note' => '0,2–0,8 %', 'needs' => 'nach dem Gelieren mixen = Fluid Gel', 'thermoreversible' => true, 'source' => 'Modernist Cuisine'],
        ['agent' => 'xanthan', 'label' => 'Xanthan', 'application' => 'Verdickung / Suspension', 'dose_min' => 0.1, 'dose_max' => 0.5, 'dose_note' => '0,1–0,5 %', 'needs' => 'kalt löslich, mixen; Extraprozent-Logik', 'thermoreversible' => null, 'source' => 'Modernist Cuisine'],
        ['agent' => 'kappa_carrageen', 'label' => 'Kappa-Carrageen', 'application' => 'festes, sprödes Gel', 'dose_min' => 0.5, 'dose_max' => 1.5, 'dose_note' => '0,5–1,5 %', 'needs' => 'K⁺ verstärkt; mit Milch besonders wirksam', 'thermoreversible' => true, 'source' => 'Modernist Cuisine'],
        ['agent' => 'iota_carrageen', 'label' => 'Iota-Carrageen', 'application' => 'weiches, elastisches Gel', 'dose_min' => 0.5, 'dose_max' => 1.5, 'dose_note' => '0,5–1,5 %', 'needs' => 'Ca²⁺ verstärkt', 'thermoreversible' => true, 'source' => 'Modernist Cuisine'],
        ['agent' => 'gellan_low_acyl', 'label' => 'Gellan (low-acyl)', 'application' => 'festes, klares, sprödes Gel', 'dose_min' => 0.2, 'dose_max' => 1.0, 'dose_note' => '0,2–1 %', 'needs' => 'hitzestabil bis ~70 °C nach Gelieren; Ionen', 'thermoreversible' => false, 'source' => 'Modernist Cuisine'],
        ['agent' => 'gellan_high_acyl', 'label' => 'Gellan (high-acyl)', 'application' => 'weiches, elastisches Gel', 'dose_min' => 0.2, 'dose_max' => 1.0, 'dose_note' => '0,2–1 %', 'needs' => '—', 'thermoreversible' => false, 'source' => 'Modernist Cuisine'],
        ['agent' => 'natriumalginat', 'label' => 'Natriumalginat', 'application' => 'Sphärifikation (Basis-Bad)', 'dose_min' => 0.5, 'dose_max' => 1.0, 'dose_note' => '0,5–1 %', 'needs' => 'im Calciumbad (CaCl₂/Calciumlactat) gelieren; direkte vs. reverse Sphärifikation', 'thermoreversible' => false, 'source' => 'Modernist Cuisine'],
        ['agent' => 'calciumlactat', 'label' => 'Calciumlactat / CaCl₂', 'application' => 'Sphärifikation (Fäll-Bad bzw. reverse im Kern)', 'dose_min' => 0.5, 'dose_max' => 1.0, 'dose_note' => '0,5–1 %', 'needs' => 'Gegenion zu Alginat; CaCl₂ bitterer als Lactat', 'thermoreversible' => false, 'source' => 'Modernist Cuisine'],
        ['agent' => 'methylcellulose', 'label' => 'Methylcellulose', 'application' => 'HEISS-Gel / Bindung', 'dose_min' => 0.5, 'dose_max' => 2.0, 'dose_note' => '0,5–2 %', 'needs' => 'geliert BEIM ERHITZEN, löst kalt (invers) — kalt hydratisieren', 'thermoreversible' => true, 'source' => 'Modernist Cuisine'],
        ['agent' => 'lecithin', 'label' => 'Sojalecithin', 'application' => 'Luft / Espuma (Air)', 'dose_min' => 0.3, 'dose_max' => 1.0, 'dose_note' => '0,3–1 %', 'needs' => 'in wässriger Phase mixen (Stabmixer schräg an Oberfläche)', 'thermoreversible' => null, 'source' => 'Modernist Cuisine'],
        ['agent' => 'johannisbrotkernmehl', 'label' => 'Johannisbrotkernmehl (LBG)', 'application' => 'Verdickung (Synergie mit Xanthan/Carrageen)', 'dose_min' => 0.1, 'dose_max' => 0.5, 'dose_note' => '0,1–0,5 %', 'needs' => 'warm hydratisieren; mit Xanthan Gel', 'thermoreversible' => null, 'source' => 'Modernist Cuisine'],
        ['agent' => 'pektin_hm', 'label' => 'Pektin HM (hochverestert)', 'application' => 'Gelee / Konfitüre', 'dose_min' => 0.5, 'dose_max' => 1.0, 'dose_note' => '0,5–1 %', 'needs' => 'braucht Zucker (>55 %) + Säure (pH ~3)', 'thermoreversible' => false, 'source' => 'BGS · Modernist Cuisine'],
        ['agent' => 'pektin_lm', 'label' => 'Pektin LM (niederverestert)', 'application' => 'zuckerarmes Gel', 'dose_min' => 0.5, 'dose_max' => 1.5, 'dose_note' => '0,5–1,5 %', 'needs' => 'geliert mit Ca²⁺, ohne viel Zucker', 'thermoreversible' => false, 'source' => 'BGS'],
    ];

    /**
     * HLB-Werte gängiger Emulgatoren (Hydrophilic-Lipophilic Balance). Skala 0–20:
     * <6 → W/O (Wasser-in-Öl), >8 → O/W (Öl-in-Wasser). Felder: emulsifier, hlb,
     * type (o_w|w_o|dual), use, source. Werte variieren je Quelle → evidence med.
     *
     * @var list<array<string, mixed>>
     */
    public const HLB_VALUES = [
        ['emulsifier' => 'sojalecithin', 'label' => 'Sojalecithin', 'hlb' => 8.0, 'type' => 'o_w', 'use' => 'O/W-Emulsion, Schaum; je nach Fraktion ~4–9', 'source' => 'BGS'],
        ['emulsifier' => 'mono_diglyceride', 'label' => 'Mono-/Diglyceride', 'hlb' => 3.5, 'type' => 'w_o', 'use' => 'W/O, Krumen/Backwaren', 'source' => 'BGS'],
        ['emulsifier' => 'span60', 'label' => 'Sorbitanmonostearat (Span 60)', 'hlb' => 4.7, 'type' => 'w_o', 'use' => 'W/O-Emulgator', 'source' => 'Griffin/BGS'],
        ['emulsifier' => 'span80', 'label' => 'Sorbitanmonooleat (Span 80)', 'hlb' => 4.3, 'type' => 'w_o', 'use' => 'W/O-Emulgator', 'source' => 'Griffin/BGS'],
        ['emulsifier' => 'polysorbat80', 'label' => 'Polysorbat 80 (Tween 80)', 'hlb' => 15.0, 'type' => 'o_w', 'use' => 'starker O/W-Emulgator', 'source' => 'Griffin/BGS'],
        ['emulsifier' => 'polysorbat60', 'label' => 'Polysorbat 60 (Tween 60)', 'hlb' => 14.9, 'type' => 'o_w', 'use' => 'O/W, Sahne/Toppings', 'source' => 'Griffin/BGS'],
        ['emulsifier' => 'saccharoseester', 'label' => 'Saccharose-Ester', 'hlb' => 8.0, 'type' => 'dual', 'use' => 'breit einstellbar HLB ~1–16 je Veresterung', 'source' => 'Modernist Cuisine'],
        ['emulsifier' => 'natriumcaseinat', 'label' => 'Natriumcaseinat', 'hlb' => null, 'type' => 'o_w', 'use' => 'protein-basiert, O/W-Stabilisierung (kein klassischer HLB)', 'source' => 'BGS'],
    ];

    /**
     * Hydrokolloid-Dosierungen, optional nach agent gefiltert (Teilstring, case-insensitiv).
     *
     * @return list<array<string, mixed>>
     */
    public function hydrokolloidDosierungen(?string $agent = null): array
    {
        $rows = self::HYDROCOLLOID_DOSAGES;
        if ($agent !== null && trim($agent) !== '') {
            $a = mb_strtolower(trim($agent));
            $rows = array_values(array_filter($rows, fn ($r) => str_contains($r['agent'], $a) || str_contains(mb_strtolower($r['label']), $a)));
        }

        return array_map(fn ($r) => $r + [
            'evidence' => 'med',
            'hinweis' => 'Publizierte Dosier-Range (% vom Ansatzgewicht = Extraprozent). Herstellerangabe/Charge hat Vorrang; im Zweifel Vorversuch.',
        ], $rows);
    }

    /**
     * HLB-Werte, optional gefiltert.
     *
     * @return list<array<string, mixed>>
     */
    public function hlbWerte(?string $emulsifier = null): array
    {
        $rows = self::HLB_VALUES;
        if ($emulsifier !== null && trim($emulsifier) !== '') {
            $e = mb_strtolower(trim($emulsifier));
            $rows = array_values(array_filter($rows, fn ($r) => str_contains($r['emulsifier'], $e) || str_contains(mb_strtolower($r['label']), $e)));
        }

        return array_map(fn ($r) => $r + [
            'evidence' => 'med',
            'hinweis' => 'HLB-Skala 0–20: <6 W/O, >8 O/W. Werte variieren je Quelle — Richtwert, kein Absolutwert.',
        ], $rows);
    }
}
