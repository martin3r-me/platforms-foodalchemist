<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;

/**
 * Spec 03 L7b — die EINE Generier-Strecke für beide Generator-Modals
 * (Basisrezept + Gericht) samt One-Shot-Toggle.
 *
 * Anlass ist eine Asymmetrie, die L7b sonst geerbt hätte (V-035): Der
 * 502-Fix von 2026-07-20 (LLM ~25 s + Matching + Aggregation reißen den
 * nginx-fastcgi-Timeout) wurde nur am Basisrezept-Modal umgesetzt; das
 * VK-Modal rief `RecipeGeneratorService::generiere()` weiter synchron in der
 * Livewire-Action — obwohl der VK-Pfad der teurere ist (zusätzliche
 * Taxonomie im Prompt, Kohärenz-Rechnung danach). Der One-Shot kostet 1–4
 * weitere Provider-Calls: sie laufen nach erfolgreicher Generierung in einem
 * eigenen Queue-Job; im Web-Request lägen sie obendrauf. Ein Toggle dort wäre
 * also entweder gar nicht baubar oder einer, der garantiert timeoutet.
 *
 * Darum fahren jetzt beide Flächen `GenerateRecipeJob` (der `vkModus`
 * ohnehin schon als Parameter kennt) und pollen dasselbe Cache-Ergebnis.
 * Der Toggle hängt damit an genau EINER Stelle statt zweimal am Rand.
 *
 * Host-Vertrag: `$fehler` ist eine Property der einbindenden Komponente,
 * `auswahlEvent()` liefert das Selektions-Event ihrer Fläche
 * (`recipe-selected` bzw. `vk-recipe-selected`).
 */
trait HatGeneratorLauf
{
    // Phase 0: derselbe flüchtige Toast wie bei jedem Save — hier für „KI-Lauf
    // gestartet" / „fehlgeschlagen", damit ein Klick NIE stumm bleibt.
    use InteractsWithSavedToast;

    /**
     * L7-DoD: Default AN — „Beschreibung rein, fertiges Rezept raus" ist der
     * Sinn des Knopfes. Aus lässt sich der Toggle für den schnellen Gerüst-Lauf
     * (kein zusätzlicher Provider-Call, Anreicherung später per Sammel-Klick).
     */
    public bool $vollAnreichern = true;

    /** Async: läuft, während der Queue-Job rechnet; UI pollt über die Run-ID. */
    public bool $laeuft = false;

    public ?string $runId = null;

    /** Ergebnis des One-Shot-Passes (`null` = Toggle war aus oder Lauf noch offen). */
    public ?array $anreicherung = null;

    /**
     * Phase 0 — Watchdog: gesetzt, wenn der Lauf ungewöhnlich lange auf `pending`
     * hängt (meist: kein Queue-Worker aktiv). Kein Abbruch, nur ein sichtbarer
     * Hinweis statt endlosem Spinner ohne jede Aussage.
     */
    public ?string $hinweis = null;

    /**
     * Phase 0 — Stufen-Label des laufenden Jobs (z. B. „Rezept wird entworfen …").
     * Seed für die gestufte Generierung; ersetzt den generischen Spinner-Text,
     * sobald der Job eine Stufe meldet.
     */
    public ?string $fortschritt = null;

    /**
     * Sekunden auf `pending`, ab denen der Watchdog anschlägt. Bewusst über der
     * realistischen Erst-Ergebnis-Dauer (LLM ~25 s + Re-Rolls), damit ein legitim
     * langsamer Lauf nicht fälschlich als „hängt" gemeldet wird.
     */
    protected int $watchdogSekunden = 45;

    /**
     * Generierung in die Queue geben. Der Web-Request kehrt sofort zurück —
     * die Dauer des Calls ist damit egal (kein 502).
     */
    protected function starteLauf(int $teamId, string $description, array $parameter, bool $vkModus): void
    {
        $this->hinweis = null;
        $this->fortschritt = null;
        $runId = (string) Str::uuid();
        try {
            // gestartet_at = Watchdog-Anker (server-authoritativ). Erst schreiben,
            // dann dispatchen — beide Schritte gehen an DB/Queue und können kippen.
            Cache::put(GenerateRecipeJob::cacheKey($runId), [
                'status' => 'pending',
                'gestartet_at' => now()->timestamp,
            ], now()->addMinutes(15));
            GenerateRecipeJob::dispatch(
                $runId, $teamId, (int) Auth::id(), $description, $parameter, $vkModus, $this->vollAnreichern
            );
        } catch (\Throwable $e) {
            // Infra (Cache/Queue/DB) kippte VOR dem sichtbaren Zustand. Früher brach
            // die Livewire-Action hier stumm mit 500 ab (kein Spinner, keine Meldung).
            // Jetzt: sichtbarer Fehler + Toast, Lauf gilt als nicht gestartet.
            $this->fehler = 'KI-Lauf konnte nicht gestartet werden — bitte erneut versuchen. (' . $e->getMessage() . ')';
            $this->errorToast('KI-Lauf konnte nicht gestartet werden.');

            return;
        }
        $this->runId = $runId;
        $this->laeuft = true;
        // Sofort-Rückmeldung: der Klick tut hörbar etwas, auch wenn das Rechnen dauert.
        $this->savedToast('✨ KI-Lauf gestartet — läuft im Hintergrund.');
    }

    /** Poll-Ziel (wire:poll während $laeuft): liest den Job-Ausgang aus dem Cache. */
    public function pruefeErgebnis(): void
    {
        if ($this->runId === null) {
            return;
        }
        $stand = Cache::get(GenerateRecipeJob::cacheKey($this->runId));
        if (! is_array($stand) || ($stand['status'] ?? null) === 'pending') {
            // Stufen-Label live zeigen, wenn der Job eine Stufe gemeldet hat.
            $this->fortschritt = is_array($stand) ? ($stand['progress'] ?? null) : null;
            // Watchdog: hängt der Lauf zu lange auf pending, läuft fast sicher kein
            // Worker (ein echter Timeout ruft failed() → status=error). Kein Abbruch —
            // Hinweis setzen und weiter pollen; sobald der Worker abarbeitet, kommt das Ergebnis.
            $seit = is_array($stand) ? (int) ($stand['gestartet_at'] ?? 0) : 0;
            $this->hinweis = ($seit > 0 && (now()->timestamp - $seit) > $this->watchdogSekunden)
                ? 'Der KI-Lauf läuft ungewöhnlich lange. Falls kein Ergebnis erscheint, läuft vermutlich kein Hintergrund-Worker (Queue) — sobald er den Job abarbeitet, erscheint das Rezept automatisch.'
                : null;

            return;   // noch am Rechnen → weiter pollen
        }
        $this->hinweis = null;       // Fortschritt erreicht → Watchdog-Hinweis weg
        $this->fortschritt = null;

        if (($stand['status'] ?? null) === 'error') {
            $this->laeuft = false;
            $this->fehler = $stand['fehler'] ?? 'Generierung fehlgeschlagen.';
            $this->errorToast('KI-Generierung fehlgeschlagen.');

            return;
        }

        if ($this->ergebnis === null) {
            $this->ergebnis = [
                'recipe_id' => $stand['recipe_id'],
                'name' => $stand['name'],
                'statistik' => $stand['statistik'],
                'offene' => $stand['offene'],
            ];
            $this->dispatch('recipe-gespeichert');
            $this->dispatch($this->auswahlEvent(), id: $stand['recipe_id']);
            $this->savedToast('✅ Rezept erstellt.');   // sichtbares „hat geklappt"
        }

        if (($stand['status'] ?? null) === 'enriching') {
            return; // Phase 1 ist sichtbar, Phase 2 wird weiter gepollt.
        }

        $this->laeuft = false;
        // Der Pass wirft nie (L7a) — ein Fehlschlag steht als `fehler` im Block
        // und wird als Lücken-Zeile gezeigt, nicht als Generierungs-Fehler.
        $this->anreicherung = is_array($stand['anreicherung'] ?? null) ? $stand['anreicherung'] : null;
    }

    /** Selektions-Event der jeweiligen Fläche (Basisrezept-Browser vs. VK-Browser). */
    abstract protected function auswahlEvent(): string;
}
