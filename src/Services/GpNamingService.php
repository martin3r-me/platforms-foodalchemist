<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;

/**
 * M3-09: GL-12 — GP-Naming, Slugs & Anlage-Guard.
 *
 * - slugify(): KANONISCH, Umlaute einheitlich ae/oe/ue/ss (seit 19.10., Dominique: GP-Namen mit echten Umlauten; vorher ä→a/ö→o/ü→u/ß→s
 *   byte-identisch zur Rust-Variante commands.rs:2471). NICHT Str::slug(). Bestandskennungen aus Umlaut-Namen (Seed ü→u) müssen beim
 *   Daten-Umbau neu gebildet werden, sonst fängt der Anlage-Guard die Dublette nicht. Matcher-Slug: TokenEngine::normalizeSlug (gleiche Regel).
 * - renderGpName()/validateGpName(): gp_naming.rs Z. 31/88; §7.1 Verpackungswörter
 *   = Hard-Error (Wort-Boundary: „Dose" blockt, „Dosentomate" nicht), §9-Zustand
 *   mit Eingangs-Normalisierung tiefgekuehlt→TK (A2/A7: EIN Vokabular).
 * - anlageGuard(): gp_key-UNIQUE über approved/tentative + Jaccard ≥ 0.92 gegen
 *   bestehende Namen (create_gp Z. 2509); nur force=true legt trotzdem an (GT-12-10).
 */
class GpNamingService
{
    /**
     * §9 — kanonisches Zustand-Vokabular (A7: EIN Validator-Set; einzige Quelle im Code, Listen und Prompts lesen sie).
     * Regelwerk GP v3.6.0 (Dominique 19.10.): `gepoekelt`, `geraeuchert`, `luftgetrocknet` sind eigene Zustände
     * (vorher Verarbeitung). Vorrang bei mehreren Behandlungen (entschieden Dominique 19.10.): luftgetrocknet > geraeuchert >
     * gepoekelt; TK + Behandlung ⇒ die Behandlung ist der Zustand, „TK“ steht als Zusatz im Namen („Raeucherlachs: geraeuchert,
     * TK, geschnitten“). {@see zustandAusTreffern()}, {@see istTk()}.
     */
    public const ZUSTAND_VOCAB = ['frisch', 'TK', 'trocken', 'konserviert', 'gepökelt', 'geräuchert', 'luftgetrocknet'];   // Kanon mit echten Umlauten (Dominique 19.10.)

    /** §9 v3.6.0: die drei Behandlungs-Zustände (Fleisch/Wurst/Fisch) — für Matching und Lager wie „haltbar gemacht“. */
    public const BEHANDLUNG = ['gepökelt', 'geräuchert', 'luftgetrocknet'];

    /** §9 v3.6.0: Vorrang bei mehreren Behandlungen (entschieden Dominique 19.10.), stärkste zuerst. */
    public const BEHANDLUNG_VORRANG = ['luftgetrocknet', 'geräuchert', 'gepökelt'];

    /**
     * Ein Zustand aus mehreren Treffern im selben Text: einer ⇒ dieser; nur Behandlungen (+ ggf. TK) ⇒ die stärkste Behandlung
     * (TK ist dann Zusatz); alles andere (z. B. frisch + konserviert, TK + trocken) ist echt widersprüchlich ⇒ null.
     *
     * @param  list<string>  $treffer  kanonische Zustände
     */
    public static function zustandAusTreffern(array $treffer): ?string
    {
        $t = array_values(array_unique($treffer));
        if (count($t) === 1) {
            return $t[0];
        }
        $behandlung = array_values(array_intersect(self::BEHANDLUNG_VORRANG, $t));
        $rest = array_diff($t, self::BEHANDLUNG, ['TK']);

        return $behandlung !== [] && $rest === [] ? $behandlung[0] : null;
    }

    /**
     * TK-Ware? Der Zustand selbst oder — bei einer Behandlung als Zustand (v3.6.0 2b) — das Wort TK/tiefgekühlt im Namen bzw. in der
     * Verarbeitung. Überall, wo TK zählt (Lagerzone, TK-Vorzug, Teilfertig-Erkennung), statt nur `condition === 'TK'`.
     */
    public static function istTk(?string $condition, ?string $name = null, ?string $processing = null): bool
    {
        if (mb_strtoupper(trim((string) $condition)) === 'TK') {
            return true;
        }
        foreach ([$name, $processing] as $text) {
            if ($text !== null && preg_match('/(?<![\p{L}])(?:TK|tiefgek(?:ue|ü)hlt)(?![\p{L}])/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Schreibweisen, die auf einen kanonischen Zustand normalisiert werden (Eingang, kleingeschrieben). */
    private const ZUSTAND_ALIAS = [
        'tiefgekuehlt' => 'TK', 'tiefgekühlt' => 'TK', 'tk' => 'TK',
        'gepoekelt' => 'gepökelt', 'geraeuchert' => 'geräuchert', 'luftgetrocknet' => 'luftgetrocknet',   // ASCII-Eingabe → Kanon mit Umlaut
    ];

    /** Zustands-Filter (Lager, Inventur): gleicher Zustand — beim Filter TK auch TK als Zusatz im Namen (v3.6.0 2b). */
    public static function zustandPasst(?string $zustand, string $name, string $filter): bool
    {
        return mb_strtolower(trim((string) $zustand)) === mb_strtolower(trim($filter))
            || (mb_strtoupper(trim($filter)) === 'TK' && self::istTk($zustand, $name));
    }

    /**
     * §9-Vokabular in ASCII-Schreibweise → Kanon mit echten Umlauten (Dominique 19.10.). Nur ganze Wörter in Verarbeitung/Form/Pflichtangabe;
     * die Hauptzutat stellt der Daten-Umbau (Wortliste Klaus) um, nicht der Code.
     */
    private const UMLAUT_KANON = [
        'wuerfel' => 'Würfel', 'stueck' => 'Stück', 'mittelstueck' => 'Mittelstück', 'roeschen' => 'Röschen', 'pueree' => 'Püree',
        'geschaelt' => 'geschält', 'pueriert' => 'püriert', 'pulverfoermig' => 'pulverförmig', 'fluessig' => 'flüssig', 'gehaeutet' => 'gehäutet',
        'geraeuchert' => 'geräuchert', 'gepoekelt' => 'gepökelt', 'tiefgekuehlt' => 'tiefgekühlt', 'gekuehlt' => 'gekühlt', 'haelften' => 'Hälften',
        'gewuerfelt' => 'gewürfelt', 'gerieben' => 'gerieben', 'gefuellt' => 'gefüllt', 'suess' => 'süß',
    ];

    /** ASCII-Schreibweisen des Vokabulars in einem Text auf den Umlaut-Kanon setzen (Wortgrenzen, nur bekannte Wörter). */
    public static function umlautKanon(string $text): string
    {
        return (string) preg_replace_callback('/(?<![\p{L}])(\p{L}+)(?![\p{L}])/u', function ($m) {
            $k = self::UMLAUT_KANON[mb_strtolower($m[1])] ?? null;
            if ($k === null) {
                return $m[1];
            }
            // Großschreibung des Originals behalten, wenn der Kanon klein ist (Satzanfang) — sonst die Kanon-Schreibung (Nomen groß)
            return mb_strtolower(mb_substr($k, 0, 1)) === mb_substr($k, 0, 1) && mb_strtoupper(mb_substr($m[1], 0, 1)) === mb_substr($m[1], 0, 1)
                ? mb_strtoupper(mb_substr($k, 0, 1)) . mb_substr($k, 1) : $k;
        }, $text);
    }

    /** Das Vokabular als Text für Prompts, Tool-Beschreibungen und Fehlermeldungen (`frisch|TK|…`). */
    public static function zustandListe(string $trenner = '|'): string
    {
        return implode($trenner, self::ZUSTAND_VOCAB);
    }

    /** Regex-Alternation aller Zustandswörter samt Schreibweisen (ohne Gruppenklammern), für Wortgrenzen-Suche im Namen. */
    public static function zustandMuster(): string
    {
        return implode('|', array_map(fn (string $w) => preg_quote($w, '/'), array_unique([...self::ZUSTAND_VOCAB, ...array_keys(self::ZUSTAND_ALIAS)])));
    }

    /*
     * §7.1 Verpackungswörter und §10 Platzhalter-Marker („generisch") sind seit Spec 81 Regeln als Daten
     * (`gp.7.1.gebinde`, `gp.10.generik`, Einstellungen › Regeln). validateGpName() fragt ALLE aktiven Regeln mit
     * Ziel `gp.name` ab — neue Regeln greifen ohne Code-Änderung. Anlass §10 (2026-09-03): »Apfel (generisch)«
     * konkurrierte im Matcher punktgleich mit »Apfel Royal Gala«; Dominique: »generisch darf gar nicht benannt
     * werden« — darum ein harter Fehler beim Namen, nicht erst ein Befund im Critic.
     *
     * Das Zustands-Vokabular bleibt hier Code (ZUSTAND_VOCAB): der Validator erzwingt es, eine Regel als Daten könnte es nie ändern.
     */

    public function __construct(private TokenEngine $engine)
    {
    }

    /**
     * KANONISCH (gp_key/main_ingredient_slug). Seit 19.10. (Dominique: GP-Namen mit echten Umlauten) EINHEITLICH ü→ue, ö→oe, ä→ae, ß→ss —
     * vorher ü→u (Rust-Erbe), damit ergaben „Karottenpüree“ und „Karottenpueree“ verschiedene Kennungen und UNIQUE fing die Dublette nicht.
     * ASCII-Bestandskennungen bleiben unverändert.
     */
    public function slugify(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s);                     // Unicode-Buchstaben (é, è) bleiben
        $s = preg_replace('/_+/', '_', $s);

        return trim($s, '_');
    }

    /** Platzhalter, die KI oder Importe für „weiß ich nicht" liefern. Sie sind KEIN Wert (Regelwerk GP §6/§9, §12). */
    private const LEERWERTE = ['unbekannt', 'unknown', 'n/a', 'na', 'n.a.', 'k.a.', 'ka', 'keine angabe', 'nicht bekannt', 'nicht angegeben', 'none', 'null', '-', '–', '?'];

    /** Strukturfeld bereinigen: „unbekannt" und Verwandte werden zu leer, nie zu Name oder Kennung. */
    public function bereinigeFeld(mixed $wert): string
    {
        if (! is_string($wert) && ! is_numeric($wert)) {
            return '';
        }
        $w = trim((string) $wert);

        return in_array(mb_strtolower(trim($w, " \t()[].,;:")), self::LEERWERTE, true) || in_array(mb_strtolower($w), self::LEERWERTE, true) ? '' : $w;
    }

    /** Alle strukturierten Naming-Felder eines Eingabe-Arrays bereinigen (Name/Kennung bleiben frei von Platzhaltern). */
    public function bereinigeFelder(array $in): array
    {
        foreach (['hauptzutat', 'condition', 'processing', 'form', 'pflichtangabe', 'portion', 'sub_category'] as $feld) {
            if (array_key_exists($feld, $in)) {
                $in[$feld] = $this->bereinigeFeld($in[$feld]);
            }
        }

        return $in;
    }

    /** commands.rs:2496 — IMMER 3 Slots, auch leere (Tomatenpulver-Dubletten-Bug 2026-05-26). */
    public function buildGpKey(string $hauptzutatSlug, ?string $processing, ?string $form): string
    {
        return $hauptzutatSlug . '|' . $this->slugify($this->bereinigeFeld($processing)) . '|' . $this->slugify($this->bereinigeFeld($form));
    }

    /** A2/A7: Eingangs-Normalisierung — Langform → kanonisch TK; sonst unverändert. */
    public function normalisiereZustand(?string $condition): ?string
    {
        if ($condition === null || $this->bereinigeFeld($condition) === '') {
            return null;
        }
        $z = trim($condition);
        $klein = mb_strtolower($z);
        if (isset(self::ZUSTAND_ALIAS[$klein])) {
            return self::ZUSTAND_ALIAS[$klein];
        }
        foreach (self::ZUSTAND_VOCAB as $kanon) {
            if (mb_strtolower($kanon) === $klein) {
                return $kanon;   // Schreibweise vereinheitlichen (Frisch → frisch, Geräuchert → geräuchert)
            }
        }

        return $z;
    }

    /**
     * Spec 80 (Paket 11): Umkehrung von {@see renderGpName} — die strukturierten Felder aus einem Bestandsnamen
     * nach §6-Schema `<Hauptzutat>: <Zustand>, <Verarbeitung|Form>[, Portion …][, <Pflichtangabe>][ / (Bio)]`.
     * Deterministisch, ohne KI: Zustand nur aus dem §9-Vokabular; das erste Attribut danach wird Verarbeitung,
     * wenn es ein §2-Verarbeitungs-Suffix trägt (Dossier), sonst Form; der Rest Pflichtangabe. Was nicht passt,
     * bleibt leer — der Mensch korrigiert im Editor.
     *
     * @return array{hauptzutat: string, condition: string, processing: string, form: string, portion: string, pflichtangabe: string, bio: bool}
     */
    public function felderAusName(string $name): array
    {
        $out = ['hauptzutat' => '', 'condition' => '', 'processing' => '', 'form' => '', 'portion' => '', 'pflichtangabe' => '', 'bio' => false];
        $name = trim($name);
        if (preg_match('/\(\s*bio\s*\)/iu', $name) === 1) {
            $out['bio'] = true;
        }
        $name = trim((string) preg_replace('/\s*\/\s*\([^)]*\)/u', '', $name));   // „ / (Bio) / (Vegan)"
        $out['hauptzutat'] = trim((string) strstr($name . ':', ':', true));
        $rest = str_contains($name, ':') ? trim((string) substr($name, strpos($name, ':') + 1)) : '';
        // Dezimalkomma („62,5 g pro Stück", „3,5 % Fett") ist kein Trenner der Attribute
        $teile = array_values(array_filter(array_map(fn ($t) => trim(str_replace("\x01", ',', $t)), explode(',', (string) preg_replace('/(?<=\d),(?=\d)/', "\x01", $rest))), fn ($t) => $t !== ''));
        $suffixe = \Platform\FoodAlchemist\Support\GpKorrektur::verarbeitungsSuffixe();
        $pflicht = [];
        $tkZusatz = false;
        foreach ($teile as $teil) {
            $zustand = $this->normalisiereZustand($teil);
            if ($out['condition'] === '' && $zustand !== null && in_array($zustand, self::ZUSTAND_VOCAB, true)) {
                $out['condition'] = $zustand;
            } elseif ($zustand === 'TK' && in_array($out['condition'], self::BEHANDLUNG, true)) {
                $tkZusatz = true;   // v3.6.0 2b: „geraeuchert, TK, …“ — TK als Zusatz, steht vorn in der Verarbeitung
            } elseif (mb_strtolower($teil) === 'bio') {
                $out['bio'] = true;
            } elseif (preg_match('/^(?:portion\s+)?((?:ca\.?\s*)?\d+(?:[.,]\d+)?\s*(?:kg|g|ml|l))\s+pro\s+st(?:ue|ü)ck$/iu', $teil, $m) === 1) {
                $out['portion'] = $this->normalisierePortion($m[1]);        // „660 g pro Stück" (neu) und Bestand „660 g pro Stueck" / Altform „Portion 150 g pro Stueck" (beide Schreibweisen lesbar)
            } elseif (preg_match('/^portion\s+(.+?)\s+pro\s+st(?:ue|ü)ck$/iu', $teil, $m) === 1) {
                $out['portion'] = $m[1];
            } elseif ($out['processing'] === '' && $out['form'] === '' && $pflicht === []) {
                $norm = \Platform\FoodAlchemist\Support\RezeptTypVokabular::norm($teil);
                $istVerarbeitung = false;
                foreach ($suffixe as $s) {
                    if (($sn = \Platform\FoodAlchemist\Support\RezeptTypVokabular::norm($s)) !== '' && str_contains($norm, $sn)) {
                        $istVerarbeitung = true;
                        break;
                    }
                }
                $out[$istVerarbeitung ? 'processing' : 'form'] = $teil;
            } else {
                $pflicht[] = $teil;
            }
        }
        $out['pflichtangabe'] = implode(', ', $pflicht);
        if ($tkZusatz) {
            // renderGpName zeigt processing ODER form — TK muss in den gezeigten Teil, die Form bleibt in ihrer Spalte erhalten
            $mitte = $out['processing'] !== '' ? $out['processing'] : $out['form'];
            $out['processing'] = $mitte === '' ? 'TK' : 'TK, ' . $mitte;
        }

        return $out;
    }

    /** Warengruppe 16 (Non-Food)? Die WG steht in den strukturierten Feldern als `warengruppe` oder `commodity_group_code`. */
    private function istNonFood(array $in): bool
    {
        return (string) ($in['warengruppe'] ?? $in['commodity_group_code'] ?? '') === \Platform\FoodAlchemist\Support\NonFoodRegeln::WG_CODE;
    }

    /**
     * gp_naming.rs Z. 31 — §6-Schema (Ist-Slots 1:1, A1):
     * `<Hauptzutat>: <condition>, <processing|form>[, <x> g pro Stück][, <pflicht>][ / (Bio)]…`
     */
    public function renderGpName(array $in): string
    {
        $in = $this->bereinigeFelder($in);
        if ($this->istNonFood($in)) {
            // Non-Food (Warengruppe 16, Regelwerk §6.4): `Produkt: Eigenschaft, Größe` — kein Zustand, keine Portion, keine Diät-Suffixe.
            // Eigenschaft = processing (sonst form), Größe = pflichtangabe.
            $teile = array_values(array_filter([($in['processing'] ?? null) ?: ($in['form'] ?? null), $in['pflichtangabe'] ?? null], fn ($t) => trim((string) $t) !== ''));
            $produkt = trim($in['hauptzutat'] ?? '');

            return $teile === [] ? $produkt : $produkt.': '.implode(', ', $teile);
        }
        $parts = [];
        $condition = $this->normalisiereZustand($in['condition'] ?? null);
        if ($condition !== null) {
            $parts[] = $condition;
        }
        $mid = ($in['processing'] ?? null) ?: ($in['form'] ?? null);       // processing gewinnt (spezifischer)
        if ($mid) {
            $parts[] = self::umlautKanon((string) $mid);                  // „Wuerfel 10 mm" → „Würfel 10 mm" (Kanon mit Umlaut)
        }
        if (! empty($in['portion'])) {
            $parts[] = $this->normalisierePortion((string) $in['portion']) . ' pro Stück';        // „660 g pro Stück" (echter Umlaut im GP-Namen; kein „Portion", Leerzeichen vor der Einheit)
        }
        if (! empty($in['pflichtangabe'])) {
            $parts[] = self::umlautKanon((string) $in['pflichtangabe']);
        }

        $hauptzutat = trim($in['hauptzutat'] ?? '');
        $base = $parts === [] ? $hauptzutat : $hauptzutat . ': ' . implode(', ', $parts);

        $suffixes = [];
        foreach (['bio' => '(Bio)', 'vegan' => '(Vegan)', 'glutenfrei' => '(Glutenfrei)', 'laktosefrei' => '(Laktosefrei)'] as $flag => $suffix) {
            if (! empty($in[$flag])) {
                $suffixes[] = $suffix;                                       // Reihenfolge fix (A1)
            }
        }

        return $suffixes === [] ? $base : $base . ' / ' . implode(' / ', $suffixes);
    }

    /**
     * gp_naming.rs Z. 88 — Hard-Errors blocken Insert, Warnings sind informativ (I4).
     *
     * @return array{errors: string[], warnings: string[]}
     */
    public function validateGpName(string $name, array $in): array
    {
        $errors = [];
        $warnings = [];

        if (trim($name) === '') {
            $errors[] = 'Name ist leer.';
        }
        if ($this->istNonFood($in)) {
            // Non-Food: eigenes, schlankes Schema (§6.4). Die Lebensmittel-Namensregeln (Zustand, Pflichtangaben) gelten nicht;
            // Lebensmittel-Zustandswörter im Namen sind ein Hinweis (Anti-Pattern), kein Blocker.
            if (preg_match('/(?<![\p{L}])(' . self::zustandMuster() . ')(?![\p{L}])/u', $name) === 1) {
                $warnings[] = 'Non-Food braucht keinen Zustand (' . self::zustandListe('/') . ') — Schema: Produkt: Eigenschaft, Größe.';
            }

            return ['errors' => $errors, 'warnings' => $warnings];
        }
        $condition = $this->normalisiereZustand($in['condition'] ?? null);
        // Spec 81: alle aktiven Regeln für den GP-Namen. blockieren/korrigieren = Hard-Error, warnen = Hinweis.
        $motor = app(\Platform\FoodAlchemist\Services\Regeln\RegelMotor::class);
        $kontext = ['zustand' => $condition, 'warengruppe' => $in['warengruppe'] ?? $in['commodity_group_code'] ?? null, 'form' => $in['form'] ?? null,
            'hauptzutat' => trim((string) ($in['hauptzutat'] ?? strstr($name . ':', ':', true)))];
        foreach (\Platform\FoodAlchemist\Services\Regeln\RegelBuch::fuerZiel('gp.name') as $regel) {
            foreach ($motor->pruefe($regel, $name, $kontext) as $b) {
                $text = trim(($b['paragraph'] ?? '') . ': ' . $b['begruendung'], ': ');
                if ($b['schweregrad'] === 'hart') {
                    $errors[] = $text;
                } else {
                    $warnings[] = $text;
                }
            }
        }
        if ($condition !== null && ! in_array($condition, self::ZUSTAND_VOCAB, true)) {
            $errors[] = "§9: Zustand »{$condition}« ist nicht im Pflicht-Vokabular (" . implode('/', self::ZUSTAND_VOCAB) . ').';
        }
        if ($this->normalisiere($name) !== $this->normalisiere($this->renderGpName($in))) {
            $warnings[] = 'Drift: Name weicht vom Render aus den strukturierten Feldern ab (I4).';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /** Zahlen im Namen als sortierte Menge (Dezimalkomma normalisiert); Grundlage des Guards „gleiche Zahl = Dublette“. */
    private function zahlenTokens(string $name): array
    {
        preg_match_all('/\d+(?:[.,]\d+)?/u', $name, $m);
        $z = array_unique(array_map(fn ($x) => str_replace(',', '.', $x), $m[0]));
        sort($z);

        return array_values($z);
    }

    /**
     * Anlage-Guard (create_gp Z. 2509, GT-12-10): gp_key-UNIQUE über aktive GPs
     * (approved/tentative) + Jaccard ≥ 0.92 gegen bestehende Namen. Hard-Stop —
     * nur force=true legt trotzdem an.
     *
     * @return array{blockiert: bool, grund: ?string, vorhandenes_gp: ?FoodAlchemistGp}
     */
    public function anlageGuard(Team $team, string $gpKey, string $name, ?int $ignoriereGpId = null): array
    {
        $kollision = FoodAlchemistGp::visibleToTeam($team)
            ->whereIn('status', ['approved', 'tentative'])
            ->where('gp_key', $gpKey)
            ->when($ignoriereGpId !== null, fn ($q) => $q->where('id', '!=', $ignoriereGpId))
            ->first();
        if ($kollision !== null) {
            return ['blockiert' => true, 'grund' => 'gp_key', 'vorhandenes_gp' => $kollision];
        }

        $query = $this->engine->tokenize($name);
        if ($query !== []) {
            $beste = null;
            FoodAlchemistGp::visibleToTeam($team)
                ->whereIn('status', ['approved', 'tentative'])
                ->when($ignoriereGpId !== null, fn ($q) => $q->where('id', '!=', $ignoriereGpId))
                ->select('id', 'name', 'gp_key', 'status', 'team_id')
                ->orderBy('id')
                ->chunk(2000, function ($gps) use (&$beste, $query, $name) {
                    $zahlen = $this->zahlenTokens($name);
                    foreach ($gps as $gp) {
                        // Abweichende Zahl (mm, g, %, Kaliber) = andere Variante, keine Dublette (v3.6.0 §7.2/§7.3): der Tokenizer ignoriert Zahlen.
                        if ($this->zahlenTokens((string) $gp->name) !== $zahlen) {
                            continue;
                        }
                        $c = $this->engine->tokenize($gp->name);
                        if ($c === []) {
                            continue;
                        }
                        $schnitt = count(array_intersect($query, $c));
                        $jaccard = $schnitt / count(array_unique([...$query, ...$c]));
                        if ($jaccard >= 0.92 && ($beste === null || $jaccard > $beste['j'])) {
                            $beste = ['gp' => $gp, 'j' => $jaccard];
                        }
                    }
                });
            if ($beste !== null) {
                return ['blockiert' => true, 'grund' => 'jaccard', 'vorhandenes_gp' => $beste['gp']];
            }
        }

        return ['blockiert' => false, 'grund' => null, 'vorhandenes_gp' => null];
    }

    /**
     * GP-Anlage (Render-First, I4): rendert + validiert + Guard, dann Insert.
     * Status neuer GPs = tentative (Kuration hebt auf approved).
     *
     * @throws \RuntimeException bei Hard-Error oder Guard-Block ohne force
     */
    public function createGp(Team $team, array $in, bool $force = false): FoodAlchemistGp
    {
        $in = $this->bereinigeFelder($in);
        $name = trim($in['name'] ?? '') !== '' ? trim($in['name']) : $this->renderGpName($in);
        if ($this->enthaeltPlatzhalter($name)) {
            throw new \RuntimeException('Der Name enthält „unbekannt“. Unbekannte Eigenschaften bleiben leer und stehen nie im Namen (Regelwerk GP §6, §12).');
        }
        $pruefung = $this->validateGpName($name, $in);
        if ($pruefung['errors'] !== []) {
            throw new \RuntimeException(implode(' ', $pruefung['errors']));
        }

        $hauptzutatSlug = $this->slugify($in['hauptzutat'] ?? '');
        if ($hauptzutatSlug === '') {
            throw new \RuntimeException('Hauptzutat ist Pflicht (D-3: main_ingredient_slug ab Editor).');
        }
        $gpKey = $this->buildGpKey($hauptzutatSlug, $in['processing'] ?? null, $in['form'] ?? null);

        $guard = $this->anlageGuard($team, $gpKey, $name);
        if ($guard['blockiert']) {
            if (! $force) {
                throw new \RuntimeException(
                    "HARD_STOP_EXISTING_GP: »{$guard['vorhandenes_gp']->name}« existiert bereits"
                    . " ({$guard['grund']}) — verwenden oder bewusst trotzdem anlegen (force)."
                );
            }
            // force: DB-UNIQUE (team_id, gp_key) ist absolut — bewusste Duplikate bekommen
            // einen ~n-Suffix (klar als Force-Anlage erkennbar, Dubletten-Guard bleibt scharf)
            $n = 2;
            while (FoodAlchemistGp::where('team_id', $team->id)->where('gp_key', "{$gpKey}~{$n}")->exists()) {
                $n++;
            }
            $gpKey = "{$gpKey}~{$n}";
        }

        return FoodAlchemistGp::create([
            'team_id' => $team->id,                                          // D1: Kind-eigene GPs möglich
            'gp_key' => $gpKey,
            'name' => $name,
            'main_ingredient_slug' => $hauptzutatSlug,
            'status' => 'tentative',
            'condition' => $this->normalisiereZustand($in['condition'] ?? null),
            'commodity_group_code' => ($in['commodity_group_code'] ?? '') ?: null,
            'sub_category' => ($in['sub_category'] ?? '') ?: null,
            'is_derivat' => (bool) ($in['is_derivat'] ?? false),
            'derivat_von_gp_id' => $in['derivat_von_gp_id'] ?? null,
            'requires_la' => ! (bool) ($in['is_derivat'] ?? false),          // §11.2: Derivat braucht keinen LA
            'first_seen_at' => now(),
        ]);
    }

    /** GP-Edit über die strukturierten Felder — gleiche Validierung, gp_key wird NICHT umgeschrieben (Seed-Stabilität). */
    public function updateGp(Team $team, FoodAlchemistGp $gp, array $in): FoodAlchemistGp
    {
        $in = $this->bereinigeFelder($in);
        // Ein Non-Food-GP (WG 16) wird auch dann nach dem Non-Food-Schema geprüft, wenn die Eingabe die Warengruppe nicht mitbringt (MCP gps.PUT):
        // sonst träfen die Lebensmittel-Namensregeln („Glas“ ist ein Verpackungswort) ein Geschirr, das Glas als Material führt.
        if ($gp->istNonFood() && ! isset($in['warengruppe']) && ! isset($in['commodity_group_code'])) {
            $in['commodity_group_code'] = (string) $gp->commodity_group_code;
        }
        $name = trim($in['name'] ?? '') !== '' ? trim($in['name']) : $this->renderGpName($in);
        $pruefung = $this->validateGpName($name, $in);
        if ($pruefung['errors'] !== []) {
            throw new \RuntimeException(implode(' ', $pruefung['errors']));
        }

        $warDerivat = (bool) $gp->is_derivat;
        $istDerivat = (bool) ($in['is_derivat'] ?? $gp->is_derivat);

        $gp->update([
            'name' => $name,
            'condition' => $this->normalisiereZustand($in['condition'] ?? null) ?? $gp->condition,
            // Spec 80 (Paket 11): Verarbeitung/Form/Hauptzutat auch beim BEARBEITEN pflegen. Vorher schrieb nur die
            // Anlage sie — im Bestand stand „Würfel 5 mm" deshalb nur im Namen (demo: Verarbeitung bei 210 von 6.950).
            // Nur wenn das Feld mitkommt (MCP-/Teil-Schreiber ohne Feld lassen den Wert stehen).
            'processing' => array_key_exists('processing', $in) ? (trim((string) $in['processing']) ?: null) : $gp->processing,
            'form' => array_key_exists('form', $in) ? (trim((string) $in['form']) ?: null) : $gp->form,
            'main_ingredient_display' => trim((string) ($in['hauptzutat'] ?? '')) ?: $gp->main_ingredient_display,
            'commodity_group_code' => ($in['commodity_group_code'] ?? '') ?: $gp->commodity_group_code,
            'sub_category' => array_key_exists('sub_category', $in) ? (($in['sub_category'] ?? '') ?: null) : $gp->sub_category,
            'is_derivat' => $istDerivat,
            'derivat_von_gp_id' => array_key_exists('derivat_von_gp_id', $in) ? $in['derivat_von_gp_id'] : $gp->derivat_von_gp_id,
            // §11.2 wie bei der Anlage: Derivat braucht keinen LA. Nur beim WECHSEL umschalten — sonst blieb ein
            // nachträglich zum Derivat gemachter GP auf „Preis fehlt“; ein bewusst gesetzter Platzhalter
            // (requires_la=0 ohne Derivat) bleibt bei normalen Edits unangetastet.
            'requires_la' => $istDerivat !== $warDerivat ? ! $istDerivat : $gp->requires_la,
        ]);

        return $gp->refresh();
    }

    /**
     * Zustand hart aus der Haltbarkeit je Lagerart: nur EINE Lagerart gesetzt und eindeutig (tiefgekühlt → TK, trocken → trocken).
     * Gekühlt allein sagt nichts (frisch ODER konserviert), mehrere Lagerarten widersprechen sich: dann entscheidet die KI.
     */
    public function zustandAusHaltbarkeit(?int $gekuehlt, ?int $tiefgekuehlt, ?int $trocken): ?string
    {
        $gesetzt = array_filter(['kuehl' => $gekuehlt, 'tk' => $tiefgekuehlt, 'trocken' => $trocken], fn ($t) => $t !== null && $t > 0);
        if (count($gesetzt) !== 1) {
            return null;
        }

        return match (array_key_first($gesetzt)) {
            'tk' => 'TK',
            'trocken' => 'trocken',
            default => null,
        };
    }

    /**
     * Halluzinations-Guard für die Hauptzutat (Regelwerk GP §6: Kernwort bzw. Gattungsbegriff, höchstens 3 Inhaltswörter): nur Wörter
     * behalten, die in den Quelltexten (Bezeichnung, Zutatenliste, Marketing-/regulierter Name) oder im GP-Vokabular vorkommen.
     * Verbindungswörter („mit", „in" …) bleiben nur zwischen zwei behaltenen Wörtern stehen („Schokoladenmousse mit Cashew-Creme").
     * Formen („Riegel", „Würfel") sind kein Teil der Hauptzutat; sie stehen im Feld Form und dürfen dort ohne Beleg im Text stehen.
     *
     * @param  list<string>  $quelltexte
     * @return array{wert: string, entfernt: list<string>}
     */
    public function bereinigeHauptzutat(string $hauptzutat, array $quelltexte): array
    {
        $norm = fn (string $x) => (string) preg_replace('/[^a-z0-9]+/', '', strtr(mb_strtolower($x), ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e', 'è' => 'e']));
        $blob = $norm(implode(' ', $quelltexte));
        $verbinder = ['mit', 'in', 'an', 'auf', 'von', 'und', 'nach', 'aus', 'ohne', 'im', 'vom', 'zum', 'au', 'a', 'la'];
        $woerter = preg_split('/\s+/u', trim($this->bereinigeFeld($hauptzutat)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $entfernt = [];
        $behalten = [];     // Liste aus [wort, istVerbinder]
        $inhalt = 0;
        foreach ($woerter as $i => $wort) {
            if ($i > 0 && in_array(mb_strtolower($wort), $verbinder, true)) {
                $behalten[] = [$wort, true];

                continue;
            }
            $n = $norm($wort);
            $belegt = $n !== '' && ($blob === '' || str_contains($blob, $n) || (mb_strlen($n) >= 6 && str_contains($blob, mb_substr($n, 0, mb_strlen($n) - 2))))
                || ($n !== '' && \Platform\FoodAlchemist\Models\FoodAlchemistGp::where('main_ingredient_slug', $this->slugify($wort))->exists());
            if ($belegt && $inhalt < 3) {
                $behalten[] = [$wort, false];
                $inhalt++;
            } else {
                $entfernt[] = $wort;
            }
        }
        // Verbinder ohne Inhaltswort davor oder danach fallen weg (nie „mit" am Rand)
        while ($behalten !== [] && end($behalten)[1]) {
            $entfernt[] = array_pop($behalten)[0];
        }
        while ($behalten !== [] && $behalten[0][1]) {
            $entfernt[] = array_shift($behalten)[0];
        }

        return ['wert' => implode(' ', array_map(fn ($x) => $x[0], $behalten)), 'entfernt' => $entfernt];
    }

    /**
     * Zusatz im Namen (Bio, Vegan, Glutenfrei, Laktosefrei) — STRENG: nur, wenn eine Quelle es AUSDRÜCKLICH sagt (LA-Merkmal, Bio-Kontrollnummer,
     * Text in Bezeichnung/Marketing-/regulierter Name/Zutatenangabe). NIE aus fehlenden oder schwachen Allergenen ableiten („Milch Spuren" ≠ laktosefrei).
     * Widerspruch (vegan, aber Milch/Ei/Fisch … „enthalten"; glutenfrei, aber Gluten „enthalten") → nicht setzen, Hinweis.
     * Der Aufrufer schaltet eine Handeingabe nie ab, AUSSER bei genau diesem Widerspruch zu „enthalten" (`widerspruch`).
     *
     * @param  array{bezeichnung?: ?string, marketing_name?: ?string, regulierter_name?: ?string, zutaten?: ?string, is_organic?: mixed, is_vegan?: mixed, bio_kontrollnummer?: ?string, allergene?: array<string,?string>}  $q
     * @return array{flags: array{bio: bool, vegan: bool, glutenfrei: bool, laktosefrei: bool}, widerspruch: list<string>, hinweise: list<string>}
     */
    public function zusatzAusQuellen(array $q): array
    {
        $text = mb_strtolower(implode(' | ', array_filter([$q['bezeichnung'] ?? null, $q['marketing_name'] ?? null, $q['regulierter_name'] ?? null, $q['zutaten'] ?? null])));
        $sagt = fn (string $muster) => (bool) preg_match($muster, $text);
        $aller = $q['allergene'] ?? [];
        $enthalten = fn (string ...$keys) => array_values(array_filter($keys, fn ($k) => ($aller[$k] ?? null) === 'enthalten'));

        $bio = ! empty($q['is_organic']) || trim((string) ($q['bio_kontrollnummer'] ?? '')) !== '' || $sagt('/(?<![a-zäöü])(bio|öko)(?![a-zäöü])|de-öko-\d+/u');
        $vegan = (! empty($q['is_vegan']) || $sagt('/(?<!nicht )(?<!un)(?<![a-zäöü])vegan(?![a-zäöü])/u')) && ! $sagt('/nicht vegan|unvegan/u');
        $gluten = $sagt('/glutenfrei|ohne gluten/u');
        $laktose = $sagt('/laktosefrei|ohne laktose/u');

        $hinweise = [];
        $widerspruch = [];
        if ($vegan && ($w = $enthalten('milk', 'eggs', 'fish', 'crustaceans', 'molluscs')) !== []) {
            $vegan = false;
            $widerspruch[] = 'vegan';
            $hinweise[] = 'Quelle nennt „vegan“, aber Allergene enthalten: '.implode(', ', array_map(fn ($k) => \Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE[$k] ?? $k, $w)).' — Zusatz „Vegan“ nicht gesetzt.';
        }
        if ($gluten && $enthalten('gluten') !== []) {
            $gluten = false;
            $widerspruch[] = 'glutenfrei';
            $hinweise[] = 'Quelle nennt „glutenfrei“, aber Gluten ist als enthalten gemeldet — Zusatz „Glutenfrei“ nicht gesetzt.';
        }

        return ['flags' => ['bio' => $bio, 'vegan' => $vegan, 'glutenfrei' => $gluten, 'laktosefrei' => $laktose], 'widerspruch' => $widerspruch, 'hinweise' => $hinweise];
    }

    /**
     * Form/Verarbeitung, die schon in der Hauptzutat steckt (Form „Mousse" bei „Schokoladenmousse"), ist Dublette: leer.
     * Nur wenn die Form das KOPFWORT einer Hauptzutat-Vokabel ist, also dem Wort entspricht oder (Kompositum, Form ≥ 4 Zeichen) am Wortende steht.
     * Ein Teilstring irgendwo trifft nicht: „Ei" + „Scheiben", „Reis" + „Korn" bleiben.
     */
    public function dedupliziereMitHauptzutat(string $hauptzutat, string $feld): string
    {
        $n = fn (string $x) => (string) preg_replace('/[^a-z0-9]+/', '', strtr(mb_strtolower($x), ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']));
        $f = $n($feld);
        if ($f === '') {
            return $feld;
        }
        foreach (preg_split('/\s+/u', trim($hauptzutat), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $wort) {
            $w = $n($wort);
            if ($w === $f || (mb_strlen($f) >= 4 && $w !== '' && str_ends_with($w, $f))) {
                return '';
            }
        }

        return $feld;
    }

    /**
     * Portionsgrammatur im Bestandsformat: „660g", „660 g" und „0,66 kg" werden zu „660 g". Der Bestand schreibt Gramm auch ab 1000
     * („2000 g", 19 Namen; nur 1 Name mit „3 kg") und Milliliter („80 ml"), darum kg → g und l → ml. Komma als Dezimaltrenner („62,5 g"),
     * „ca." bleibt erhalten. Nicht Lesbares bleibt unverändert (getrimmt).
     */
    public function normalisierePortion(string $portion): string
    {
        $p = trim($portion);
        if (! preg_match('/^(ca\.?\s*)?(\d+(?:[.,]\d+)?)\s*(kg|g|ml|l)?$/iu', $p, $m)) {
            return $p;
        }
        $zahl = (float) str_replace(',', '.', $m[2]);
        $einheit = mb_strtolower($m[3] ?? '');
        if ($einheit === 'kg') {
            $zahl *= 1000;
            $einheit = 'g';
        } elseif ($einheit === 'l') {
            $zahl *= 1000;
            $einheit = 'ml';
        }
        $text = rtrim(rtrim(number_format($zahl, 3, ',', ''), '0'), ',');

        return trim(($m[1] !== '' ? 'ca. ' : '').$text.($einheit !== '' ? ' '.$einheit : ''));
    }

    /** Steht ein Platzhalter („unbekannt") als Attribut im Namen? Nur ganze Wörter im Attributteil nach dem Doppelpunkt. */
    public function enthaeltPlatzhalter(string $name): bool
    {
        $teil = str_contains($name, ':') ? substr($name, (int) strpos($name, ':') + 1) : $name;

        return (bool) preg_match('/(^|[\s,\/(])(unbekannt|unknown)([\s,\/)]|$)/iu', $teil);
    }

    private function normalisiere(string $s): string
    {
        // Beide Schreibweisen gelten als gleich (Dubletten-/Drift-Vergleich): Umlaute auf ae/oe/ue/ss abbilden, wie Matcher und TokenEngine.
        return strtr(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s))), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }
}
