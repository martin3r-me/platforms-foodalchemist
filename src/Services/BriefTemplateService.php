<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistBriefTemplate;
use Platform\FoodAlchemist\Support\TeamScope;
use RuntimeException;

/**
 * Schnellstart-Vorlagen (Brief-Templates): kunden-anlegbare Startpunkte für die Planung-Erzeugung.
 * Eine Vorlage = benannter Schnappschuss (Brief + Kreativ-Modus + kompletter Leitplanken-Stand) je Scope.
 *
 * Sichtbarkeit (D1): kuratierte Globals (team_id NULL) ∪ eigene Team-Kette ({@see TeamScope::applyVisible}).
 * Editierbar (create/rename/toggle/delete): NUR team-eigene ({@see TeamScope::owns}) — Globals sind read-only.
 * Erstellt wird per Snapshot AUS dem Planung-Editor (dort leben die Regler), verwaltet in den Settings.
 */
class BriefTemplateService
{
    private const SCOPES = ['rezept', 'gericht', 'concept'];

    /**
     * Sichtbare, aktive Vorlagen für einen Creation-Tab — Globals zuerst, dann eigene, nach sort_order/Label.
     * Rückgabe keyed by id (String) → das UI-fertige Bündel für Chips + Anwenden.
     *
     * @return array<string,array{id:int,label:string,brief:string,titel:?string,regler:array,creative_mode:?string,is_global:bool}>
     */
    public function fuer(Team $team, string $scope): array
    {
        $rows = TeamScope::applyVisible(
            FoodAlchemistBriefTemplate::query()->where('scope', $scope)->where('active', true),
            'team_id', $team
        )->orderByRaw('team_id IS NULL DESC')->orderBy('sort_order')->orderBy('label')->get();

        $out = [];
        foreach ($rows as $r) {
            $payload = is_array($r->payload) ? $r->payload : [];
            $out[(string) $r->id] = [
                'id' => (int) $r->id,
                'label' => (string) $r->label,
                'brief' => (string) $r->brief,
                'titel' => $r->titel !== null ? (string) $r->titel : null,
                'regler' => is_array($payload['regler'] ?? null) ? $payload['regler'] : [],
                'creative_mode' => isset($payload['creative_mode']) ? (string) $payload['creative_mode'] : null,
                'is_global' => $r->team_id === null,
            ];
        }

        return $out;
    }

    /** Eine sichtbare, aktive Vorlage (Global ∪ eigene) für den Scope laden — null bei nicht sichtbar/falscher Scope. */
    public function lade(Team $team, int $id, string $scope): ?FoodAlchemistBriefTemplate
    {
        return TeamScope::applyVisible(
            FoodAlchemistBriefTemplate::query()->where('scope', $scope)->where('active', true),
            'team_id', $team
        )->find($id);
    }

    /**
     * Team-eigene Vorlage aus einem Editor-Snapshot anlegen: Brief + Kreativ-Modus + der komplette
     * Regler-Stand des Tabs. Der Regler-Snapshot wird as-is gespeichert (Anwenden setzt nur Keys, die
     * der Ziel-Regler-Satz führt — Guard im Anwender, damit ein Scope keine fremden Keys erbt).
     *
     * @param  array<string,mixed>  $regler  aktueller regler[$scope]-Stand
     */
    public function speichere(Team $team, string $scope, string $label, string $brief, array $regler, ?string $titel = null, ?string $creativeMode = null, ?int $userId = null): FoodAlchemistBriefTemplate
    {
        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('Vorlagen-Name ist Pflicht.');
        }
        if (! in_array($scope, self::SCOPES, true)) {
            throw new RuntimeException("Ungültiger Scope „{$scope}“.");
        }
        if (trim($brief) === '') {
            throw new RuntimeException('Ohne Briefing lässt sich keine Vorlage speichern.');
        }

        $payload = ['regler' => $regler];
        if ($creativeMode !== null && trim($creativeMode) !== '') {
            $payload['creative_mode'] = trim($creativeMode);
        }

        return FoodAlchemistBriefTemplate::create([
            'team_id' => $team->id,
            'slug' => $this->uniqueSlug($team, $label),
            'label' => $label,
            'scope' => $scope,
            'titel' => ($titel !== null && trim($titel) !== '') ? trim($titel) : null,
            'brief' => $brief,
            'payload' => $payload,
            'sort_order' => (int) (FoodAlchemistBriefTemplate::where('team_id', $team->id)->max('sort_order') ?? 0) + 10,
            'active' => true,
            'created_by' => $userId,
        ]);
    }

    public function umbenennen(Team $team, int $id, string $label): FoodAlchemistBriefTemplate
    {
        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('Vorlagen-Name ist Pflicht.');
        }
        $tpl = $this->eigene($team, $id);
        $tpl->update(['label' => $label]);

        return $tpl;
    }

    public function toggleActive(Team $team, int $id): FoodAlchemistBriefTemplate
    {
        $tpl = $this->eigene($team, $id);
        $tpl->update(['active' => ! $tpl->active]);

        return $tpl;
    }

    public function loeschen(Team $team, int $id): void
    {
        $this->eigene($team, $id)->delete();
    }

    /** Team-eigene Vorlage per id — wirft, wenn nicht vorhanden ODER kuratiert/geerbt (Owns-Guard). */
    private function eigene(Team $team, int $id): FoodAlchemistBriefTemplate
    {
        $tpl = FoodAlchemistBriefTemplate::find($id);
        if ($tpl === null || ! TeamScope::owns($tpl->team_id, $team)) {
            throw new RuntimeException('Nur eigene Vorlagen sind bearbeitbar (kuratierte sind read-only).');
        }

        return $tpl;
    }

    /** Team-scoped eindeutiger Slug aus dem Label (Kollision → -2/-3 …). */
    private function uniqueSlug(Team $team, string $label): string
    {
        $base = Str::slug($label, '_') ?: 'vorlage';
        $slug = $base;
        for ($i = 2; FoodAlchemistBriefTemplate::where('team_id', $team->id)->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'_'.$i;
        }

        return $slug;
    }
}
