<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;

/** Baut alle Fakten vor dem eigentlichen KI-Call und liefert einen kleinen Audit-Snapshot. */
class RecipeGenerationContextService
{
    /**
     * Prompt-Whitelist: NUR semantisch bedeutsame Leitplanken landen im KI-Kontext. Steuer-/Ableitungs-
     * Keys (ki_bilder, use_favorites_list, favorites_convenience_only, bio_praeferenz, cascade_step_id,
     * auto_dependencies, _*) sind Rauschen im Prompt und werden weggelassen. Bewusst additiv gehalten —
     * spätere Achsen (pax, ziel_portion_g, saison …) hier eintragen.
     */
    // Hinweis: `aroma_kueche` fehlt hier bewusst — sie bekommt einen eigenen, reichen Prompt-Block
    // (Label + Würz-Anker, s. build()), der Roh-Slug im parameter-Bündel wäre redundant.
    private const PROMPT_KEYS = [
        'convenience', 'frische', 'bio', 'bio_pref', 'bestand', 'level', 'sektor',
        'diaet_hart', 'allergen_nogo', 'aroma', 'occasion', 'serviceform', 'kompositions_stil', 'ziel_vk_eur',
        // L6 »Menge & Ziel« (Basisrezept: ziel_menge + ziel_einheit statt pax/portion)
        'pax', 'ziel_portion_g', 'saison', 'ziel_we_pct', 'ziel_einheit', 'ziel_menge',
    ];

    /**
     * Aroma-Küchen (L1.5, Achse 4 aus `_Entscheidungsachsen.md` v1.9) — Slug => [Label, Würz-Anker].
     * Die Würz-Anker sind VERBATIM aus dem verbindlichen Regelwerk (keine Erfindung); Technik/Archetyp
     * führt das Regelwerk nur exemplarisch, darum hier bewusst nur der belegte Würz-Anker. »Frei« (leer)
     * trägt keinen Block. Steuert die Würzung als Prompt-Direktive + speist die Aroma-Erdung (Anker-Graph).
     */
    private const KUECHE_ANKER = [
        'klassisch_de'  => ['label' => 'Klassisch DE', 'anker' => 'Regionale deutsche Aromen, traditionelle Pairings'],
        'franzoesisch'  => ['label' => 'Französisch', 'anker' => 'Butter, Schalotte, Weißwein, Estragon, Dijon, Crème fraîche, Demi-Glace'],
        'mediterran'    => ['label' => 'Mediterran', 'anker' => 'Olivenöl, Zitrone, Knoblauch, Kräuter der Provence, Tomate'],
        'italienisch'   => ['label' => 'Italienisch', 'anker' => 'Olivenöl, Parmesan, Basilikum, Tomate, Knoblauch, Balsamico, Salbei'],
        'asiatisch'     => ['label' => 'Asiatisch (allg.)', 'anker' => 'Sojasauce, Ingwer, Sesam, Reisessig, Chili, Limette, Koriander'],
        'japanisch'     => ['label' => 'Japanisch', 'anker' => 'Dashi, Soja, Mirin, Miso, Yuzu, Sesam, Ingwer, Nori'],
        'thai'          => ['label' => 'Thai', 'anker' => 'Zitronengras, Galgant, Fischsauce, Kokos, Thai-Basilikum, Limettenblatt, Chili'],
        'indisch'       => ['label' => 'Indisch', 'anker' => 'Kreuzkümmel, Kurkuma, Garam Masala, Ingwer, Koriander, Chili, Kokos, Ghee'],
        'orient'        => ['label' => 'Orient', 'anker' => 'Kreuzkümmel, Kardamom, Sumach, Granatapfel, Tahini, Minze'],
        'lateinamerika' => ['label' => 'Lateinamerika', 'anker' => 'Limette, Koriander, Chili, Avocado, Mais, Bohnen'],
        'neu_nordisch'  => ['label' => 'Neu-Nordisch', 'anker' => 'Wildkräuter, Pickled-Komponenten, Beerennoten, Räucherton'],
    ];

    /**
     * Reduziert das rohe Parameter-Bündel auf die Prompt-Whitelist und gleicht die Key-Sprache an den
     * Prompt-Text an (der Prompt nennt »niveau«/»anlass«, die kanonischen Keys heißen level/occasion).
     * So muss das Modell die Brücke nicht selbst raten.
     *
     * @param  array<string,mixed>  $parameter
     * @return array<string,mixed>
     */
    private function promptParameter(array $parameter): array
    {
        $p = array_intersect_key($parameter, array_flip(self::PROMPT_KEYS));
        if (array_key_exists('level', $p)) {
            $p['niveau'] = $p['level'];
            unset($p['level']);
        }
        if (array_key_exists('occasion', $p)) {
            $p['anlass'] = $p['occasion'];
            unset($p['occasion']);
        }

        return $p;
    }

    public function __construct(
        private KnowledgeContextService $knowledge,
        private GenerationContextService $generation,
        private RecipeTemplateService $templates,
        private TeamSettingsService $settings,
        private TokenEngine $tokens,
    ) {
    }

    public function build(Team $team, string $description, array $parameter, bool $vkModus): array
    {
        // Spec 37 (2026-08-07): Rezept-Typ explizit. Steuert (a) die typ-abhängige Niveau-Auswahl
        // (der Selektor liest params['rezept_typ']) und geht (b) als eigenes Kontext-Feld an die KI
        // — Gürtel & Hosenträger zur Prompt-Einleitung (Basisrezept = Baustein, Gericht = Teller).
        $rezeptTyp = $vkModus ? 'gericht' : 'basisrezept';
        $wissen = $this->knowledge->contextFor('ai_generate_recipe', $description, $parameter['kompositions_stil'] ?? null, [], $parameter + ['rezept_typ' => $rezeptTyp]);
        $prompt = [
            'rezept_typ' => $vkModus
                ? 'GERICHT (essfertig angerichteter Verkaufsteller — Komposition erlaubt)'
                : 'BASISREZEPT (wiederverwendbarer Baustein / EINE Komponente — kein angerichteter Teller)',
            'description' => $description,
            'parameter' => $this->promptParameter($parameter),
        ];
        if (($typ = $this->settings->kuechenTyp($team)) !== null) {
            $prompt = ['kuechen_profil' => 'Mandanten-Profil (Soft-Default): ' . TeamSettingsService::KUECHEN_TYPEN[$typ]] + $prompt;
        }
        // Aroma-Küche (L1.5, Achse 4): gewählte Küche trägt ihren Würz-Anker als Prompt-Direktive
        // (verbindliches Regelwerk). Zusätzlich fließen Küche-Anker + Aroma-Freitext in die Erdungs-
        // Query (unten) — sonst erdete die Aroma-Vorgabe den Anker-Graph nicht (Audit-Fix). »Frei«=kein Block.
        $kueche = (string) ($parameter['aroma_kueche'] ?? '');
        $aromaFrei = trim((string) ($parameter['aroma'] ?? ''));
        $kuecheAnker = self::KUECHE_ANKER[$kueche] ?? null;
        if ($kuecheAnker !== null) {
            $prompt['aroma_kueche'] = [
                'kueche' => $kuecheAnker['label'],
                'wuerz_anker' => $kuecheAnker['anker'],
                'hinweis' => 'Würze in Richtung dieser Küche (Anker sind Leit-, keine Pflichtzutaten); '
                    . 'die per-Zutat-Pairing-Erdung bleibt die präzisere Quelle an der Hauptzutat.',
            ];
        }
        // Foodpairing-Composer B (2026-08-22): gezielte Seed-Anker aus dem Composer (Slug-Liste).
        // Sie sind der VERBINDLICHE kreative Kern — reisen als eigener Pflicht-Key (unten) UND als
        // Erdungs-Signal in die GP-Kandidaten-Suche (damit die Leit-Aromen echte GPs bekommen).
        $seedAnker = array_values(array_filter(array_map(
            fn ($s) => trim((string) $s),
            (array) ($parameter['seed_anker'] ?? []),
        )));
        // Erdungs-Query: Beschreibung + Aroma-Freitext + Küche-Anker (+ Seed-Anker) → der Anker-Graph
        // erdet jetzt auch an der Aroma-Vorgabe (nicht mehr nur an der Beschreibung).
        $seedErdung = $seedAnker === [] ? '' : ' ' . str_replace('_', ' ', implode(' ', $seedAnker));
        $erdungsText = trim($description . ' ' . $aromaFrei . ' ' . ($kuecheAnker['anker'] ?? '') . $seedErdung);
        foreach ($this->generation->forGeneration(
            $team, $erdungsText, $vkModus,
            (bool) ($parameter['use_favorites_list'] ?? false),
            (bool) ($parameter['favorites_convenience_only'] ?? false),
            isset($parameter['bestand']) ? (string) $parameter['bestand'] : null,
        ) as $key => $value) {
            $prompt[$key] = $value;
        }

        // Foodpairing-Composer B2 (2026-08-22): verbindlicher Leit-Aromen-Block aus den Seed-Ankern —
        // GETRENNT vom weichen `pairing`-Angebot (GenerationContextService). Der normale Generator
        // (ohne Seeds) bekommt diesen Key NIE → nur die Composer-Kreation ist gezielt-verbindlich.
        if ($seedAnker !== []) {
            $pairing = app(\Platform\FoodAlchemist\Services\PairingService::class);
            $leitAromen = [];
            foreach ($seedAnker as $slug) {
                $res = $pairing->neighborsForName($slug, null, 12);
                if (($res['anker'] ?? null) === null) {
                    continue;
                }
                $palette = [];
                foreach ($res['partner'] as $p) {
                    $name = is_array($p) ? ($p['display_de'] ?? $p['slug'] ?? null) : ($p->display_de ?? $p->slug ?? null);
                    if ($name === null) {
                        continue;
                    }
                    // Harmonie-Stärke wie im Wissens-Textblock (●●● best / ●● gut).
                    $axis = is_array($p) ? ($p['axis'] ?? null) : ($p->axis ?? null);
                    $level = is_array($p) ? ($p['level'] ?? null) : ($p->level ?? null);
                    $sym = $axis === 'harmony' ? ($level >= 3 ? ' ●●●' : ($level >= 2 ? ' ●●' : ' ●')) : '';
                    $palette[] = $name . $sym;
                    if (count($palette) >= 10) {
                        break;
                    }
                }
                $leitAromen[] = [
                    'aroma' => $res['anker']['display_de'] ?: $res['anker']['slug'],
                    'palette' => $palette,
                ];
            }
            if ($leitAromen !== []) {
                $prompt['pairing_vorgabe'] = [
                    'rolle' => 'verbindliche_leit_aromen',
                    'hinweis' => 'Diese Leit-Aromen prägen das Rezept BEWUSST (gezielte Foodpairing-Kreation). '
                        . 'Sie MÜSSEN als Zutaten/Komponenten vorkommen; ihre Harmonie-Palette (●●●/●●) ist die '
                        . 'bevorzugte Auswahl zum Abrunden. Setze zusätzlich bewusste Kontraste (Säure/Fett/Textur) '
                        . 'aus Kochwissen + Pairing-Prinzip. Erfinde keine unbelegten Paarungen; Grounding '
                        . '(gp_kandidaten) hat Vorrang bei der Benennung. Baue EIN kohärentes Rezept.',
                    'leit_aromen' => $leitAromen,
                ];
            }
        }

        $descriptionTokens = $this->tokens->tokenize($description);
        $templateContext = $this->templates->templates($team)->map(function ($template) use ($team, $descriptionTokens) {
            $nameTokens = $this->tokens->tokenize((string) $template->name);
            $score = count(array_intersect($descriptionTokens, $nameTokens));

            return [
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'score' => $score,
                'slots' => $this->templates->slotsFor($team, (int) $template->id),
            ];
        })->sortByDesc('score')->take(5)->values()->all();
        if ($templateContext !== []) {
            $prompt['rezept_templates'] = $templateContext;
        }

        // Kontext-Inspektor (2026-08-07): kompaktes, UI-fertiges Bündel „auf welches Wissen
        // greift der Generator" — gruppierte Wissens-Docs je Kanal + gematchte Templates
        // (nur score>0 = echt zur Beschreibung passend) + Zeichen-Budget des Wissens-Blocks.
        // Reine String-/Int-Listen → gefahrlos durch Job-Cache + Livewire-Ergebnis reichbar.
        $kontext = [
            'wissen' => $wissen['used_by_category'] ?? [],
            'chars' => (int) ($wissen['total_chars'] ?? 0),
            'templates' => array_values(array_map(
                fn ($t) => ['id' => $t['id'], 'name' => $t['name']],
                array_filter($templateContext, fn ($t) => ($t['score'] ?? 0) > 0),
            )),
            // Foodpairing-Composer B2: UI-Audit „Generator nutzt Vorgabe-Anker: …" (leer im Normalfall).
            'pairing_vorgabe' => array_values(array_map(
                fn ($x) => (string) ($x['aroma'] ?? ''),
                $prompt['pairing_vorgabe']['leit_aromen'] ?? [],
            )),
        ];

        return [
            'prompt' => $prompt,
            'knowledge' => $wissen['block'],
            'knowledge_used' => $wissen['files_used'],
            'kontext' => $kontext,
            'snapshot' => [
                'knowledge_files' => $wissen['files_used'],
                'template_ids' => array_column($templateContext, 'id'),
                'pairing_keys' => array_values(array_filter(array_keys($prompt), fn ($key) => str_contains((string) $key, 'pair'))),
                'built_at' => now()->toIso8601String(),
            ],
        ];
    }
}
