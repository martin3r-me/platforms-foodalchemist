<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdeaGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * @ai.description Kreativ-Skizzen-Ebene der Foodbook-Leitstelle (Spec 19, E6.2). CRUD über
 * `FoodAlchemistDishIdea` + Paket-Gruppen (`FoodAlchemistDishIdeaGroup`).
 *
 * **Invariante (M4):** Ideen/Gruppen erzeugen NIE Rezepte/GPs/Konzepte — sie sind Entwürfe,
 * erst das Kapitel-Go (E7.3) materialisiert. Deshalb setzt dieser Service `status` nur auf
 * `entwurf|verworfen`; `freigegeben` + `generation_status` + `materialized_*` gehören der
 * Anlage (E7). Die `kiDivergenz()`-Erdung folgt in E6.4.
 *
 * Owner-XOR: jede Idee/Gruppe hängt an GENAU einem von chapter_id/concept_id. Team-Scope wie
 * überall — visibleToTeam beim Lesen, isOwnedBy (übers Owner-Kapitel/-Konzept) beim Schreiben.
 * Vokabular-Pflicht (Entscheidung 6): target_form/status per Model-Const validiert, kein Freitext.
 */
class IdeenService
{
    /**
     * Skizzen eines Owners (Kapitel XOR Konzept), gruppiert für UI (E6.3) und MCP (E6.5):
     * Paket-Gruppen mit ihren Ideen + freie Einzel-Skizzen. Nur nicht-verworfene sind
     * standardmäßig sichtbar; $inklVerworfen holt auch die Papierkorb-Skizzen.
     *
     * @return array{gruppen: list<array{gruppe: FoodAlchemistDishIdeaGroup, ideen: Collection<int, FoodAlchemistDishIdea>}>, einzel: Collection<int, FoodAlchemistDishIdea>}
     */
    public function liste(Team $team, ?int $chapterId = null, ?int $conceptId = null, bool $inklVerworfen = false): array
    {
        [$chapterId, $conceptId] = $this->ownerXor($chapterId, $conceptId);

        $ideenQ = FoodAlchemistDishIdea::visibleToTeam($team)
            ->where('chapter_id', $chapterId)
            ->where('concept_id', $conceptId)
            ->when(! $inklVerworfen, fn ($q) => $q->where('status', '!=', 'verworfen'))
            ->orderBy('position')->orderBy('id');
        $ideen = $ideenQ->get();

        $gruppen = FoodAlchemistDishIdeaGroup::visibleToTeam($team)
            ->where('chapter_id', $chapterId)
            ->where('concept_id', $conceptId)
            ->orderBy('position')->orderBy('id')->get();

        $nachGruppe = $ideen->groupBy(fn ($i) => $i->group_id);

        return [
            'gruppen' => $gruppen->map(fn ($g) => [
                'gruppe' => $g,
                'ideen' => ($nachGruppe->get($g->id) ?? collect())->values(),
            ])->values()->all(),
            'einzel' => ($nachGruppe->get(null) ?? collect())->values(),
        ];
    }

    /**
     * Frei geschriebene Skizze anlegen. Owner-XOR + optionale Paket-Zuordnung; erdet NICHTS.
     * Pflichtfeld ist der Titel (der eigentliche Inhalt der Skizze).
     */
    public function add(Team $team, array $in): FoodAlchemistDishIdea
    {
        $owner = $this->resolveOwner($team, $in);
        $titel = trim((string) ($in['title'] ?? ''));
        if ($titel === '') {
            throw new \RuntimeException('Ideen-Titel ist Pflicht.');
        }

        [$targetForm, $groupId] = $this->normForm($team, $in, $owner);

        return FoodAlchemistDishIdea::create([
            'team_id' => $team->id,
            'chapter_id' => $owner['chapter_id'],
            'concept_id' => $owner['concept_id'],
            'position' => $this->naechstePosition($team, $owner, FoodAlchemistDishIdea::class),
            'title' => $titel,
            'description' => $this->clean($in['description'] ?? null),
            'sales_recipe_id' => null,
            'target_form' => $targetForm,
            'group_id' => $groupId,
            'status' => 'entwurf',
            'created_via' => (string) ($in['created_via'] ?? 'ui'),
            'source_meta' => ['quelle' => 'frei'],
        ]);
    }

    /**
     * Bestands-Übernahme als Skizze: ein echtes VK-Gericht wird als Idee gestempelt
     * (Reuse der `slotVorschlaege`/Picker-Idee). Das Gericht wird NICHT erdet/dupliziert —
     * nur die `sales_recipe_id` referenziert (loser Zeiger). Titel-Default = Gericht-Name.
     */
    public function uebernehmeBestand(Team $team, array $in): FoodAlchemistDishIdea
    {
        $owner = $this->resolveOwner($team, $in);
        $recipe = $this->pruefeBestandGericht($team, isset($in['sales_recipe_id']) ? (int) $in['sales_recipe_id'] : null);

        [$targetForm, $groupId] = $this->normForm($team, $in, $owner);
        $titel = trim((string) ($in['title'] ?? '')) ?: (string) $recipe->name;

        return FoodAlchemistDishIdea::create([
            'team_id' => $team->id,
            'chapter_id' => $owner['chapter_id'],
            'concept_id' => $owner['concept_id'],
            'position' => $this->naechstePosition($team, $owner, FoodAlchemistDishIdea::class),
            'title' => $titel,
            'description' => $this->clean($in['description'] ?? null),
            'sales_recipe_id' => $recipe->id,
            'target_form' => $targetForm,
            'group_id' => $groupId,
            'status' => 'entwurf',
            'created_via' => (string) ($in['created_via'] ?? 'bestand'),
            'source_meta' => ['quelle' => 'bestand', 'sales_recipe_id' => $recipe->id, 'name' => (string) $recipe->name],
        ]);
    }

    /**
     * Inhalt/Form einer Skizze ändern (Titel, Beschreibung, Paket-Zuordnung, Position).
     * NICHT über diesen Pfad: status (→ setStatus), Owner-Wechsel, sales_recipe_id,
     * generation_/materialized_-Felder (E7).
     */
    public function update(Team $team, int $id, array $in): FoodAlchemistDishIdea
    {
        $idee = $this->ownedIdee($team, $id);
        $owner = ['chapter_id' => $idee->chapter_id, 'concept_id' => $idee->concept_id];
        $patch = [];

        if (array_key_exists('title', $in)) {
            $titel = trim((string) $in['title']);
            if ($titel === '') {
                throw new \RuntimeException('Ideen-Titel darf nicht leer sein.');
            }
            $patch['title'] = $titel;
        }
        if (array_key_exists('description', $in)) {
            $patch['description'] = $this->clean($in['description']);
        }
        if (array_key_exists('position', $in)) {
            $patch['position'] = (int) $in['position'];
        }
        if (array_key_exists('target_form', $in) || array_key_exists('group_id', $in)) {
            // Bestehende Werte als Basis, damit ein reiner group_id-Wechsel target_form mitzieht.
            $merged = $in + ['target_form' => $idee->target_form, 'group_id' => $idee->group_id];
            [$patch['target_form'], $patch['group_id']] = $this->normForm($team, $merged, $owner);
        }

        if ($patch !== []) {
            $idee->update($patch);
        }

        return $idee->refresh();
    }

    /**
     * Status-Wechsel — NUR entwurf|verworfen (verwerfen/reaktivieren). `freigegeben` ist dem
     * Kapitel-Go (E7.3) vorbehalten und hier bewusst gesperrt (Invariante).
     */
    public function setStatus(Team $team, int $id, string $status): FoodAlchemistDishIdea
    {
        if (! in_array($status, ['entwurf', 'verworfen'], true)) {
            throw new \RuntimeException("Status «{$status}» ist über die Skizzen-Ebene nicht setzbar (nur entwurf|verworfen).");
        }
        $idee = $this->ownedIdee($team, $id);
        $idee->update(['status' => $status]);

        return $idee->refresh();
    }

    // ── Paket-Gruppen ────────────────────────────────────────────────────────

    /**
     * Paket-Gruppe anlegen (Owner-XOR). `name` wird beim Kapitel-Go (E7.3) zum Konzept-Namen,
     * `target_price_pp` zum €/Gast-Ziel. Erdet NICHTS.
     */
    public function addGruppe(Team $team, array $in): FoodAlchemistDishIdeaGroup
    {
        $owner = $this->resolveOwner($team, $in);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Paket-Name ist Pflicht.');
        }

        return FoodAlchemistDishIdeaGroup::create([
            'team_id' => $team->id,
            'chapter_id' => $owner['chapter_id'],
            'concept_id' => $owner['concept_id'],
            'name' => $name,
            'target_price_pp' => $this->preis($in['target_price_pp'] ?? null),
            'position' => $this->naechstePosition($team, $owner, FoodAlchemistDishIdeaGroup::class),
        ]);
    }

    /** Paket-Gruppe ändern (Name, Zielpreis/Gast, Position). */
    public function updateGruppe(Team $team, int $id, array $in): FoodAlchemistDishIdeaGroup
    {
        $gruppe = $this->ownedGruppe($team, $id);
        $patch = [];

        if (array_key_exists('name', $in)) {
            $name = trim((string) $in['name']);
            if ($name === '') {
                throw new \RuntimeException('Paket-Name darf nicht leer sein.');
            }
            $patch['name'] = $name;
        }
        if (array_key_exists('target_price_pp', $in)) {
            $patch['target_price_pp'] = $this->preis($in['target_price_pp']);
        }
        if (array_key_exists('position', $in)) {
            $patch['position'] = (int) $in['position'];
        }

        if ($patch !== []) {
            $gruppe->update($patch);
        }

        return $gruppe->refresh();
    }

    /**
     * Paket-Gruppe auflösen (E6.3-UI „Paket auflösen"): erst alle Mitglieder lösen (→ Einzel),
     * dann die leere Gruppe soft-löschen. Team-eigen erzwungen (isOwnedBy übers Owner-Kapitel/
     * -Konzept). Die Skizzen selbst bleiben erhalten — nur ihre Paket-Bindung fällt weg.
     */
    public function loescheGruppe(Team $team, int $id): void
    {
        $gruppe = $this->ownedGruppe($team, $id);
        FoodAlchemistDishIdea::where('team_id', $team->id)
            ->where('group_id', $gruppe->id)
            ->update(['group_id' => null, 'target_form' => 'einzel']);
        $gruppe->delete();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Owner-XOR-Guard: exakt eines von chapter_id/concept_id. Gibt normalisiertes
     * [chapter_id, concept_id] (jeweils ?int) zurück.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function ownerXor(?int $chapterId, ?int $conceptId): array
    {
        $hatKapitel = $chapterId !== null && $chapterId > 0;
        $hatKonzept = $conceptId !== null && $conceptId > 0;
        if ($hatKapitel === $hatKonzept) {
            throw new \RuntimeException('Skizze braucht GENAU einen Owner: chapter_id XOR concept_id.');
        }

        return [$hatKapitel ? $chapterId : null, $hatKonzept ? $conceptId : null];
    }

    /**
     * Owner aus Eingabe auflösen + Ownership prüfen (das Owner-Kapitel/-Konzept muss dem
     * Team gehören, nicht nur sichtbar sein — sonst würde man an fremden Katalog schreiben).
     *
     * @return array{chapter_id: ?int, concept_id: ?int}
     */
    private function resolveOwner(Team $team, array $in): array
    {
        [$chapterId, $conceptId] = $this->ownerXor(
            isset($in['chapter_id']) ? (int) $in['chapter_id'] : null,
            isset($in['concept_id']) ? (int) $in['concept_id'] : null,
        );

        if ($chapterId !== null) {
            $k = FoodAlchemistFoodbookKapitel::visibleToTeam($team)->findOrFail($chapterId);
            if (! $k->isOwnedBy($team)) {
                throw new \RuntimeException('Geerbtes Foodbook — Skizzen nur durchs Besitzer-Team (D1).');
            }
        } else {
            $c = FoodAlchemistConcept::visibleToTeam($team)->findOrFail($conceptId);
            if (! $c->isOwnedBy($team)) {
                throw new \RuntimeException('Geerbtes Konzept — Skizzen nur durchs Besitzer-Team (D1).');
            }
        }

        return ['chapter_id' => $chapterId, 'concept_id' => $conceptId];
    }

    /**
     * target_form + group_id normalisieren (Vokabular-Pflicht). Regel M4:
     * paket ⇒ group_id gesetzt; group_id gesetzt ⇒ target_form=paket. Die Gruppe muss
     * team-eigen sein UND denselben Owner tragen (kein Quer-Paket).
     *
     * @return array{0: string, 1: ?int}
     */
    private function normForm(Team $team, array $in, array $owner): array
    {
        $groupId = isset($in['group_id']) && (int) $in['group_id'] > 0 ? (int) $in['group_id'] : null;
        $form = (string) ($in['target_form'] ?? ($groupId !== null ? 'paket' : 'einzel'));

        if (! in_array($form, FoodAlchemistDishIdea::TARGET_FORMS, true)) {
            throw new \RuntimeException("Ungültige Ziel-Form «{$form}» (erlaubt: einzel|paket).");
        }

        if ($groupId !== null) {
            $gruppe = $this->ownedGruppe($team, $groupId);
            if ((int) $gruppe->chapter_id !== (int) $owner['chapter_id'] || (int) $gruppe->concept_id !== (int) $owner['concept_id']) {
                throw new \RuntimeException('Paket-Gruppe gehört zu einem anderen Kapitel/Konzept.');
            }
            $form = 'paket'; // group_id gesetzt ⇒ Paket (Regel M4).
        } elseif ($form === 'paket') {
            throw new \RuntimeException('Paket-Skizze braucht eine group_id.');
        }

        return [$form, $groupId];
    }

    /** Spiegelt pruefeRecipeRef (E1.1): echtes VK-Gericht, sichtbar, keine Slot-Variante. */
    private function pruefeBestandGericht(Team $team, ?int $salesRecipeId): FoodAlchemistRecipe
    {
        if ($salesRecipeId === null) {
            throw new \RuntimeException('Bestands-Übernahme braucht ein sales_recipe_id (VK-Gericht).');
        }
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id')
            ->find($salesRecipeId);
        if ($recipe === null) {
            throw new \RuntimeException("sales_recipe_id {$salesRecipeId} ist kein gültiges, sichtbares VK-Gericht (keine Slot-Variante).");
        }

        return $recipe;
    }

    private function ownedIdee(Team $team, int $id): FoodAlchemistDishIdea
    {
        $idee = FoodAlchemistDishIdea::visibleToTeam($team)->findOrFail($id);
        if (! $idee->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Skizze — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $idee;
    }

    private function ownedGruppe(Team $team, int $id): FoodAlchemistDishIdeaGroup
    {
        $gruppe = FoodAlchemistDishIdeaGroup::visibleToTeam($team)->findOrFail($id);
        if (! $gruppe->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Paket-Gruppe — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $gruppe;
    }

    /**
     * Nächste Position innerhalb des Owners (ans Ende). Owner-gescoped, damit Kapitel- und
     * Konzept-Skizzen voneinander unabhängig zählen.
     *
     * @param  class-string<FoodAlchemistDishIdea|FoodAlchemistDishIdeaGroup>  $model
     */
    private function naechstePosition(Team $team, array $owner, string $model): int
    {
        $max = $model::query()
            ->where('team_id', $team->id)
            ->where('chapter_id', $owner['chapter_id'])
            ->where('concept_id', $owner['concept_id'])
            ->max('position');

        return (int) $max + 1;
    }

    private function clean(mixed $wert): ?string
    {
        $s = trim((string) ($wert ?? ''));

        return $s === '' ? null : $s;
    }

    private function preis(mixed $wert): ?float
    {
        if ($wert === null || $wert === '') {
            return null;
        }

        return round((float) $wert, 2);
    }
}
