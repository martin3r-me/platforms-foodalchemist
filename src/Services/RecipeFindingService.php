<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeFinding;

/**
 * Spec 21 · S5a (Tranche B) — die Ablage-Hälfte des Rezept-Copilot.
 *
 * `RecipeReviewService` bleibt read-only (der MCP-Weg `recipes.REVIEW` trägt
 * `read_only`), dieser Service ist der einzige Schreiber der Befund-Zeilen. Damit
 * ist der Prüf-Pass weiter kostenlos wiederholbar und der Batch der einzige, der
 * Bestand anlegt.
 *
 * Drei Entscheidungen tragen das Ganze:
 *
 *  1. **Der Fingerprint kennt den Wert NICHT.** Er ist `art + Zielzeile` — also
 *     „an dieser Zeile stimmt die Menge nicht", nicht „die Menge soll 1200 sein".
 *     Sonst wäre eine Ablehnung wirkungslos: der nächste Lauf schlägt 1250 vor,
 *     das wäre ein neuer Fingerprint, eine neue Zeile und wieder ein Signal —
 *     genau das Rausch-Muster, gegen das S2b die Policies gebaut hat.
 *  2. **`verworfen` bleibt verworfen, `uebernommen` darf zurückkommen.** „Lass das
 *     so" ist eine menschliche Entscheidung über den Sachverhalt und muss halten.
 *     Ein *angewendeter* Befund, der wieder auftaucht, ist dagegen eine Nachricht:
 *     der Fix hat nicht gegriffen. Er geht zurück auf `offen`, `seen_count` trägt
 *     die Wiederkehr.
 *  3. **Der Prüf-Stempel darf `updated_at` nicht anfassen.** Die Arbeitsmenge ist
 *     change-driven (`ai_reviewed_at < updated_at`); ein Eloquent-`save()` würde
 *     das Rezept mit dem Stempel sofort wieder als geändert markieren und in jedem
 *     Lauf erneut kosten. Darum ein roher Update ohne Timestamps.
 */
class RecipeFindingService
{
    /**
     * Ab dieser Konfidenz wird ein offener Befund für S5b signalwürdig. Bewusst
     * hoch: der Copilot liefert auch Geschmacksfragen, und ein Signal ist eine
     * Behauptung über Datenqualität, keine Anregung.
     */
    public const KONFIDENZ_SCHWELLE = 0.7;

    /**
     * S5b-2: die Ablage trägt jetzt zwei PÄSSE — den Copilot über die Rezeptur (S5a)
     * und den Bauart-Pass über das Rezept als Ganzes. Sie teilen Tabelle und Mechanik
     * (Fingerprint, Wiederkehr, Ablehnung), aber nichts sonst: eigener Erzeuger,
     * eigener Prüf-Stempel, eigenes Signal.
     *
     * Der Stempel MUSS je Pass verschieden sein, sonst nehmen sich die Läufe
     * gegenseitig die Fälligkeit weg (s. Migration `…_000005`). Die Arten-Liste MUSS
     * je Pass verschieden sein, sonst schlösse der eine Lauf die offenen Befunde des
     * anderen als „nicht mehr gemeldet".
     */
    public const PASS_COPILOT = 'copilot';

    public const PASS_BAUART = 'bauart';

    private const PAESSE = [
        self::PASS_COPILOT => ['arten' => RecipeReviewService::ARTEN_COPILOT, 'stempel' => 'ai_reviewed_at'],
        self::PASS_BAUART => ['arten' => RecipeReviewService::ARTEN_STRUKTUR, 'stempel' => 'structure_reviewed_at'],
    ];

    /** Rezepte, deren Prüfung fällig ist: nie geprüft oder seit der Prüfung angefasst. */
    public function arbeitsmenge(Team $team, bool $nurVerkauf = false, string $pass = self::PASS_COPILOT): Builder
    {
        $stempel = $this->pass($pass)['stempel'];

        $q = FoodAlchemistRecipe::visibleToTeam($team)
            ->whereIn('status', ['review', 'approved'])
            ->where(fn ($w) => $w->whereNull($stempel)
                ->orWhereColumn($stempel, '<', 'updated_at'));

        if ($nurVerkauf) {
            $q->verkauf();
        }

        // Nie-Geprüfte zuerst, danach die am längsten Ungeprüften. `IS NULL` als
        // Sortier-Ausdruck funktioniert in MySQL und SQLite gleich (1/0).
        return $q->orderByRaw($stempel . ' IS NULL DESC')->orderBy($stempel);
    }

    /**
     * Ein Rezept prüfen und die Befunde ablegen — der Batch-Schritt.
     *
     * @return array{neu:int, wieder:int, offen:int, verschwunden:int}
     */
    public function pruefeUndAblegen(Team $team, int $recipeId, ?int $runId = null, string $pass = self::PASS_COPILOT): array
    {
        $ergebnis = $pass === self::PASS_BAUART
            ? app(RecipeBauartService::class)->pruefe($team, $recipeId)
            : app(RecipeReviewService::class)->pruefe($team, $recipeId);

        return $this->speichere($team, $recipeId, $ergebnis['befunde'], $runId, $pass);
    }

    /**
     * Befund-Liste (Form: `RecipeReviewService::pruefe()['befunde']`) als Zeilen ablegen.
     * Idempotent: derselbe Pass zweimal erzeugt keine zweite Zeile.
     *
     * @param  array<int, array<string, mixed>>  $befunde
     * @return array{neu:int, wieder:int, offen:int, verschwunden:int}
     */
    public function speichere(Team $team, int $recipeId, array $befunde, ?int $runId = null, string $pass = self::PASS_COPILOT): array
    {
        $konfig = $this->pass($pass);

        // `withTrashed`, weil der Unique-Index (team+recipe+fingerprint) auch auf
        // gelöschte Zeilen greift: ein weggeräumter Befund, der wiederkommt, würde
        // sonst am Insert scheitern statt wieder aufzutauchen.
        $bestand = FoodAlchemistRecipeFinding::withTrashed()
            ->where('team_id', $team->id)->where('recipe_id', $recipeId)->get()->keyBy('fingerprint');

        $zaehler = ['neu' => 0, 'wieder' => 0, 'offen' => 0, 'verschwunden' => 0];
        $gesehen = [];

        foreach ($befunde as $b) {
            $art = (string) ($b['art'] ?? 'hinweis');
            // Fremde Art im Pass wird NICHT stillschweigend umgedeutet: sie gehört
            // einem anderen Erzeuger, dessen Sweep sie unten sonst gleich schlösse.
            if (! in_array($art, $konfig['arten'], true)) {
                continue;
            }
            $fp = $this->fingerprint($b);
            if ($fp === null || in_array($fp, $gesehen, true)) {
                continue;                                   // leerer oder doppelter Befund im selben Pass
            }
            $gesehen[] = $fp;

            $felder = [
                'kind' => $art,
                'ingredient_id' => ($b['zutat_id'] ?? null) !== null ? (int) $b['zutat_id'] : null,
                'ingredient_text' => ($b['zutat_text'] ?? '') !== '' ? mb_strimwidth((string) $b['zutat_text'], 0, 255) : null,
                'quantity' => ($b['quantity'] ?? null) !== null ? (float) $b['quantity'] : null,
                'unit_slug' => $b['einheit_slug'] ?? null,
                'reason' => ($b['begruendung'] ?? '') !== '' ? (string) $b['begruendung'] : null,
                'confidence' => (float) ($b['konfidenz'] ?? 0),
                'auto_applicable' => (bool) ($b['auto_applicable'] ?? false),
                'applicability' => (string) ($b['status'] ?? 'anwendbar'),
                'run_id' => $runId,
                'last_seen_at' => now(),
            ];

            $zeile = $bestand->get($fp);
            if ($zeile === null) {
                FoodAlchemistRecipeFinding::create([
                    ...$felder,
                    'team_id' => $team->id,
                    'recipe_id' => $recipeId,
                    'fingerprint' => $fp,
                    'status' => 'offen',
                    'seen_count' => 1,
                    'first_seen_at' => now(),
                ]);
                $zaehler['neu']++;
                $zaehler['offen']++;

                continue;
            }

            $vorher = $zeile->status;
            if ($zeile->trashed()) {
                $zeile->restore();
            }
            $zeile->fill($felder);
            $zeile->seen_count = $zeile->seen_count + 1;
            // Entscheidung 2: nur `verworfen` hält. Alles andere ist wieder offen —
            // ein wiedergekehrter, schon angewendeter Befund ist ein Befund.
            if ($vorher !== 'verworfen') {
                $zeile->status = 'offen';
            }
            $zeile->save();

            if ($vorher !== 'offen' && $zeile->status === 'offen') {
                $zaehler['wieder']++;
            }
            if ($zeile->status === 'offen') {
                $zaehler['offen']++;
            }
        }

        // Nicht mehr gemeldete OFFENE Befunde schließen — das Rezept wurde geändert
        // oder das Modell sieht es anders. Entschiedene Zeilen bleiben stehen.
        // Streng auf die Arten DIESES Passes begrenzt: ein Bauart-Lauf darf die
        // offenen Mengen-Befunde des Copilot nicht mit abräumen (und umgekehrt).
        $weg = FoodAlchemistRecipeFinding::query()
            ->where('team_id', $team->id)->where('recipe_id', $recipeId)
            ->whereIn('kind', $konfig['arten'])
            ->where('status', 'offen')
            ->when($gesehen !== [], fn ($q) => $q->whereNotIn('fingerprint', $gesehen))
            ->update(['status' => 'verschwunden', 'updated_at' => now()]);
        $zaehler['verschwunden'] = (int) $weg;

        $this->stempele($recipeId, $konfig['stempel']);

        return $zaehler;
    }

    /** Menschliche Entscheidung an einer Befund-Zeile (`uebernommen` / `verworfen`). */
    public function entscheide(Team $team, int $findingId, string $status): FoodAlchemistRecipeFinding
    {
        if (! in_array($status, ['uebernommen', 'verworfen'], true)) {
            throw new \InvalidArgumentException('Nur uebernommen/verworfen sind menschliche Entscheidungen.');
        }
        $zeile = FoodAlchemistRecipeFinding::query()
            ->where('team_id', $team->id)->whereKey($findingId)->first();
        if ($zeile === null) {
            throw new \RuntimeException('Befund nicht gefunden.');
        }
        $zeile->status = $status;
        $zeile->decided_at = now();
        $zeile->save();

        return $zeile;
    }

    /**
     * Signal-Kandidaten (S5b liest genau das): offen und über der Schwelle.
     *
     * `$arten` ist die Pass-Grenze und ab S5b-2 der Normalfall am Signal-Register:
     * `rezept_plausi_ki` zählt die Rezeptur-Befunde, `rezept_gericht_vs_komponente`
     * die Bauart-Befunde. Ohne Einschränkung zählt sie alles — das ist die Sicht
     * „wie voll ist die KI-Inbox insgesamt", nicht die eines einzelnen Signals.
     *
     * @param  list<string>|null  $arten
     */
    public function offeneUeberSchwelle(Team $team, ?float $schwelle = null, ?array $arten = null): Builder
    {
        return FoodAlchemistRecipeFinding::query()
            ->where('team_id', $team->id)
            ->where('status', 'offen')
            ->when($arten !== null, fn ($q) => $q->whereIn('kind', $arten))
            ->where('confidence', '>=', $schwelle ?? self::KONFIDENZ_SCHWELLE);
    }

    /**
     * S5b — die abgelegten offenen Befunde EINES Rezepts in der Form, die
     * `RecipeReviewService::bewerte()` und die Copilot-Fläche lesen.
     *
     * Bewusst OHNE Pass-Grenze: die Fläche im Rezept ist die KI-Inbox DIESES Rezepts,
     * und der Mensch, der sie öffnet, will beides sehen — die Mengen-Befunde und die
     * Frage nach der Bauart. Getrennt wird auf der Signal-Seite (zwei Typen), nicht hier.
     *
     * Bewusst ohne die gespeicherte Anwendbarkeit (`auto_applicable`/`applicability`):
     * die stammt aus dem Batch-Zeitpunkt und kann längst überholt sein — die Zielzeile
     * ist vielleicht weg, die „fehlende" Zutat schon drin. Wer diese Liste anzeigt,
     * schickt sie durch `bewerte()` und bekommt die Entscheidung frisch aus derselben
     * Stelle wie der Live-Pass. Die id reist als `finding_id` mit, damit die Fläche
     * eine Übernahme auch an der Ablage vermerken kann.
     *
     * @return array<int, array<string, mixed>>
     */
    public function offeneBefundeFuer(Team $team, int $recipeId, ?float $schwelle = null): array
    {
        return $this->offeneUeberSchwelle($team, $schwelle)
            ->where('recipe_id', $recipeId)
            ->orderByDesc('confidence')->orderBy('id')
            ->get()
            ->map(fn (FoodAlchemistRecipeFinding $f) => [
                'finding_id' => (int) $f->id,
                'art' => (string) $f->kind,
                'zutat_id' => $f->ingredient_id !== null ? (int) $f->ingredient_id : null,
                'zutat_text' => (string) ($f->ingredient_text ?? ''),
                'quantity' => $f->quantity !== null ? (float) $f->quantity : null,
                'einheit_slug' => $f->unit_slug,
                'begruendung' => (string) ($f->reason ?? ''),
                'konfidenz' => (float) $f->confidence,
            ])->all();
    }

    /**
     * Dedup-Schlüssel: Art + Zielzeile. Ohne Wert (s. Klassen-Doku), ohne Fuzzy —
     * die Zielzeile ist entweder die id oder der genannte Text.
     */
    private function fingerprint(array $befund): ?string
    {
        $art = (string) ($befund['art'] ?? '');
        if ($art === '') {
            return null;
        }
        // S5b-2: Befunde über das Rezept ALS GANZES haben keine Zielzeile. Der
        // Text-Rückfall unten wäre hier fatal — die Begründung formuliert das Modell
        // in jedem Lauf anders, jeder Lauf ergäbe einen neuen Fingerprint, eine neue
        // Zeile und ein wiederauferstehendes Signal trotz „Lass das so". Die Art
        // allein ist der Schlüssel: es gibt je Rezept genau einen solchen Befund.
        if (in_array($art, RecipeReviewService::ARTEN_STRUKTUR, true)) {
            return sha1($art . '|rezept');
        }
        $ziel = ($befund['zutat_id'] ?? null) !== null
            ? 'z' . (int) $befund['zutat_id']
            : mb_strtolower(trim((string) (($befund['zutat_text'] ?? '') !== '' ? $befund['zutat_text'] : ($befund['begruendung'] ?? ''))));
        if ($ziel === '') {
            return null;
        }

        return sha1($art . '|' . $ziel);
    }

    /** Prüf-Stempel des Passes, ohne `updated_at`-Nebenwirkung (s. Entscheidung 3). */
    private function stempele(int $recipeId, string $spalte): void
    {
        DB::table('foodalchemist_recipes')->where('id', $recipeId)->update([$spalte => now()]);
    }

    /**
     * Pass-Konfiguration oder harter Fehler — ein Tippfehler im Pass-Namen würde sonst
     * auf den Copilot-Stempel zurückfallen und dessen Fälligkeit stillschweigend
     * verbrennen.
     *
     * @return array{arten: list<string>, stempel: string}
     */
    private function pass(string $pass): array
    {
        return self::PAESSE[$pass]
            ?? throw new \InvalidArgumentException("Unbekannter Prüf-Pass [{$pass}].");
    }
}
