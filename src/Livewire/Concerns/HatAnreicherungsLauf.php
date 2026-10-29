<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Services\RecipeOneShotService;

/**
 * „Alles anreichern" im Rezept- und Gericht-Modal als Hintergrund-Job (statt synchron im Web-Request).
 *
 * Anlass (Dominique 29.10.): der Voll-Lauf (sechs Schritte, GP-Mint, Kohärenz, Wirtschaftlichkeit, acht Coverage-Glieder) lief in der
 * Livewire-Action; das Request-Limit schnitt die hinteren Glieder ab — Eignung/Niveau und Sensorik fehlten, ohne Fehlermeldung.
 * Jetzt fährt {@see EnrichRecipeJob} (300 s) und das Modal pollt den Cache ({@see EnrichRecipeJob::anreicherungCacheKey}).
 *
 * Host-Vertrag: `$recipeId`, `$fehler`, `$anreicherung`, `oeffnen(int)`, `savedToast()`/`errorToast()` und `anreicherungFertigEvent()`.
 */
trait HatAnreicherungsLauf
{
    /** Ein Lauf ist unterwegs — das Modal pollt {@see self::pruefeAnreicherung}. */
    public bool $anreicherungLaeuft = false;

    /** Livewire-Event, das die Liste nach dem Lauf neu lädt (`recipe-gespeichert` bzw. `vk-recipe-gespeichert`). */
    abstract protected function anreicherungFertigEvent(): string;

    protected function starteAnreicherung(Team $team): void
    {
        $key = EnrichRecipeJob::anreicherungCacheKey((int) $team->id, (int) $this->recipeId);
        $stand = Cache::get($key);
        if (is_array($stand) && in_array($stand['status'] ?? null, ['queued', 'running'], true)) {
            $this->anreicherungLaeuft = true;   // läuft schon — nicht doppelt dispatchen

            return;
        }
        Cache::put($key, ['status' => 'queued', 'at' => now()->toIso8601String()], now()->addMinutes(30));
        // #4: expliziter Klick = Refresh auch gefüllter, nicht-manueller Felder; volle Coverage.
        EnrichRecipeJob::dispatch(
            teamId: (int) $team->id,
            userId: (int) (Auth::id() ?? 0),
            recipeId: (int) $this->recipeId,
            refresh: true,
            completeCoverage: true,
        );
        // #4 Kaskade: die Basisrezept-Komponenten im Hintergrund mit-anreichern (refresh=true).
        $subIds = app(RecipeOneShotService::class)->subRezeptIds((int) $this->recipeId);
        foreach ($subIds as $subId) {
            EnrichRecipeJob::dispatch(
                teamId: (int) $team->id, userId: (int) (Auth::id() ?? 0), recipeId: (int) $subId, refresh: true,
            );
        }
        $this->anreicherungLaeuft = true;
        $this->savedToast('Anreicherung läuft im Hintergrund …' . ($subIds !== [] ? ' (' . count($subIds) . ' Komponente(n) folgen)' : ''));
    }

    /** Poll-Ziel (wire:poll während {@see self::$anreicherungLaeuft}): liest den Job-Ausgang aus dem Cache. */
    public function pruefeAnreicherung(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            $this->anreicherungLaeuft = false;

            return;
        }
        $stand = Cache::get(EnrichRecipeJob::anreicherungCacheKey((int) $team->id, (int) $this->recipeId));
        if (is_array($stand) && in_array($stand['status'] ?? null, ['queued', 'running'], true)) {
            // Ein Job, der hart starb, ohne failed() zu erreichen (Worker-Neustart): nach 10 Minuten nicht ewig weiterdrehen.
            $seit = isset($stand['at']) ? strtotime((string) $stand['at']) : false;
            if ($seit === false || time() - $seit < 600) {
                return;   // noch am Rechnen → weiter pollen
            }
            $stand = ['status' => 'failed', 'error' => 'Der Lauf hat sich nicht zurückgemeldet. Bitte erneut starten.'];
        }
        $this->anreicherungLaeuft = false;
        if (! is_array($stand)) {
            return;
        }
        if (($stand['status'] ?? null) === 'failed') {
            $this->fehler = $stand['error'] ?? 'Anreicherung fehlgeschlagen.';
            $this->errorToast('Anreicherung fehlgeschlagen.');

            return;
        }
        $ergebnis = is_array($stand['ergebnis'] ?? null) ? $stand['ergebnis'] : null;
        $this->oeffnen((int) $this->recipeId);
        $this->anreicherung = $ergebnis;
        $this->dispatch($this->anreicherungFertigEvent());
    }
}
