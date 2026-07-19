<?php

namespace Platform\FoodAlchemist\Services;

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
        foreach (self::ALIAS_GROUPS as $group) {
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
     * S2: darf dieser Kandidat für diese Query gar nicht erst in die Shortlist?
     * true = bekannte Verwechslungs-Falle → unterdrücken (unabhängig von Score).
     */
    public function isAntiMarker(string $queryName, string $candidateName): bool
    {
        $q = ' ' . $this->norm($queryName) . ' ';
        $c = ' ' . $this->norm($candidateName) . ' ';
        foreach (self::ANTI_MARKERS as $rule) {
            $trigger = ' ' . $rule['trigger'] . ' ';
            $forbid = ' ' . $rule['forbid'] . ' ';
            // Token-Grenzen für Trigger/Forbid; Guard schützt den legitimen Treffer.
            if (str_contains($q, $trigger)
                && str_contains($c, $forbid)
                && (! isset($rule['unless']) || ! str_contains($c, ' ' . $rule['unless'] . ' '))) {
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
