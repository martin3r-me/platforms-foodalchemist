<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Services\HardstopResolveService;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * Spec 03 L7b-2b — die Hard-Stop-Fläche für BEIDE Generator-Modals.
 *
 * Der Paritäts-Check der L7-DoD war negativ (geprüft 2026-07-26): „GP anlegen"
 * / „Basisrezept anlegen" standen an beiden Flächen nur als *Text*, und von der
 * längst mitgelieferten `offene[].shortlist` wurde nur die *Anzahl* gezeigt.
 * Die Kandidaten waren also da — sie wurden nur weggeworfen.
 *
 * Ein Trait statt zweier Kopien, aus demselben Grund wie bei `HatGeneratorLauf`
 * und `HatRezeptCopilot`: die Fläche unterscheidet sich nur im Wording, die
 * Mechanik nicht. Host-Vertrag: `$ergebnis` (mit `recipe_id`/`offene`/
 * `statistik`) und `$fehler` sind Properties der einbindenden Komponente.
 *
 * Zustands-Pflege nach einer Auflösung ist bewusst lokal (Liste kürzen, Zähler
 * korrigieren) statt „Lauf neu laden": das Lauf-Ergebnis liegt im Cache des
 * Jobs und ist ein Protokoll des Laufs — es wird nicht rückwirkend umgeschrieben.
 */
trait HatHardstopAktionen
{
    /** Rückmeldung der letzten Auflösung (grün = ok, rot über `$fehler`). */
    public ?string $hardstopMeldung = null;

    /** Aufgeklappte „Meintest du?"-Shortlist je Hard-Stop-Index. */
    public array $hardstopOffenIndex = [];

    public bool $freigegeben = false;

    public function toggleShortlist(int $index): void
    {
        $this->hardstopOffenIndex[$index] = ! ($this->hardstopOffenIndex[$index] ?? false);
    }

    /** „Meintest du?" — Bestands-Kandidat aus der Shortlist binden. */
    public function hardstopVerknuepfen(int $index, string $kind, int $zielId): void
    {
        $this->hardstopAktion($index, fn ($team, $zeile) => app(HardstopResolveService::class)->verknuepfe(
            $team, (int) $this->ergebnis['recipe_id'], $zeile['index'] + 1, $kind, $zielId,
        ));
    }

    /** Halbfabrikat-Lücke: Basisrezept-Stub anlegen + binden. */
    public function hardstopStubAnlegen(int $index): void
    {
        $this->hardstopAktion($index, fn ($team, $zeile) => app(HardstopResolveService::class)->stubAnlegen(
            $team, (int) $this->ergebnis['recipe_id'], $zeile['index'] + 1, (string) $zeile['text'],
        ));
    }

    /** Schritt 1: vorgeschlagenen Lieferantenartikel auswählen, noch nichts schreiben. */
    public function hardstopLaWaehlen(int $index, int $laId): void
    {
        foreach ($this->ergebnis['offene'] ?? [] as $key => $offen) {
            if ((int) ($offen['index'] ?? -1) !== $index) {
                continue;
            }
            $gueltig = collect($offen['la_kandidaten'] ?? [])->contains(
                fn (array $la) => (int) ($la['id'] ?? 0) === $laId
            );
            if ($gueltig) {
                $this->ergebnis['offene'][$key]['selected_la_id'] = $laId;
                $this->hardstopMeldung = 'Lieferantenartikel gewählt — jetzt vorhandenes oder neues GP bestätigen.';
            }
            return;
        }
    }

    /** Schritt 2: gewählten LA mit vorhandenem/neuem GP verbinden und Rezeptzeile binden. */
    public function hardstopLaGpBestaetigen(int $index, ?int $gpId = null): void
    {
        $this->hardstopAktion($index, function ($team, $zeile) use ($gpId) {
            $laId = (int) ($zeile['selected_la_id'] ?? 0);
            if ($laId <= 0) {
                return ['ok' => false, 'meldung' => 'Bitte zuerst einen Lieferantenartikel wählen.'];
            }

            return app(HardstopResolveService::class)->lieferantenartikelMitGpVerknuepfen(
                $team, (int) $this->ergebnis['recipe_id'], $zeile['index'] + 1,
                $laId, $gpId, (string) $zeile['text'],
            );
        });
    }

    /** Rezeptfreigabe bleibt eine bewusste, separate Aktion nach allen Zuordnungen. */
    public function generatorFreigeben(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || ($this->ergebnis['recipe_id'] ?? null) === null) {
            return;
        }
        if (count($this->ergebnis['offene'] ?? []) > 0) {
            $this->fehler = 'Vor der Freigabe bitte alle offenen Zutaten zuordnen.';
            return;
        }

        app(RecipeService::class)->setStatus($team, (int) $this->ergebnis['recipe_id'], 'approved');
        $this->freigegeben = true;
        $this->hardstopMeldung = 'Rezept freigegeben.';
        $this->dispatch('recipe-gespeichert');
    }

    /**
     * GP-Lücke: Beschaffungs-Wunsch. Löst die Zeile NICHT auf (kein GP ohne LA)
     * — darum bleibt sie in der Liste stehen, mit Quittung darunter.
     */
    public function hardstopBeschaffen(int $index): void
    {
        $zeile = $this->hardstopZeile($index);
        $team = Auth::user()?->currentTeamRelation;
        if ($zeile === null || $team === null) {
            return;
        }

        try {
            $ergebnis = app(HardstopResolveService::class)->beschaffungAnstossen(
                $team, (int) $this->ergebnis['recipe_id'], (string) $zeile['text'], null, (int) Auth::id(),
            );
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }

        $this->hardstopMeldung = $ergebnis['meldung'];
    }

    /** @return array|null */
    private function hardstopZeile(int $index): ?array
    {
        foreach ($this->ergebnis['offene'] ?? [] as $offen) {
            if ((int) ($offen['index'] ?? -1) === $index) {
                return $offen;
            }
        }

        return null;
    }

    /** Gemeinsamer Rahmen der beiden auflösenden Aktionen (Stub + Verknüpfen). */
    private function hardstopAktion(int $index, callable $tun): void
    {
        $zeile = $this->hardstopZeile($index);
        $team = Auth::user()?->currentTeamRelation;
        if ($zeile === null || $team === null || ($this->ergebnis['recipe_id'] ?? null) === null) {
            return;
        }

        try {
            $ergebnis = $tun($team, $zeile);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();

            return;
        }

        if (! ($ergebnis['ok'] ?? false)) {
            $this->fehler = $ergebnis['meldung'] ?? 'Auflösung fehlgeschlagen.';

            return;
        }

        $this->hardstopMeldung = $ergebnis['meldung'];
        $this->ergebnis['offene'] = array_values(array_filter(
            $this->ergebnis['offene'],
            fn (array $o) => (int) ($o['index'] ?? -1) !== $index,
        ));
        $this->ergebnis['statistik']['offen'] = max(0, (int) ($this->ergebnis['statistik']['offen'] ?? 0) - 1);
        // Ein frisch angelegter Stub zählt als Stub, nicht als Bestands-Treffer —
        // sonst behauptete die Statistik einen Reuse, den es nicht gab. Und er
        // gehört in dieselbe Bringschuld-Liste wie die Stubs aus der Kaskade.
        if (($ergebnis['neu'] ?? false) === true) {
            $this->ergebnis['statistik']['stub_neu'] = (int) ($this->ergebnis['statistik']['stub_neu'] ?? 0) + 1;
            $this->ergebnis['statistik']['stubs'][] = ['id' => (int) $ergebnis['recipe_id'], 'name' => (string) $ergebnis['name']];
        } elseif (($ergebnis['gp_neu'] ?? false) === true) {
            $this->ergebnis['statistik']['gp_neu_aus_la'] = (int) ($this->ergebnis['statistik']['gp_neu_aus_la'] ?? 0) + 1;
        } else {
            $schluessel = ($ergebnis['kind'] ?? null) === 'gp' ? 'bestand_gp' : 'bestand_sub';
            $this->ergebnis['statistik'][$schluessel] = (int) ($this->ergebnis['statistik'][$schluessel] ?? 0) + 1;
        }
        unset($this->hardstopOffenIndex[$index]);

        // #511-Kette: die Zutatenliste des Rezepts hat sich geändert.
        $this->dispatch('recipe-gespeichert');
    }
}
