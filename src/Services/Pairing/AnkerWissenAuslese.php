<?php

namespace Platform\FoodAlchemist\Services\Pairing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\Achse;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * Spec 60 · P4: Anker-Wissen flächig aus den Zutaten-Dossiers lesen (Ersatz für den Agenten-Pilot).
 *
 * Pro Anker liest ein LLM (Prompt `anker.wissen_auslese`) die Dossier-Texte und liefert dasselbe
 * Profil-JSON wie der Pilot (`braucht`, `liefert`, `vertraegt`, `zerstoert`, `komponenten`). Danach
 * gilt unverändert {@see AnkerWissenImport}: alles landet als Entwurf, geprüfte Einträge bleiben.
 *
 * Zwei Sicherungen im Code statt im Prompt, weil „nur was im Text steht" ein LLM nicht von selbst hält
 * (Pilot 2026-10-14: 21 von 312 Belegen waren nicht wörtlich im Dossier, `muss` wurde teils aus
 * Ermessen vergeben):
 *   1. Ein Bedarf bleibt nur, wenn sein Beleg im Dossier-Text wiederzufinden ist.
 *   2. `muss` bleibt nur bei ausdrücklicher Formulierung im Beleg („zwingend", „Pflicht" …); sonst `soll`.
 */
final class AnkerWissenAuslese
{
    public const PROMPT_KEY = 'anker.wissen_auslese';

    /** Aspekte ohne Kombinationslogik / Aromaverhalten — bewusst nicht gelesen. */
    private const AUSGELASSEN = ['cave', 'verwertung', 'sorten'];

    /** Obergrenze des Dossier-Texts je Anker (Zeichen). */
    public const MAX_ZEICHEN = 14000;

    /** Anteil der Beleg-Wörter, der im Dossier stehen muss. */
    private const BELEG_ANTEIL = 0.8;

    private const MUSS_WORTE = ['muss', 'müssen', 'zwingend', 'pflicht', 'immer', 'unbedingt', 'nicht optional', 'kompromisslos', 'untrennbar'];

    public function __construct(private readonly AiGatewayService $ki, private readonly AnkerWissenImport $import) {}

    /**
     * Anker, die noch keinen Dossier-Eintrag haben (kein Bedarf, keine Dossier-Eigenschaft, keine Komponente)
     * und deren Dossiers etwas zu „braucht" sagen. Resumefähig: ein gelesener Anker fällt beim nächsten Lauf raus.
     *
     * @return Collection<int, object{id: int, slug: string, name: string}>
     */
    public function kandidaten(?int $nurAnker = null, int $limit = 0): Collection
    {
        $q = DB::table('foodalchemist_vocab_pairing_anchors as a')
            ->whereNull('a.deleted_at')
            ->whereNotExists(fn ($s) => $s->from('foodalchemist_anchor_bedarfe')->whereColumn('anchor_id', 'a.id'))
            ->whereNotExists(fn ($s) => $s->from('foodalchemist_anchor_eigenschaften')->whereColumn('anchor_id', 'a.id')->where('quelle', 'dossier'))
            ->whereNotExists(fn ($s) => $s->from('foodalchemist_anchor_komponenten')->whereColumn('anchor_id', 'a.id'))
            ->whereExists(fn ($s) => $s->from('foodalchemist_knowledge_documents as d')
                ->whereColumn('d.anchor_id', 'a.id')->where('d.category', 'zutat')->where('d.active', true)
                ->whereNull('d.deleted_at')->where('d.content_md', 'like', '%braucht%'))
            ->when($nurAnker !== null, fn ($x) => $x->where('a.id', $nurAnker))
            ->orderBy('a.id')
            ->select('a.id', 'a.slug', 'a.display_de as name');
        if ($limit > 0) {
            $q->limit($limit);
        }

        return $q->get();
    }

    /**
     * Dossier-Slugs und -Text eines Ankers, ohne Cave/Verwertung/Sorten.
     *
     * @return array{slugs: list<string>, text: string}
     */
    public function quellen(int $ankerId): array
    {
        $slugs = [];
        $text = '';
        $docs = DB::table('foodalchemist_knowledge_documents')
            ->where('anchor_id', $ankerId)->where('category', 'zutat')->where('active', true)->whereNull('deleted_at')
            ->orderBy('slug')->get(['slug', 'content_md']);
        foreach ($docs as $d) {
            $aspekt = (string) preg_replace('/^.*--/', '', (string) $d->slug);
            if ($this->ausgelassen($aspekt)) {
                continue;
            }
            // Frontmatter trägt nur Metadaten; der Inhalt beginnt nach dem zweiten ---
            $inhalt = trim((string) preg_replace('/\A---\n.*?\n---\n/s', '', (string) $d->content_md));
            if (mb_strlen($text) + mb_strlen($inhalt) > self::MAX_ZEICHEN) {
                break;
            }
            $slugs[] = (string) $d->slug;
            $text .= "### {$d->slug}\n{$inhalt}\n\n";
        }

        return ['slugs' => $slugs, 'text' => trim($text)];
    }

    /**
     * Einen Anker lesen. Mit $schreiben=false nur das bereinigte Profil zurückgeben.
     *
     * @return array{profil: array<string, mixed>|null, verworfen: int, importiert: array<string, mixed>|null}
     */
    public function lese(int $ankerId, bool $schreiben): array
    {
        $anker = DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $ankerId)->first(['id', 'slug', 'display_de']);
        $quellen = $this->quellen($ankerId);
        if ($anker === null || $quellen['text'] === '') {
            return ['profil' => null, 'verworfen' => 0, 'importiert' => null];
        }

        $vorschlag = $this->ki->propose(self::PROMPT_KEY, [
            'anker' => ['slug' => $anker->slug, 'name' => $anker->display_de],
            'achsen' => array_map(fn (Achse $a) => $a->value, Achse::cases()),
            'dossier' => $quellen['text'],
        ]);

        [$profil, $verworfen] = self::bereinige((array) $vorschlag->werte, $quellen['text']);
        $profil += ['anker_id' => (int) $anker->id, 'anker_slug' => (string) $anker->slug, 'name' => (string) $anker->display_de,
            'quellen' => $quellen['slugs']];

        return ['profil' => $profil, 'verworfen' => $verworfen,
            'importiert' => $schreiben ? $this->import->importiere($profil) : null];
    }

    /**
     * Antwort des Modells absichern (rein, ohne Datenbank).
     *
     * @param  array<string, mixed>  $werte
     * @return array{0: array<string, mixed>, 1: int}  Profil und Zahl der verworfenen Bedarfe
     */
    public static function bereinige(array $werte, string $dossierText): array
    {
        $woerter = self::woerter($dossierText);
        $verworfen = 0;
        $braucht = [];
        $gesehen = [];
        foreach ((array) ($werte['braucht'] ?? []) as $b) {
            if (! is_array($b) || Achse::tryFrom((string) ($b['achse'] ?? '')) === null) {
                $verworfen++;

                continue;
            }
            $beleg = trim((string) ($b['beleg'] ?? ''));
            if (! self::belegt($beleg, $woerter) || isset($gesehen[$b['achse']])) {
                $verworfen++;

                continue;
            }
            $gesehen[$b['achse']] = true;
            $braucht[] = [
                'achse' => (string) $b['achse'],
                'staerke' => ($b['staerke'] ?? '') === 'muss' && self::hatMussWort($beleg) ? 'muss' : 'soll',
                'beleg' => $beleg,
            ];
        }

        $liefert = (array) ($werte['liefert'] ?? []);
        $profil = [
            'braucht' => $braucht,
            'liefert' => [
                'geschmack' => array_filter((array) ($liefert['geschmack'] ?? []), fn ($v, $k) => Achse::tryFrom((string) $k) !== null && is_numeric($v), ARRAY_FILTER_USE_BOTH),
                'textur' => array_values(array_filter((array) ($liefert['textur'] ?? []), fn ($t) => Achse::tryFrom((string) $t) !== null)),
                'beleg' => (string) ($liefert['beleg'] ?? ''),
            ],
            'vertraegt' => array_values(array_filter((array) ($werte['vertraegt'] ?? []), fn ($v) => is_array($v) && trim((string) ($v['name'] ?? '')) !== '')),
            'zerstoert' => array_values(array_filter((array) ($werte['zerstoert'] ?? []), fn ($z) => is_array($z) && trim((string) ($z['name'] ?? '')) !== '')),
            'komponenten' => array_values(array_filter((array) ($werte['komponenten'] ?? []), fn ($k) => is_array($k) && trim((string) ($k['name'] ?? '')) !== '')),
        ];

        return [$profil, $verworfen];
    }

    /** Beleg gilt, wenn ≥ 80 % seiner Wörter (ab 3 Zeichen) im Dossier-Text vorkommen. */
    public static function belegt(string $beleg, array $dossierWoerter): bool
    {
        $teil = array_filter(array_map('strval', array_keys(self::woerter($beleg))), fn ($w) => mb_strlen($w) >= 3);
        if ($teil === []) {
            return false;
        }
        $treffer = count(array_filter($teil, fn ($w) => isset($dossierWoerter[$w])));

        return $treffer / count($teil) >= self::BELEG_ANTEIL;
    }

    public static function hatMussWort(string $beleg): bool
    {
        $b = mb_strtolower($beleg);
        foreach (self::MUSS_WORTE as $w) {
            if (str_contains($b, $w)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private static function woerter(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $m);

        return array_fill_keys($m[0], true);
    }

    private function ausgelassen(string $aspekt): bool
    {
        foreach (self::AUSGELASSEN as $a) {
            if (str_starts_with($aspekt, $a)) {
                return true;
            }
        }

        return false;
    }
}
