<?php

namespace Platform\FoodAlchemist\Services\Conformance;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\RecipeReviseService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\RecipeStepService;

/**
 * Konformitäts-Adapter für Rezepte — deckt BEIDE Sichten ab (Basisrezept UND
 * Verkaufsgericht), weil beide dieselbe Tabelle sind; `is_sales_recipe` wählt
 * die Regelwerke. Die Kontext-Felder spiegeln bewusst {@see RecipeReviewService::kontext}
 * (bewährte Accessor-Kette), erweitert um Grounding-/Sub-Rezept-Marker, die für
 * die §-Prüfung (Naming, Sub-Rezept-Regel, Default-GPs) tragen.
 */
class RecipeConformanceAdapter implements ConformanceAdapter
{
    public function artifactType(): string
    {
        return 'recipe';                                              // Basisrezept UND VK — dieselbe Tabelle
    }

    public function unterstuetztHeilung(): bool
    {
        return true;                                                 // Freitext-Revise via recipe.ueberarbeiten
    }

    /**
     * Was die Selbstheilung NICHT ändern kann, geht nicht in die Direktive:
     *  · Befunde an einer Verweiszeile (Unterrezept) — C3 lässt die Zeile unangetastet, geheilt wird im Unterrezept.
     *  · eine offene Basisrezept-Lücke („Püree: Petersilienwurzel" ohne Bestand), wenn der Befund die fehlende
     *    Verknüpfung meint. Die füllt nur freigegebener Bestand oder ein Mensch; ein Umschreiben kann sie nur
     *    verletzen (Präfix weg → Rohware). Meint der Befund an derselben Zeile etwas anderes (Typ-Präfix, Menge),
     *    bleibt er heilbar.
     *  · Beschreibung/Zubereitung, die von Hand gepflegt sind — revise schreibt sie nie (siehe unten).
     *  · §6.5 Garverlust/Einkochverlust: revise setzt `cooking_loss_pct` nicht, der Befund überlebt jede Runde
     *    (demo Lauf 87: 3798/3799/3800 je 3 Calls ≈ 33k Tokens für nichts). Bis Paket 11 (Garverlust-Tabelle).
     * Im Zweifel heilbar: ein Feld, das keiner Zeile zuzuordnen ist, bleibt drin.
     */
    public function heilbar(Team $team, int $id, array $befunde): array
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $id);
        if ($r === null) {
            return $befunde;
        }
        $heuristik = app(\Platform\FoodAlchemist\Services\Matching\MatchHeuristics::class);
        $engine = app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class);
        $verweise = [];
        $luecken = [];
        $schnittNurImGp = [];
        foreach ($r->ingredients as $z) {
            // Lauf 89 (3814): §2-Code-Befund „zutat:Rinderbeinscheiben: frisch, geschnitten" — die Schnittform steht nur im
            // GP-NAMEN (Stamm), die Zeile heißt „Rinderbeinscheiben: frisch". Das heilt kein Umschreiben des Rezepts.
            $gpName = (string) ($z->gp?->name ?? '');
            if ($gpName !== '') {
                $schnitt = fn (string $s) => array_filter($engine->tokenize($s), fn ($t) => $engine->isCutFormToken($t) && $engine->istReinesMerkmal($t)) !== [];
                if ($schnitt($gpName) && ! $schnitt(trim(($z->raw_text ?? '') . ' ' . ($z->display_name ?? '') . ' ' . ($z->note ?? '')))) {
                    $schnittNurImGp[self::zeilenSchluessel($gpName)] = true;
                }
            }
            foreach (array_filter([$z->display_name, $z->raw_text, $z->referencedRecipe?->name, $z->gp?->name]) as $text) {
                if ($z->referenced_recipe_id !== null) {
                    $verweise[self::zeilenSchluessel((string) $text)] = true;
                } elseif ($z->gp_id === null && $heuristik->istBasisrezeptZeile((string) $text)) {
                    $luecken[self::zeilenSchluessel((string) $text)] = true;
                }
            }
        }

        return array_values(array_filter($befunde, function (array $b) use ($r, $verweise, $luecken, $schnittNurImGp) {
            $feld = trim((string) ($b['feld'] ?? ''));
            if (preg_match('/^zutat\s*:\s*(.+)$/iu', $feld, $m) === 1) {
                $k = self::zeilenSchluessel($m[1]);
                if (isset($schnittNurImGp[$k]) && preg_match('/§\s*2(?![\d.])/u', (string) ($b['paragraph'] ?? '')) === 1) {
                    return false;   // gehört in die GP-Pflege, nicht in die Rezept-Heilung
                }
                if (isset($verweise[$k])) {
                    return false;
                }
                if (isset($luecken[$k]) && self::meintVerknuepfung((string) ($b['begruendung'] ?? ''))) {
                    return false;
                }
            }
            if (self::istGarverlust((string) ($b['paragraph'] ?? ''), $feld)) {
                return false;
            }
            if ($r->description_source === 'manual' && mb_strtolower($feld) === 'description') {
                return false;
            }
            if ($r->preparation_source === 'manual' && preg_match('/^(schritt|preparation|zubereitung)/iu', $feld) === 1) {
                return false;
            }

            return true;
        }));
    }

    /** §6.5 / F6.5 (auch „§6.5a", „Regelwerk … §6.5") oder ein Garverlust-Feld. */
    private static function istGarverlust(string $paragraph, string $feld): bool
    {
        return preg_match('/(?:§|\bF)\s*6\.5(?!\d)/u', $paragraph) === 1
            || preg_match('/cooking_loss|garverlust|einkochverlust/iu', $feld) === 1;
    }

    private static function zeilenSchluessel(string $text): string
    {
        $t = (string) preg_replace('/\([^)]*\)/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($t)));
    }

    /** Begründung handelt von fehlender Verknüpfung/Bestand — nicht von Name, Typ oder Menge der Zeile. */
    private static function meintVerknuepfung(string $begruendung): bool
    {
        $b = mb_strtolower($begruendung);
        if (preg_match('/präfix|vokabular|schreibweise|benenn|bezeichn|menge|einheit|gramm|anteil|diät|vegan|vegetar|allergen/u', $b) === 1) {
            return false;
        }

        return preg_match('/verknüpf|verlink|verweis|unterrezept|sub-?rezept|basisrezept|bestand|lücke|nicht (gemappt|zugeordnet|geerdet|verbunden)|ungemappt|ohne (gp|grundprodukt|zuordnung)|kein(e|en)? (gp|grundprodukt|zuordnung)/u', $b) === 1;
    }

    /**
     * KONZENTRATIONS-MARKER: Zutaten-Formen, deren Masse durch Wasserentzug oder Einkochen
     * bereits reduziert ist. Sie sind Würz- und Aromakomponenten, nie die Hauptmasse eines
     * Gerichts — 1 kg Trockentomate entspricht grob 8–14 kg Frischware.
     *
     * Geprüft wird gegen NAME + `processing` + `form`, nicht gegen `condition`: `condition`
     * trägt nur die vier §9-Zustände (frisch|TK|trocken|konserviert), und „getrocknet" steckt
     * in der Verarbeitung. GP 13757 („Tomaten: TK, getrocknet") hat sogar `condition = NULL` —
     * ein zustand-basierter Check wäre dort blind.
     */
    private const KONZENTRAT_MARKER = [
        'getrocknet', 'trockentomate', 'konzentrat', 'pulver', 'granulat', 'extrakt',
        'reduktion', 'demi-glace', 'instant',
    ];

    /**
     * Anteil an der Einsatzmasse, ab dem eine Konzentrat-Zutat als Hauptmasse gilt.
     *
     * FACHLICHE SETZUNG, nicht gemessen — darum konfigurierbar und als `hart`, aber NICHT
     * blockierend. 20 % ist bewusst hoch angesetzt: legitime Fälle (eine Pfeffer-Reduktion in
     * einer Sauce, Tomatenmark als Röstbasis) liegen deutlich darunter, der Fall aus Lauf 65
     * lag bei 57,9 %. Wer den Wert senkt, bekommt Befunde an Saucen; wer ihn hebt, verliert den
     * Fall. Er gehört Dominique, nicht dem Code.
     */
    private const KONZENTRAT_ANTEIL_MAX = 0.20;

    public function deterministischeBefunde(Team $team, int $id): array
    {
        return [...$this->konzentratBefunde($team, $id), ...$this->regelwerkBefunde($team, $id), ...$this->breiteBefunde($team, $id)];
    }

    /**
     * Spec 80 G1 — was das Regelwerk eindeutig entscheidet, prüft der Code bei JEDER Prüfung (kostet nichts,
     * hält auch alte Befunde aktuell): §1.2 Typ-Präfix außerhalb des Vokabulars, §2 frisches GP in
     * Schnittform, §10 Bio-GP ohne Bio-Anspruch im Rezept, §8.3 Satzzahl der Beschreibung. Listen und Schwellen
     * kommen seit Spec 81 aus den Regeln (Einstellungen › Regeln); jeder Befund trägt `quelle = code` + Regel-ID.
     *
     * @return list<array<string, mixed>>
     */
    private function regelwerkBefunde(Team $team, int $id): array
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $id);
        if ($r === null || $r->is_sales_recipe) {
            return [];
        }
        $befunde = [];
        $regel = static fn (string $k) => \Platform\FoodAlchemist\Services\Regeln\RegelBuch::falls($k);
        $herkunft = static fn (string $k) => ['quelle' => 'code', 'rule_id' => $regel($k)?->id];

        $praefix = \Platform\FoodAlchemist\Support\RezeptTypVokabular::praefix((string) $r->name);
        if ($praefix !== null && \Platform\FoodAlchemist\Support\RezeptTypVokabular::istGeladen()
            && \Platform\FoodAlchemist\Support\RezeptTypVokabular::finde($praefix) === null) {
            $befunde[] = [
                'paragraph' => '§1.2', 'schweregrad' => 'hart', 'feld' => 'name',
                'begruendung' => "Typ-Präfix »{$praefix}« steht nicht im kontrollierten Typ-Vokabular (§1.2).",
                'vorschlag' => 'Einen Typ aus dem Vokabular der passenden Hauptgruppe wählen (z. B. Püree, Fond, Beilage).',
                'konfidenz' => 1.0,
            ] + $herkunft('basisrezept.1.2.typ');
        }

        $bioImRezept = preg_match('/\bbio\b/iu', (string) $r->name . ' ' . (string) $r->description) === 1;
        // Die Detail-Sicht lädt GPs nur mit wenigen Spalten (ohne Zustand/Verarbeitung/Form/Bio). Ohne diese Felder
        // schwiegen §2 und §10 still — darum die Prüf-Felder hier gezielt nachladen.
        $gpFelder = \Platform\FoodAlchemist\Models\FoodAlchemistGp::query()
            ->whereKey($r->ingredients->pluck('gp_id')->filter()->unique()->values()->all())
            ->get(['id', 'name', 'condition', 'processing', 'form', 'bio'])->keyBy('id');
        foreach ($r->ingredients as $z) {
            $gp = $gpFelder->get((int) $z->gp_id);
            if ($gp === null) {
                continue;
            }
            if (mb_strtolower((string) $gp->condition) === 'frisch'
                && ($suffix = \Platform\FoodAlchemist\Support\GpKorrektur::verarbeitungIn($gp)) !== null) {
                $befunde[] = [
                    'paragraph' => '§2', 'schweregrad' => 'hart', 'feld' => 'zutat:' . $gp->name,
                    'begruendung' => "Frische Zutat in Schnittform »{$suffix}« — Verarbeitung in der Küche gehört nicht ins Grundprodukt (§2).",
                    'vorschlag' => 'Auf die rohe Grundform umstellen, »' . $suffix . '« in die Zeilen-Notiz.',
                    'konfidenz' => 1.0,
                ] + $herkunft('basisrezept.2.schnittform');
            }
            if (! $bioImRezept && \Platform\FoodAlchemist\Support\GpKorrektur::istBio($gp)) {
                $befunde[] = [
                    'paragraph' => '§10', 'schweregrad' => 'hart', 'feld' => 'zutat:' . $gp->name,
                    'begruendung' => 'Bio-Grundprodukt ohne Bio-Anspruch im Rezept — Standard vor Bio (§10).',
                    'vorschlag' => 'Konventionelle Variante desselben Grundprodukts wählen.',
                    'konfidenz' => 1.0,
                ] + $herkunft('basisrezept.10.bio');
            }
        }

        $beschreibung = trim((string) $r->description);
        $saetze = $beschreibung === '' ? 0 : count(array_filter(preg_split('/(?<=[.!?])\s+/u', $beschreibung) ?: [], fn ($t) => trim($t) !== ''));
        $satzRegel = $regel('basisrezept.8.3.saetze');
        if ($beschreibung !== '' && $satzRegel !== null
            && ! app(\Platform\FoodAlchemist\Services\Regeln\RegelMotor::class)->art('schwelle')->erfuellt($satzRegel, (float) $saetze)) {
            $spanne = ($satzRegel->params['min'] ?? '') . '–' . ($satzRegel->params['max'] ?? '');
            $befunde[] = [
                'paragraph' => '§8.3', 'schweregrad' => $satzRegel->wirkung === 'warnen' ? 'weich' : 'hart', 'feld' => 'description',
                'begruendung' => "Beschreibung hat {$saetze} Satz/Sätze, verlangt sind {$spanne} (§8.3).",
                'vorschlag' => "Beschreibung auf {$spanne} sachliche Sätze bringen.",
                'konfidenz' => 1.0,
            ] + $herkunft('basisrezept.8.3.saetze');
        }

        return $befunde;
    }

    /**
     * Spec 81 Paket 7 — Regeln als Daten für Verkaufsgerichte (Name §1.1/§1.2, Schritte §3.8) und Kostformen
     * (vegan/vegetarisch ausgelobt → ausgeschlossene Zutaten). Jede Regel greift nur, wenn sie aktiv ist.
     *
     * @return list<array<string, mixed>>
     */
    private function breiteBefunde(Team $team, int $id): array
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $id);
        if ($r === null) {
            return [];
        }
        $motor = app(\Platform\FoodAlchemist\Services\Regeln\RegelMotor::class);
        $buch = \Platform\FoodAlchemist\Services\Regeln\RegelBuch::class;
        $out = [];
        $melde = static function (array $befunde, string $feld) use (&$out): void {
            foreach ($befunde as $b) {
                $out[] = ['paragraph' => (string) $b['paragraph'], 'schweregrad' => $b['schweregrad'], 'feld' => $feld,
                    'begruendung' => $b['begruendung'], 'vorschlag' => (string) ($b['vorschlag'] ?? ''), 'konfidenz' => 1.0,
                    'quelle' => 'code', 'rule_id' => $b['rule_id']];
            }
        };
        $name = (string) $r->name;

        if ($r->is_sales_recipe) {
            if (($hg = $buch::falls('vk.1.1.hg')) !== null) {
                $code = preg_match('/^\[([^\]]+)\]/u', $name, $m) === 1 ? $m[1] : '';
                $melde($code === '' ? [['paragraph' => $hg->paragraph, 'schweregrad' => 'hart', 'begruendung' => 'Hauptgruppen-Kürzel in eckigen Klammern fehlt, z. B. „[HG] …" (§1.1).',
                    'vorschlag' => null, 'rule_id' => $hg->id]] : $motor->pruefe($hg, $code), 'name');
            }
            if (($bs = $buch::falls('vk.1.1.bausteine')) !== null) {
                $rest = trim((string) preg_replace('/^\[[^\]]+\]\s*/u', '', $name));
                $anzahl = count(array_filter(array_map('trim', explode('|', $rest)), static fn ($t) => $t !== ''));
                $melde($motor->pruefe($bs, (string) $anzahl), 'name');
            }
            foreach ($buch::fuerZiel('vk.name') as $regel) {
                $melde($motor->pruefe($regel, $name), 'name');
            }
            if (($sm = $buch::falls('vk.3.8.schritt_mengen')) !== null) {
                foreach ([...$r->steps()->get(['position', 'text']), ...$r->platingSteps()->get(['position', 'text'])] as $schritt) {
                    $melde($motor->pruefe($sm, (string) $schritt->text), 'schritt:' . $schritt->position);
                }
            }
        }

        // Zeilen-Regeln, die vom Rezept-Typ abhängen (z. B. Hausstandard: Jus/Fond/Brühe aus Knochen und Abschnitten).
        $praefix = \Platform\FoodAlchemist\Support\RezeptTypVokabular::praefix($name);
        $typ = $praefix !== null ? \Platform\FoodAlchemist\Support\RezeptTypVokabular::finde($praefix) : null;
        if ($typ !== null) {
            foreach ($buch::fuerZiel('rezeptzeile.typ') as $regel) {
                foreach ($r->ingredients as $z) {
                    $text = (string) ($z->gp?->name ?? $z->referencedRecipe?->name ?? $z->raw_text);
                    $melde($motor->pruefe($regel, $text, ['typ' => $typ]), 'zutat:' . $text);
                }
            }
        }

        $kostform = $r->spec_is_vegan ? $buch::falls('ernaehrung.vegan') : ($r->spec_is_vegetarian ? $buch::falls('ernaehrung.vegetarisch') : null);
        if ($kostform !== null) {
            foreach ($r->ingredients as $z) {
                $text = (string) ($z->gp?->name ?? $z->referencedRecipe?->name ?? $z->raw_text);
                $melde($motor->pruefe($kostform, $text), 'zutat:' . $text);
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function konzentratBefunde(Team $team, int $id): array
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $id);
        if ($r === null) {
            return [];
        }
        $schwelle = (float) config('foodalchemist.conformance.konzentrat_anteil_max', self::KONZENTRAT_ANTEIL_MAX);
        if ($schwelle <= 0.0 || $schwelle >= 1.0) {
            return [];
        }

        // Einsatzmasse nur aus Zeilen mit MASSEN-Einheit bilden. Eine Stück-/Bund-Zeile trägt
        // hier 0 g bei und würde den Anteil künstlich hochrechnen — dieselbe Falle, die im
        // Yield-Check von DataQualityService dokumentiert ist. Ist die Masse nicht sauber
        // bestimmbar, wird NICHT geraten und der Check schweigt.
        $gewichte = [];
        $summe = 0.0;
        $unklar = false;
        foreach ($r->ingredients as $z) {
            $menge = (float) $z->quantity;
            $slug = (string) ($z->unit?->slug ?? '');
            $gramm = match ($slug) {
                'g', 'ml' => $menge,                    // ml ≈ g: für einen Anteils-Check genügt das
                'kg', 'l' => $menge * 1000.0,
                default => null,
            };
            if ($gramm === null || $menge <= 0.0) {
                if ($menge > 0.0) {
                    $unklar = true;
                }

                continue;
            }
            $summe += $gramm;
            $gewichte[] = [$z, $gramm];
        }
        if ($unklar || $summe <= 0.0 || $gewichte === []) {
            return [];
        }

        $befunde = [];
        foreach ($gewichte as [$z, $gramm]) {
            $gp = $z->gp;
            if ($gp === null) {
                continue;                               // ungeerdete Zeile: andere Baustelle
            }
            $text = mb_strtolower(trim(
                (string) $gp->name.' '.(string) ($gp->processing ?? '').' '.(string) ($gp->form ?? '')
            ));
            $marker = null;
            foreach (self::KONZENTRAT_MARKER as $m) {
                if (str_contains($text, $m)) {
                    $marker = $m;

                    break;
                }
            }
            if ($marker === null) {
                continue;
            }
            $anteil = $gramm / $summe;
            if ($anteil <= $schwelle) {
                continue;
            }
            $befunde[] = [
                'paragraph' => '§6',                    // Mengen, Einheiten & Yield
                'schweregrad' => 'hart',
                'feld' => 'zutaten',
                'begruendung' => sprintf(
                    '»%s« ist eine Konzentrat-/Trockenform (Marker »%s») und trägt %s %% der '
                    .'Einsatzmasse (%s g von %s g). Solche Formen sind Würz- und Aromakomponenten, '
                    .'nicht die Hauptmasse — als Hauptzutat ist die Menge um etwa eine '
                    .'Größenordnung zu hoch.',
                    (string) $gp->name,
                    $marker,
                    number_format($anteil * 100, 1, ',', '.'),
                    number_format($gramm, 0, ',', '.'),
                    number_format($summe, 0, ',', '.'),
                ),
                'vorschlag' => 'Entweder die Menge auf eine Würz-Dosierung senken, oder auf die '
                    .'passende Grundform wechseln (frisch / konserviert / passiert).',
                'konfidenz' => 1.0,                     // deterministisch gerechnet, nicht geschätzt
            ];
        }

        return $befunde;
    }

    public function pruefauftrag(Team $team, int $id): array
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $id);
        if ($r === null) {
            throw new \RuntimeException('Rezept nicht gefunden oder nicht sichtbar.');
        }
        $vk = (bool) $r->is_sales_recipe;
        // Zeilen, für die die Kaskade gerade ein Unterrezept baut: noch nicht verknüpft, aber kein offenes Loch
        // (demo Lauf 87, Jus 3793: §4 F4.1 „nicht als Sub-Rezept markiert“ gemeldet — löste eine Heilrunde aus).
        $geplant = \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency::query()
            ->whereIn('ingredient_id', $r->ingredients->pluck('id')->all())
            ->pluck('ingredient_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();

        $kontext = [
            'artefakt_typ' => $vk ? 'Verkaufsgericht (VK)' : 'Basisrezept/Komponente',
            'name' => $r->name,
            'kategorie' => $r->kategorie?->label,
            'beschreibung' => $r->description,
            'zutaten' => $r->ingredients->map(fn ($z) => [
                'text' => $z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text,
                'menge' => (float) $z->quantity,
                'einheit_slug' => $z->unit?->slug,
                'geerdet' => $z->gp_id !== null || $z->referenced_recipe_id !== null,
                'ist_sub_rezept' => $z->referenced_recipe_id !== null || isset($geplant[(int) $z->id]),
            ] + (isset($geplant[(int) $z->id]) && $z->referenced_recipe_id === null
                ? ['unterrezept_in_arbeit' => 'wird in diesem Lauf als eigenes Basisrezept gebaut und danach verknüpft — kein Verstoß']
                : []))->values()->all(),
        ];

        if ($vk) {
            // Verkaufs-Facetten sind Prüf-MASSSTAB (passt Name/Klasse zur §-Regel?),
            // gespiegelt aus dem Review-Kontext — kein Schreibziel.
            $kontext['speisen_klasse'] = $r->dishClass?->label;
            $kontext['diaetform'] = $r->dishClass?->diet_form;
            $kontext['portion_g'] = $r->sales_quantity_per_unit_g;
            $kontext['verkaufseinheiten'] = $r->sales_unit_count;
            // Die Diätklasse setzt die Anreicherung bei der Freigabe (SpeisenKlassenService) — direkt nach dem Bau ist
            // sie oft noch leer. Das ist kein Regelverstoß (demo 3754: §2.2 hart gemeldet, nie heilbar).
            if ($r->dish_class_id === null) {
                $kontext['noch_offen_bis_freigabe'] = 'Speisen-Klasse / Diätform wird bei der Freigabe gesetzt — fehlt sie jetzt, NICHT melden.';
            }
        } else {
            $kontext['ansatz_kg'] = $r->yield_kg_manual ?? $r->yield_kg;
            $kontext['ansatz_stueck'] = $r->yield_pieces;
            // Spec 80 G2: diese Punkte prüft der Code bei jeder Prüfung selbst (regelwerkBefunde) —
            // die KI soll sie nicht noch einmal melden (spart Ausgabe, keine Dubletten mit anderem §-Label).
            // Spec 81: aus den aktiven Regeln erzeugt statt hart codiert — was der Code prüft, muss die KI nicht prüfen.
            $geprueft = [];
            foreach (\Platform\FoodAlchemist\Services\Regeln\RegelBuch::alle() as $rg) {
                if ($rg->regelwerk === 'basisrezept' && $rg->art !== 'zuordnung' && $rg->paragraph !== null) {
                    $geprueft[$rg->paragraph . ' ' . $rg->titel] = true;
                }
            }
            if ($geprueft !== []) {
                $kontext['vom_code_geprueft'] = implode(' · ', array_keys($geprueft)) . ' — diese Punkte NICHT melden.';
            }
        }

        // Basisrezept → Basisrezepte-Regelwerk (§-Dossiers). VK → zusätzlich das
        // Verkaufsgerichte-Regelwerk (Einzel-Dossier, kein §-Split). Volle §-Texte
        // lädt der ConformanceService anhand dieser Slug-Präfixe.
        $praefixe = ['regelwerk-basisrezepte-'];
        if ($vk) {
            $praefixe[] = 'regelwerk.regelwerk_verkaufsgerichte';
        }

        return [
            // Stabiler Domaenen-Schluessel — NICHT der Anzeigetext aus dem Kontext.
            'artefakt' => $vk ? 'vk' : 'basisrezept',
            'kontext' => $kontext,
            'regelwerk_praefixe' => $praefixe,
            'target_table' => 'foodalchemist_recipes',
        ];
    }

    /**
     * Selbstheil-Runde: EIN Freitext-Revise (`recipe.ueberarbeiten`) nach der Verstoß-
     * Direktive, angewendet über DIESELBE Strecke wie der manuelle „✨ KI-Überarbeiten"
     * (syncZeilen → syncIngredients mit #508-Grounding; Texte mit Lineage ki, Override-
     * First). Gespiegelt aus {@see \Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::ueberarbeitungUebernehmen}
     * — ohne den Livewire-Zustand. Best-effort: liefert die KI nichts Verwertbares,
     * bleibt das Rezept unangetastet und der Verstoß steht danach als Hinweis.
     */
    public function revise(Team $team, int $id, string $direktive, array $befunde = []): void
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $id);
        if ($r === null) {
            return;
        }

        $promptKey = $r->is_sales_recipe ? 'vk.ueberarbeiten' : 'recipe.ueberarbeiten';

        /*
         * Spec 52/C5 — die Selbstheilung war der einzige Schritt OHNE jedes Wissen.
         *
         * Gemessen 2026-09-10: `propose()` bekam hier genau ein Argument. Kanon gibt es für
         * `recipe.ueberarbeiten` nicht (keine Zeile), Bindungen sind seit Paket 3 abgeschafft,
         * und `contextFor()` wurde nie gerufen. Das Modell sollte also einen §-Verstoß
         * korrigieren, ohne den § zu kennen.
         *
         * Zwei Korrekturen, beide aus Befund I5/I6:
         *  · Regelquelle ist der KANON DES GENERATORS — dieselben verbindlichen §§, gegen die
         *    das Rezept erzeugt und geprüft wurde. Nicht drei per Ähnlichkeit gewürfelte aus 61.
         *  · Suchanlass ist der BEFUND (Paragraph + Begründung), nicht die Rezeptbeschreibung.
         *    Bei „Menge auf 4 Portionen" entschied sonst der Text „Cremiges Karottenpüree mit
         *    Ingwer", welches Fachwissen mitkam.
         *
         * Kein stiller Ausfall: fehlt der Kanon, wirft der Aufbau — dann ist die Konfiguration
         * kaputt und nicht die Heilung heimlich blind.
         */
        $kanonKey = $r->is_sales_recipe ? 'vk.generator' : 'recipe.generator';
        $anlass = trim($direktive.' '.collect($befunde)
            ->map(fn ($b) => trim((string) ($b['paragraph'] ?? '').' '.(string) ($b['begruendung'] ?? $b['reason'] ?? '')))
            ->filter()->implode(' '));
        $wissen = app(\Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::class)->contextFor(
            $team, $promptKey, $anlass !== '' ? $anlass : (string) $r->name,
            null, [], ['_kanon_prompt_key' => $kanonKey] + \Platform\FoodAlchemist\Services\Knowledge\RezeptAchsen::fuer($r),
        );
        $optionen = \Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::proposeOptionen($wissen)
            + ['_kanon_prompt_key' => $kanonKey]
            // Spec 80 C6: Kosten und Verlauf der Heilung dem Rezept zuordnen (vorher target_id = null, 15/15).
            + ['target_table' => 'foodalchemist_recipes', 'target_id' => $id];

        $vorschlag = app(AiGatewayService::class)->propose($promptKey, [
            'anweisung' => $direktive,
            'name' => $r->name,
            'kategorie' => $r->is_sales_recipe ? $r->dishMainGroup?->code : $r->kategorie?->label,
            'diaetform' => $r->is_sales_recipe ? $r->dishClass?->diet_form : null,
            'description' => $r->description,
            'preparation' => $r->preparation,
            'zutaten' => $r->ingredients->map(fn ($z) => [
                'id' => $z->id,
                'text' => $z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text,
                'quantity' => (float) $z->quantity,
                'einheit_slug' => $z->unit?->slug,
            ])->values()->all(),
        ], $optionen);

        $werte = $vorschlag->werte;
        $conf = max(0.0, min(1.0, $vorschlag->confidence));

        // Zutaten: Voll-Ersatz über den geteilten Revise-Pfad (#508-Grounding hängt dran).
        if (! empty($werte['zutaten']) && is_array($werte['zutaten'])) {
            $zeilen = app(RecipeReviseService::class)->syncZeilen($r, $werte['zutaten']);
            // Spec 80 C3: Verweiszeilen (Unterrezepte) fasst die Selbstheilung nicht an. Lässt die KI eine
            // weg, kommt sie unverändert zurück — ein Befund am Unterrezept wird dort geheilt, nicht hier.
            $behalten = array_flip(array_filter(array_map(static fn ($z) => $z['id'] ?? null, $zeilen)));
            foreach ($r->ingredients as $orig) {
                if ($orig->referenced_recipe_id !== null && ! isset($behalten[$orig->id])) {
                    $zeilen[] = app(RecipeReviseService::class)->bestandsZeile($orig);
                }
            }
            if ($zeilen !== []) {
                app(RecipeService::class)->syncIngredients($team, $id, $zeilen);
            }
        }

        // Texte: direkter Write mit Lineage ki — manuell gepflegte Felder bleiben unangetastet.
        $frisch = $r->fresh();
        $name = trim((string) ($werte['name'] ?? ''));
        if ($name === '') {
            // Der Critic hat bereits einen kontrollierten Zielwert geliefert. Falls der freie
            // Revise-Call das neue `name`-Feld auslässt, darf der erkannte harte Naming-Verstoß
            // nicht wirkungslos bleiben.
            $name = $this->findingVorschlag($befunde, 'name', nurHart: true);
        }
        if ($name !== '' && $name !== (string) $frisch->name) {
            $frisch->update(['name' => $name]);
        }
        if (is_string($werte['description'] ?? null) && trim($werte['description']) !== '' && $frisch->description_source !== 'manual') {
            $frisch->update([
                'description' => $werte['description'],
                'description_source' => 'ki',
                'description_ai_confidence' => $conf,
            ]);
        }
        if (is_string($werte['preparation'] ?? null) && trim($werte['preparation']) !== '' && $frisch->preparation_source !== 'manual') {
            $frisch->update(['preparation_source' => 'ki', 'preparation_ai_confidence' => $conf]);
            app(RecipeStepService::class)->ausMarkdown($frisch, $werte['preparation'], ueberschreiben: true);
        }

        $this->applyControlledSuggestions($team, $frisch->fresh(), $befunde);
    }

    /** Kritiker-Vorschlag für ein exakt benanntes Feld; leere/unsichere Vorschläge bleiben No-op. */
    private function findingVorschlag(array $befunde, string $feld, bool $nurHart = false): string
    {
        foreach ($befunde as $befund) {
            if (! is_array($befund) || (string) ($befund['feld'] ?? '') !== $feld) {
                continue;
            }
            if ($nurHart && (string) ($befund['schweregrad'] ?? '') !== 'hart') {
                continue;
            }
            $vorschlag = trim((string) ($befund['vorschlag'] ?? ''));
            if ($vorschlag !== '') {
                return $vorschlag;
            }
        }

        return '';
    }

    /**
     * Kontrollierte Facetten nicht als Freitext speichern: ein Critic-Vorschlag wird nur
     * übernommen, wenn er im sichtbaren DB-Vokabular exakt auflösbar ist.
     */
    private function applyControlledSuggestions(Team $team, $recipe, array $befunde): void
    {
        $kategorie = $this->findingVorschlag($befunde, 'kategorie', nurHart: true);
        if ($kategorie !== '') {
            if ($recipe->is_sales_recipe) {
                $gruppe = FoodAlchemistDishMainGroup::visibleToTeam($team)
                    ->where(fn ($q) => $q->whereRaw('LOWER(code) = ?', [mb_strtolower($kategorie)])
                        ->orWhereRaw('LOWER(label) = ?', [mb_strtolower($kategorie)]))
                    ->first();
                if ($gruppe !== null) {
                    $recipe->update(['dish_main_group_id' => (int) $gruppe->id]);
                }
            } else {
                $category = FoodAlchemistRecipeCategory::visibleToTeam($team)
                    ->whereRaw('LOWER(label) = ?', [mb_strtolower($kategorie)])->first();
                if ($category !== null) {
                    $recipe->update(['category_id' => (int) $category->id]);
                }
            }
        }

        if (! $recipe->is_sales_recipe) {
            return;
        }
        $diaet = $this->findingVorschlag($befunde, 'diaetform', nurHart: true);
        $hauptgruppeId = (int) ($recipe->fresh()->dish_main_group_id ?? 0);
        if ($diaet === '' || $hauptgruppeId <= 0) {
            return;
        }
        $klasse = FoodAlchemistDishClass::visibleToTeam($team)
            ->where('dish_main_group_id', $hauptgruppeId)
            ->whereRaw('LOWER(diet_form) = ?', [mb_strtolower($diaet)])
            ->first();
        if ($klasse !== null) {
            $recipe->update(['dish_class_id' => (int) $klasse->id]);
        }
    }
}
