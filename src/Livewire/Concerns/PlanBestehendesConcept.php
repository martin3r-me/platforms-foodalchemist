<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;

/**
 * Leitstelle, Reiter Concept: ein BESTEHENDES Concept in die Planung nehmen (Dominique 13.11.2026).
 *
 * Foodbook, Angebot und Format hatten die Auswahl „Bestehendes …", das Concept nicht: der geplante Pfad
 * ({@see \Platform\FoodAlchemist\Livewire\Planung\Index::$planConceptId}) entstand nur über „Plan ausarbeiten
 * lassen", und das legt immer ein neues Concept an. Ein von Hand oder per MCP angelegtes Concept (Canvas,
 * Positionen) ließ sich so nicht weiterplanen.
 *
 * Der Lauf selbst kann das längst: {@see \Platform\FoodAlchemist\Services\PlanningCascadeService::starteKaskade}
 * nimmt `existing_concept_id` für jedes team-eigene Concept. Hier entsteht nur der Einstieg; gewählt wird
 * derselbe Zeiger wie beim KI-Kopf (Prop + `plan_concept_id` an der Session), Plan-Panel, „Plan verwerfen"
 * und der Go bleiben unverändert.
 *
 * Eigener Trait, weil Planung\Index auf seiner Größen-Basis steht (Spec 85 §5).
 */
trait PlanBestehendesConcept
{
    /** Höchstens so viele Einträge in der Auswahl — die jüngsten zuerst. */
    private const BESTEHENDE_CONCEPTS_MAX = 200;

    /**
     * Auswahl: eigene Konzepte des Teams (kein Paket, keine Vorlage). Nur team-eigene, weil der Lauf leere
     * Positionen füllt, also schreibt — ein geerbtes Concept ist nur lesbar.
     *
     * @return Collection<int, FoodAlchemistConcept>
     */
    public function bestehendeConcepts(): Collection
    {
        $team = $this->team();
        if ($team === null) {
            return collect();
        }

        return FoodAlchemistConcept::where('team_id', $team->id)->konzepte()->echte()
            ->orderByDesc('id')->limit(self::BESTEHENDE_CONCEPTS_MAX)->get(['id', 'name']);
    }

    /**
     * Ein bestehendes Concept als Plan setzen. Leere Auswahl löst den Plan wieder (wie „Plan verwerfen").
     * Titel und Briefing werden aus dem Concept vorbelegt, wenn sie leer sind: der Lauf braucht einen Auftrag.
     */
    public function bestehendesConceptWaehlen(mixed $wert): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null) {
            $this->fehler = 'Kein Team oder keine Planung offen. Bestehendes Concept nicht wählbar.';

            return;
        }
        $id = is_numeric($wert) ? (int) $wert : 0;
        if ($id <= 0) {
            $this->planVerwerfen();

            return;
        }
        $concept = FoodAlchemistConcept::where('team_id', $team->id)->konzepte()->echte()->find($id);
        if ($concept === null) {
            $this->fehler = 'Das gewählte Concept wurde nicht gefunden oder gehört zu einem anderen Team.';

            return;
        }

        $this->fehler = null;
        $this->planConceptId = (int) $concept->id;
        try {
            $session->update(['plan_concept_id' => $this->planConceptId]);   // übersteht den Reload, wie beim KI-Kopf
        } catch (\Throwable $e) {
            Log::warning('[Planung] plan_concept_id-Persistenz übersprungen (bestehendes Concept bleibt für diese Sitzung gewählt)', ['error' => $e->getMessage()]);
        }
        if (trim((string) ($this->eingabe['concept']['titel'] ?? '')) === '') {
            $this->eingabe['concept']['titel'] = (string) $concept->name;
        }
        if (trim((string) ($this->eingabe['concept']['brief'] ?? '')) === '') {
            $this->eingabe['concept']['brief'] = trim((string) ($concept->brief ?: $concept->description ?: $concept->name));
        }
        $this->ladePlanForm();
        $this->meldung = 'Bestehendes Concept gewählt. Leitidee und Menü-Aufbau prüfen, dann „Concept aus Plan erstellen": gefüllt werden nur die leeren Positionen.';
    }
}
