<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Models\FoodAlchemistTerminologyAlias;
use Platform\FoodAlchemist\Models\FoodAlchemistTerminologyAntiMarker;
use Throwable;

/**
 * #507 Weg-2 (deterministische Terminologie-Schicht): der ehrliche Fix, nachdem
 * die E5-Eichung (2026-07-19) zeigte, dass OpenAI-Embeddings kurze Lebensmittel-
 * Synonyme NICHT sauber von Anti-Markern trennen (kein Floor mit gutem Recall +
 * 0 Leaks). Die Fehlerfälle sind grösstenteils gar nicht semantisch, sondern:
 *   (a) Dialekt-/Übersetzungs-Synonyme  → Wörterbuch-Lookup  (S1 Alias)
 *   (b) lexikalische Verwechslungs-Fallen → harte Negativliste (S2 Anti-Marker)
 * Embeddings bleiben als hoher-Floor-Sicherheitsnetz + für Freitext-Suche; die
 * PRÄZISE Zutat→GP-Auflösung läuft deterministisch über diese Schicht.
 *
 * PROVENIENZ (keine Erfindungen): Die Alias-Gruppen spiegeln die Positiv-Paare
 * des Golden-Sets (tests/Fixtures/SemanticGoldenSet.php, das seinerseits aus dem
 * Vault-Wissen 07.01/Cross_Cutting/Substitutionen.md stammt) + etablierte
 * DE/AT/EN-Küchenterminologie. Die Anti-Marker spiegeln die Negativ-Fälle des
 * Golden-Sets + Vault 07.01/Cross_Cutting/Anti_Marker.md (Brie/Bries, Triple Sec/
 * Triple Chocolate, Paprika-Schote/Pulver, Spirituose≠Frucht, Soja≠Hollandaise).
 *
 * Erweiterbar: kuratierte PHP-Konstanten (schnell, testbar, deploy-bar). Eine
 * spätere Promotion auf team-scoped DB-Tabelle + MCP-Pflege ist ein sauberer
 * Folge-Slice, kein Muss für den Go-Live.
 */
class TerminologyService
{
    /** Request-Cache der zusammengeführten (Konstanten ∪ DB) Regeln. */
    private ?array $aliasGroupsCache = null;

    private ?array $antiMarkerCache = null;

    /**
     * E7-b: Alias-Gruppen = Konstanten-Baseline ∪ runtime-gepflegte DB-Zeilen
     * (globaler Master, additiv). Graceful: fehlt die Tabelle / kein DB (Sandbox,
     * frühe Boot-Phase) → nur die Konstanten. Terminologie ist ein GLOBALER Master
     * (kein Team-Scoping) — sprachliche Zuordnung, keine Geschäftsdaten.
     *
     * @return list<list<string>>
     */
    public function aliasGroups(): array
    {
        if ($this->aliasGroupsCache !== null) {
            return $this->aliasGroupsCache;
        }
        $groups = self::ALIAS_GROUPS;
        try {
            foreach (FoodAlchemistTerminologyAlias::query()->whereNull('deleted_at')->get(['members']) as $row) {
                $members = is_array($row->members)
                    ? array_values(array_filter(array_map(fn ($m) => $this->norm((string) $m), $row->members), fn ($m) => $m !== ''))
                    : [];
                if (count($members) >= 2) {
                    $groups[] = $members;
                }
            }
        } catch (Throwable) {
            // Tabelle nicht migriert / kein DB → Konstanten-Baseline genügt.
        }

        return $this->aliasGroupsCache = $groups;
    }

    /**
     * E7-c: eine Alias-Gruppe im globalen Master anlegen (Lernschleife-Senke — genutzt
     * von MCP terminology.POST UND der ReviewQueue-UI, EINE Regel-Stelle). Wirkt sofort
     * beim nächsten Matching. Wirft RuntimeException bei <2 Phrasen.
     *
     * @param  list<string>  $members
     */
    public function createAlias(array $members, ?string $note = null, string $via = 'manual'): FoodAlchemistTerminologyAlias
    {
        $clean = array_values(array_unique(array_filter(
            array_map(fn ($m) => mb_strtolower(trim((string) $m)), $members),
            fn ($m) => $m !== '',
        )));
        if (count($clean) < 2) {
            throw new \RuntimeException('Eine Alias-Gruppe braucht ≥2 verschiedene Phrasen.');
        }
        $row = FoodAlchemistTerminologyAlias::create([
            'team_id' => null, 'members' => $clean, 'note' => $note ?: null,
            'source' => $via, 'created_via' => $via,
        ]);
        $this->aliasGroupsCache = null;

        return $row;
    }

    /**
     * E7-c: einen Anti-Marker im globalen Master anlegen (Lernschleife-Senke). Wirft
     * RuntimeException, wenn trigger oder forbid leer ist.
     */
    public function createAntiMarker(string $trigger, string $forbid, ?string $unless = null, ?string $note = null, string $via = 'manual'): FoodAlchemistTerminologyAntiMarker
    {
        $trigger = mb_strtolower(trim($trigger));
        $forbid = mb_strtolower(trim($forbid));
        $unless = ($u = mb_strtolower(trim((string) $unless))) !== '' ? $u : null;
        if ($trigger === '' || $forbid === '') {
            throw new \RuntimeException('Ein Anti-Marker braucht trigger UND forbid.');
        }
        $row = FoodAlchemistTerminologyAntiMarker::create([
            'team_id' => null, 'trigger_token' => $trigger, 'forbid_token' => $forbid,
            'unless_token' => $unless, 'note' => $note ?: null, 'source' => $via, 'created_via' => $via,
        ]);
        $this->antiMarkerCache = null;

        return $row;
    }

    /**
     * E7-b: Anti-Marker = Konstanten-Baseline ∪ DB-Zeilen (additiv, global). Graceful.
     *
     * @return list<array{trigger:string, forbid:string, unless?:string}>
     */
    public function antiMarkerRules(): array
    {
        if ($this->antiMarkerCache !== null) {
            return $this->antiMarkerCache;
        }
        $rules = self::ANTI_MARKERS;
        try {
            foreach (FoodAlchemistTerminologyAntiMarker::query()->whereNull('deleted_at')->get(['trigger_token', 'forbid_token', 'unless_token']) as $r) {
                $trigger = $this->norm((string) $r->trigger_token);
                $forbid = $this->norm((string) $r->forbid_token);
                if ($trigger === '' || $forbid === '') {
                    continue;
                }
                $rule = ['trigger' => $trigger, 'forbid' => $forbid];
                $unless = $this->norm((string) ($r->unless_token ?? ''));
                if ($unless !== '') {
                    $rule['unless'] = $unless;
                }
                $rules[] = $rule;
            }
        } catch (Throwable) {
            // Tabelle nicht migriert / kein DB → Konstanten-Baseline genügt.
        }

        return $this->antiMarkerCache = $rules;
    }

    /**
     * Alias-Gruppen: Sätze bedeutungsgleicher Begriffe. Taucht EIN Glied in der
     * Query auf, werden die Tokens der ANDEREN Glieder additiv in die Query
     * aufgenommen (Prefilter + Scoring). Rein additiv — Standardnamen ohne Alias
     * bleiben unberührt.
     *
     * @var list<list<string>>
     */
    private const ALIAS_GROUPS = [
        // ── DE-Synonyme ──────────────────────────────────────────────────────
        ['möhre', 'karotte', 'mohrrübe', 'gelbe rübe'],
        ['rahm', 'sahne', 'obers'],                       // obers = AT
        ['sauerrahm', 'saure sahne'],
        ['blumenkohl', 'karfiol'],                        // karfiol = AT
        ['rote bete', 'rote rübe', 'rande'],
        ['kohlrübe', 'steckrübe'],
        ['speisequark', 'quark', 'topfen'],               // topfen = AT
        ['schmand', 'saure sahne'],
        // ── AT/regional → DE ─────────────────────────────────────────────────
        ['erdapfel', 'kartoffel'],
        ['fisolen', 'grüne bohnen', 'gartenbohne'],
        ['paradeiser', 'tomate'],
        ['marille', 'aprikose'],
        ['ribisel', 'johannisbeere'],
        ['kukuruz', 'mais'],
        ['vogerlsalat', 'feldsalat'],
        ['kren', 'meerrettich'],
        // ── EN → DE (Übersetzung) ────────────────────────────────────────────
        ['beef', 'rindfleisch', 'rind'],
        ['pork', 'schweinefleisch', 'schwein'],
        ['chicken', 'hähnchen', 'huhn'],
        ['chicken breast', 'hähnchenbrust'],
        ['salmon', 'lachs'],
        ['prawns', 'prawn', 'garnele', 'garnelen'],
        ['shrimp', 'shrimps', 'garnele'],
        ['courgette', 'zucchini'],
        ['coriander', 'koriander', 'cilantro'],
        ['aubergine', 'eggplant', 'melanzani'],           // melanzani = AT
        ['cauliflower', 'blumenkohl'],
        ['beetroot', 'rote bete'],
        // ── Hyperonym → gängigster Vertreter (bewusst, Golden-Set) ───────────
        ['champignon', 'pilz'],
    ];

    /**
     * Harte Anti-Marker: gerichtete Regeln „taucht Trigger-Token in der Query
     * auf, unterdrücke Kandidaten, deren Name das Forbid-Token trägt (es sei
     * denn, der Name trägt zusätzlich das Guard-Token — dann ist es der legitime
     * Treffer selbst)". Alles lowercased Token-/Substring-Prüfung.
     *
     * @var list<array{trigger:string, forbid:string, unless?:string}>
     */
    private const ANTI_MARKERS = [
        // Brie (Molkerei-Weichkäse) ↔ Bries (Kalbsthymus, Innerei) — Anti_Marker §2
        ['trigger' => 'brie',       'forbid' => 'bries'],
        ['trigger' => 'bries',      'forbid' => 'brie',        'unless' => 'bries'],
        ['trigger' => 'kalbsbries', 'forbid' => 'brie',        'unless' => 'bries'],
        // Triple Sec (Likör) ↔ Triple Chocolate / Cookie (Backware) — Anti_Marker §1
        ['trigger' => 'sec',        'forbid' => 'chocolate'],
        ['trigger' => 'sec',        'forbid' => 'cookie'],
        ['trigger' => 'sec',        'forbid' => 'schoko'],
        ['trigger' => 'chocolate',  'forbid' => 'sec'],
        ['trigger' => 'cookie',     'forbid' => 'sec'],
        // Spirituose ≠ Frucht — Anti_Marker Obst
        ['trigger' => 'cointreau',  'forbid' => 'orange',      'unless' => 'cointreau'],
        ['trigger' => 'limoncello', 'forbid' => 'zitrone',     'unless' => 'limoncello'],
        ['trigger' => 'limoncello', 'forbid' => 'citrus',      'unless' => 'limoncello'],
        // Paprika-Schote (frisch) ≠ Paprikapulver (Gewürz) — Anti_Marker Gemüse
        ['trigger' => 'paprikapulver', 'forbid' => 'frisch'],
        ['trigger' => 'pulver',        'forbid' => 'frisch',   'unless' => 'pulver'],
        // Sojasauce (Asia-Würze) ≠ Sauce Hollandaise (Butter-Sauce) — Anti_Marker Würzen
        ['trigger' => 'sojasauce',  'forbid' => 'hollandaise'],
        ['trigger' => 'soja',       'forbid' => 'hollandaise'],
    ];

    /**
     * Alias-PHRASEN (nicht Einzeltokens) der ausgelösten Gruppen — die anderen,
     * nicht schon in der Query stehenden Glieder. Der Matcher scort jeden Kandidaten
     * gegen die ORIGINAL-Query UND jede Alias-Phrase einzeln und nimmt das Maximum;
     * dadurch bekommt der Alias-Treffer VOLLEN Score (statt im gemeinsamen Token-Bag
     * verwässert zu werden und aus den Top-K zu fallen). Multi-Wort-Glieder ("gelbe
     * rübe") bleiben als Phrase erhalten.
     *
     * @return list<string>
     */
    public function aliasPhrasesFor(string $ingredientName): array
    {
        $hay = ' ' . $this->norm($ingredientName) . ' ';
        $phrases = [];
        foreach ($this->aliasGroups() as $group) {
            $hit = false;
            foreach ($group as $member) {
                // Token-Grenzen (Space-umschlossen) — verhindert Falsch-Trigger wie
                // „Tamarinde" ⊃ „rind". Compound-Fälle (Rinderhackfleisch) sind S3.
                if (str_contains($hay, ' ' . $this->norm($member) . ' ')) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                continue;
            }
            foreach ($group as $member) {
                $m = $this->norm($member);
                if ($m !== '' && ! str_contains($hay, ' ' . $m . ' ')) {
                    $phrases[$m] = true;
                }
            }
        }

        return array_keys($phrases);
    }

    /**
     * Compound-Köpfe (§1-Syntax-„Head:"-Wörter + gängige DE-Grundworte). Endet ein
     * Query-Token auf einen dieser Köpfe, wird es in [Modifier, Kopf] gesplittet.
     * In norm-Form (lowercase, Umlaute erhalten).
     *
     * @var list<string>
     */
    private const COMPOUND_HEADS = [
        'püree', 'jus', 'sugo', 'sauce', 'soße', 'fond', 'coulis', 'mus', 'brühe',
        'suppe', 'wurzel', 'fleisch', 'öl', 'essig', 'butter', 'creme', 'kompott',
    ];

    /**
     * S3 Decompounding: zerlegt ein Compound-Query-Token in [Modifier, Kopf], damit
     * es die §1-Syntax-GPs trifft („Kürbispüree" → „kürbis püree" ⇒ GP „Püree:
     * Kürbis"). Fugen-Elemente (-s/-n/-en) werden als zusätzliche Modifier-Varianten
     * erzeugt (Kalbsjus→„kalb jus"); ein falscher Split matcht schlicht nichts (der
     * Matcher nimmt das Maximum über alle Varianten). Ergänzt {@see aliasPhrasesFor}.
     *
     * @return list<string>
     */
    public function decompoundPhrasesFor(string $ingredientName): array
    {
        $phrases = [];
        foreach (preg_split('/\s+/', $this->norm($ingredientName)) ?: [] as $tok) {
            if ($tok === '') {
                continue;
            }
            foreach (self::COMPOUND_HEADS as $head) {
                if ($tok === $head || ! str_ends_with($tok, $head)) {
                    continue;
                }
                $modLen = mb_strlen($tok) - mb_strlen($head);
                if ($modLen < 3) {
                    continue;
                }
                $mod = mb_substr($tok, 0, $modLen);
                // Modifier + Fugen-bereinigte Varianten (nur wenn Rest ≥3 bleibt).
                foreach ([$mod, $this->stripSuffix($mod, 's'), $this->stripSuffix($mod, 'n'), $this->stripSuffix($mod, 'en')] as $m) {
                    if ($m !== '' && mb_strlen($m) >= 3) {
                        $phrases[$m . ' ' . $head] = true;
                    }
                }
                break;   // erster passender Kopf gewinnt
            }
        }

        return array_keys($phrases);
    }

    private function stripSuffix(string $s, string $suf): string
    {
        if (str_ends_with($s, $suf) && mb_strlen($s) - mb_strlen($suf) >= 3) {
            return mb_substr($s, 0, mb_strlen($s) - mb_strlen($suf));
        }

        return '';
    }

    /**
     * S2: darf dieser Kandidat für diese Query gar nicht erst in die Shortlist?
     * true = bekannte Verwechslungs-Falle → unterdrücken (unabhängig von Score).
     */
    public function isAntiMarker(string $queryName, string $candidateName): bool
    {
        $q = $this->norm($queryName);
        $c = $this->norm($candidateName);
        foreach ($this->antiMarkerRules() as $rule) {
            // Compound-bewusster Treffer (Spec 16·S3): ganzes Token ODER Präfix/Suffix
            // eines längeren Tokens (Kompositum-Kopf/Modifier) — „Kalbsbries" ⊃ „bries",
            // „Briekäse" ⊃ „brie". NIE Interieur → „Tamarinde" ⊅ „rind" bleibt geschützt.
            if ($this->tokenHit($q, $rule['trigger'])
                && $this->tokenHit($c, $rule['forbid'])
                && (! isset($rule['unless']) || ! $this->tokenHit($c, $rule['unless']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Treffer eines Regel-Tokens im normalisierten String: ganzes Space-Token ODER
     * Präfix/Suffix eines Kompositum-Tokens, bei dem der RESTMORPHEM ≥ 3 Zeichen ist.
     *
     * Die Rest-Länge ist der Trick, der echte Komposita von zufälligen Flexions-
     * Nachbarn trennt: „kalbsbries" ⊃ „bries" (Rest „kalbs" = 5) und „briekaese" ⊃
     * „brie" (Rest „kaese" = 5) zünden; „bries" ⊃ „brie" (Rest „s" = 1) NICHT — sonst
     * würde der legitime Bries-Selbsttreffer geblockt. Interieur-Substrings bleiben
     * ausgeschlossen (schützt „tamarinde" ⊅ „rind").
     */
    private function tokenHit(string $normHay, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }
        $nl = mb_strlen($needle);
        foreach (explode(' ', $normHay) as $tok) {
            if ($tok === '') {
                continue;
            }
            if ($tok === $needle) {
                return true;                                   // ganzes Token
            }
            // Kompositum-Rand: Rest-Morphem ≥ 3 Zeichen (Fuge/Modifier/Kopf).
            if (mb_strlen($tok) - $nl >= 3
                && (str_starts_with($tok, $needle) || str_ends_with($tok, $needle))) {
                return true;
            }
        }

        return false;
    }

    /** Lowercase + Satzzeichen/Separatoren → Space, Whitespace kollabieren. */
    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }
}
