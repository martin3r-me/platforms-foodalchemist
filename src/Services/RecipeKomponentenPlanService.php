<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Support\BestandsPassung;
use Platform\FoodAlchemist\Support\RezeptTypVokabular;

/**
 * Spec 80 Teil B — Komponenten-Plan für Basisrezepte. Vorher baute der Generator ein Basisrezept immer als
 * EINEN Baustein aus Rohware; Unterrezepte entstanden nur zufällig (demo: 7 von 32 Läufen). Zielbild
 * (Dominique 2026-10-09): „Püree: Petersilienwurzel (grün)" = 1,5 kg „Püree: Petersilienwurzel" aus dem
 * Bestand + 100 g „Matte: Petersilie" neu.
 *
 * Ablauf: die KI schlägt die Komponenten vor (Name, Funktion, Menge im Ansatz, Suchbegriffe); der CODE sucht
 * je Komponente einen Bestandskandidaten (nur freigegeben, Funktionsprüfung {@see BestandsPassung}) und hält
 * abgelehnte Kandidaten mit Grund fest. Der Mensch bestätigt den Plan, erst dann wird gebaut.
 */
class RecipeKomponentenPlanService
{
    public const MAX_KOMPONENTEN = 6;

    public const FUNKTIONEN = ['basis', 'farbe', 'bindung', 'wuerze', 'textur', 'aroma', 'saeure', 'fett', 'garnitur'];

    /**
     * @param  array<string, mixed>  $params  Lauf-Parameter (suchbegriffe, ziel_menge, ziel_einheit, diaet_hart …)
     * @return list<array{name: string, funktion: ?string, menge: ?float, einheit: ?string, suchbegriffe: list<string>,
     *                    bestand: ?array{recipe_id: int, name: string}, abgelehnt: list<array{recipe_id: int, name: string, grund: string}>, neu: bool}>
     */
    public function plane(Team $team, string $brief, array $params, ?int $max = null): array
    {
        $max = max(1, min($max ?? self::MAX_KOMPONENTEN, self::MAX_KOMPONENTEN));
        $vorschlag = app(AiGatewayService::class)->propose('recipe.komponenten_plan', array_filter([
            'briefing' => $brief,
            'suchbegriffe' => RecipeGenerationContextService::suchbegriffeAus($params) ?: null,
            'ansatz' => isset($params['ziel_menge'], $params['ziel_einheit']) ? $params['ziel_menge'] . ' ' . $params['ziel_einheit'] : null,
            // Unterrezept: Bedarf des Gerichts nur als Information — die Charge wählt der Plan zum Sektor (Dominique 10.10.).
            'bedarf_im_gericht' => ! isset($params['ziel_menge']) && (float) ($params['bedarf_menge'] ?? 0) > 0
                ? $params['bedarf_menge'] . ' ' . ($params['bedarf_einheit'] ?? 'g') . ' (nur Bedarf, nicht der Ansatz — Charge passend zum Sektor)'
                : null,
            'sektor' => $params['sektor'] ?? null,
            'niveau' => $params['level'] ?? null,
            'convenience' => $params['convenience'] ?? null,
            'funktionen' => self::FUNKTIONEN,
        ], static fn ($v) => $v !== null && $v !== ''), [
            'structural_retry' => fn (array $p) => is_array($p['werte']['komponenten'] ?? null) && $p['werte']['komponenten'] !== [],
        ]);

        $diaet = array_values(array_filter((array) ($params['diaet_hart'] ?? []), 'is_string'));
        $bestandErlaubt = ($params['bestand'] ?? 'hybrid') !== 'komplett_neu';
        $out = [];
        foreach (array_slice((array) ($vorschlag->werte['komponenten'] ?? []), 0, $max) as $k) {
            if (! is_array($k) || trim((string) ($k['name'] ?? '')) === '') {
                continue;
            }
            $name = self::bereinigeName((string) $k['name']);
            $menge = is_numeric($k['menge'] ?? null) && (float) $k['menge'] > 0 ? (float) $k['menge'] : null;
            $funktion = in_array($k['funktion'] ?? null, self::FUNKTIONEN, true) ? $k['funktion'] : null;
            $abgelehnt = [];
            $bestand = $bestandErlaubt ? $this->bestandFuer($team, $name, $diaet, $abgelehnt) : null;
            $out[] = [
                'name' => $name,
                'funktion' => $funktion,
                'menge' => $menge,
                'einheit' => is_string($k['einheit'] ?? null) && trim($k['einheit']) !== '' ? mb_strtolower(trim($k['einheit'])) : null,
                'suchbegriffe' => array_values(array_filter(array_map(static fn ($t) => is_scalar($t) ? trim((string) $t) : '', (array) ($k['suchbegriffe'] ?? [])))),
                'bestand' => $bestand,
                'abgelehnt' => $abgelehnt,
                'neu' => $bestand === null,
            ];
        }

        return $out;
    }

    /**
     * Komponenten-Name säubern (Live-Test demo 09.10., Lauf 81): die KI hängte „nach Basisrezept-Regelwerk §1" an
     * und doppelte den Typ („Püree: Petersilienwurzelpüree"). Ergebnis: „Püree: Petersilienwurzel" — kanonischer
     * Typ aus dem Vokabular, Bezeichnung ohne Typ-Wort am Ende, ohne Regelwerk-Verweise.
     */
    public static function bereinigeName(string $name): string
    {
        $n = trim((string) preg_replace('/\s*\([^)]*(?:§|regelwerk)[^)]*\)/iu', '', $name));
        $n = trim((string) preg_replace('/[\s,;-]+(?:nach|gemäß|gem\.|laut|gemäss)\s+[^:]*?(?:regelwerk|§)[^:]*$/iu', '', $n));
        if (($praefix = RezeptTypVokabular::praefix($n)) === null) {
            return $n;
        }
        $typ = RezeptTypVokabular::finde($praefix) ?? $praefix;
        $bez = trim((string) substr($n, strpos($n, ':') + 1));
        $typN = RezeptTypVokabular::norm($typ);
        $bezN = RezeptTypVokabular::norm($bez);
        if ($typN !== '' && mb_strlen($bezN) > mb_strlen($typN) + 2 && str_ends_with($bezN, $typN) && ! str_contains($bez, ' ')) {
            // „Petersilienwurzelpüree" → „Petersilienwurzel": Typ-Wort am Ende abschneiden (Länge in der Originalschreibweise).
            $rest = mb_substr($bez, 0, mb_strlen($bez) - mb_strlen($typ));
            if (RezeptTypVokabular::norm($rest . $typ) === $bezN && mb_strlen($rest) >= 3) {
                // Fugenelement weg: „Petersilien|matte" → Petersilie, „Kalbs|fond" → Kalb, „Tomaten|sauce" → Tomate.
                if (preg_match('/[^s]s$/u', $rest) === 1 && mb_strlen($rest) > 4) {
                    $rest = mb_substr($rest, 0, -1);
                } elseif (preg_match('/en$/u', $rest) === 1 && mb_strlen($rest) > 5) {
                    $rest = mb_substr($rest, 0, -1);
                }
                $bez = $rest;
            }
        }

        return $typ . ': ' . $bez;
    }

    /**
     * Bestes freigegebenes Basisrezept für eine Komponente: zuerst gleicher Name (Token-Set), dann die
     * Matcher-Kandidaten. Jeder Kandidat muss die Funktionsprüfung bestehen; Ablehnungen landen mit Grund
     * in `$abgelehnt`.
     *
     * @param  list<string>  $diaet
     * @param  list<array{recipe_id: int, name: string, grund: string}>  $abgelehnt
     * @return array{recipe_id: int, name: string}|null
     */
    public function bestandFuer(Team $team, string $name, array $diaet, array &$abgelehnt): ?array
    {
        $ids = [];
        if (($gleich = app(RecipeService::class)->findByTokenSetMitReife($team, $name)) !== null) {
            $ids[] = (int) $gleich['recipe']->id;
        }
        foreach (app(IngredientMatchService::class)->candidatesFor($team, $name, null, 5) as $c) {
            if (($c['kind'] ?? null) === 'sub' && isset($c['id'])) {
                $ids[] = (int) $c['id'];
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return null;
        }
        $kandidaten = FoodAlchemistRecipe::query()->visibleToTeam($team)->basis()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'status', 'spec_is_vegan', 'spec_is_vegetarian'])->keyBy('id');
        foreach ($ids as $id) {
            $r = $kandidaten->get($id);
            if ($r === null) {
                continue;
            }
            // Bestand = nur freigegeben (Entscheid 09.10.). Nicht freigegebene Treffer sichtbar machen statt still
            // fallen zu lassen — sonst wirkt „nicht gefunden", obwohl es einen Entwurf gibt.
            $status = $r->status instanceof \BackedEnum ? $r->status->value : (string) $r->status;
            if ($status !== 'approved') {
                $abgelehnt[] = ['recipe_id' => (int) $r->id, 'name' => (string) $r->name, 'grund' => "noch nicht freigegeben (Status {$status})"];

                continue;
            }
            $grund = BestandsPassung::grund($name, (string) $r->name,
                $r->spec_is_vegan !== null ? (bool) $r->spec_is_vegan : null,
                $r->spec_is_vegetarian !== null ? (bool) $r->spec_is_vegetarian : null, $diaet);
            if ($grund === null) {
                return ['recipe_id' => (int) $r->id, 'name' => (string) $r->name];
            }
            $abgelehnt[] = ['recipe_id' => (int) $r->id, 'name' => (string) $r->name, 'grund' => $grund];
        }

        return null;
    }

    /**
     * Für den Generator: Plan-Komponenten als Zutatenzeilen in den KI-Vorschlag einmischen. Eine Zeile mit
     * gleichem Namen ersetzt die KI-Zeile, sonst wird angehängt. Bestand → `sub_rezept_id`, Lücke →
     * `sub_rezept: true` (wird zum Kind-Step mit Ansatz = Zeilenmenge).
     *
     * @param  list<array<string, mixed>>  $zutaten
     * @param  list<array<string, mixed>>  $plan
     * @return list<array<string, mixed>>
     */
    public static function einmischen(array $zutaten, array $plan): array
    {
        // Über die Wortmenge vergleichen (wie der Matcher), nicht über den Wortlaut: „Reduktion: Ginger Beer-Ingwer“
        // (KI) und „Reduktion: Ginger Beer Ingwer“ (Plan) sind dieselbe Zeile. Vorher hängte ein Bindestrich die
        // Plan-Komponente ein zweites Mal an — demo Lauf 87: zwei gleiche Zeilen im Jus, zwei gleiche Unterrezepte.
        $engine = app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class);
        $norm = static function ($s) use ($engine): string {
            $t = $engine->tokenize((string) $s);
            sort($t);

            return implode(' ', $t);
        };
        foreach ($plan as $k) {
            if (! is_array($k) || trim((string) ($k['name'] ?? '')) === '') {
                continue;
            }
            $bestand = is_array($k['bestand'] ?? null) ? $k['bestand'] : null;
            $text = $bestand['name'] ?? (string) $k['name'];
            $zeile = [
                'text' => $text,
                'quantity' => $k['menge'] ?? 1,
                'unit' => $k['einheit'] ?? 'g',
                'sub_rezept' => true,
                'sub_rezept_id' => $bestand['recipe_id'] ?? null,
                'note' => 'Komponente laut Plan' . (! empty($k['funktion']) ? ' (' . $k['funktion'] . ')' : ''),
            ];
            $treffer = null;
            foreach ($zutaten as $i => $z) {
                $zText = $norm($z['text'] ?? $z['name'] ?? '');
                if ($zText !== '' && ($zText === $norm($k['name']) || $zText === $norm($text))) {
                    $treffer = $i;
                    break;
                }
            }
            if ($treffer !== null) {
                $zutaten[$treffer] = $zeile + $zutaten[$treffer];
            } else {
                $zutaten[] = $zeile;
            }
        }

        return array_values($zutaten);
    }
}
