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
}
