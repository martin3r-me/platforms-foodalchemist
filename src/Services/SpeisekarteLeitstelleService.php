<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;

/**
 * Speisekarte-Leitstelle (read-only, Spiegel LeitstelleService): abgeleitetes Cockpit
 * über den Karten-Zustand — eine Checkliste „ist die Karte fertig?" (Rubriken, Positionen,
 * Preise vollständig, Allergene bekannt, Branding) + Preis-Ampel je Position. Schreibt nie.
 */
class SpeisekarteLeitstelleService
{
    public function __construct(private SpeisekarteService $karten)
    {
    }

    /** Schwache Allergen-Konfidenz zählt als „unbekannt" für die Checkliste. */
    private const SCHWACHE_KONF = ['low', 'unbekannt', ''];

    /**
     * Abgeleitete Fertigstellungs-Checkliste. Jeder Punkt: status ∈ offen|teil|erledigt.
     *
     * @return array{punkte: list<array{key:string,label:string,status:string,hinweis:string}>, bereit: bool}
     */
    public function checkliste(Team $team, int $karteId): array
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)
            ->with(['sections.items.dish', 'sections.items.concept'])
            ->findOrFail($karteId);

        $rubriken = $karte->sections;
        $positionen = $rubriken->flatMap->items->filter(fn ($p) => in_array($p->type, ['gericht_ref', 'menue_ref'], true));

        // 1) Rubriken
        $pRubriken = $rubriken->isEmpty() ? 'offen' : 'erledigt';

        // 2) Positionen
        $pPositionen = $positionen->isEmpty() ? 'offen' : 'erledigt';

        // 3) Preise vollständig
        $ohnePreis = $positionen->filter(fn ($p) => ($this->karten->positionPreis($p)['vk'] ?? null) === null)->count();
        $pPreise = match (true) {
            $positionen->isEmpty() => 'offen',
            $ohnePreis === 0 => 'erledigt',
            $ohnePreis < $positionen->count() => 'teil',
            default => 'offen',
        };

        // 4) Allergene bekannt (nur gericht_ref-Gerichte)
        $gerichte = $positionen->where('type', 'gericht_ref')->map->dish->filter();
        $unbekannt = $gerichte->filter(fn ($g) => in_array((string) ($g->allergens_confidence ?? ''), self::SCHWACHE_KONF, true))->count();
        $pAllergene = match (true) {
            $gerichte->isEmpty() => 'offen',
            $unbekannt === 0 => 'erledigt',
            $unbekannt < $gerichte->count() => 'teil',
            default => 'offen',
        };

        // 5) Branding
        $hatBranding = ($karte->logo_path || $karte->footer_text || ($karte->brand_color && $karte->brand_color !== '#6d28d9'));
        $pBranding = $hatBranding ? 'erledigt' : 'offen';

        $punkte = [
            ['key' => 'rubriken', 'label' => 'Rubriken angelegt', 'status' => $pRubriken,
                'hinweis' => $rubriken->count() . ' Rubrik(en)'],
            ['key' => 'positionen', 'label' => 'Positionen befüllt', 'status' => $pPositionen,
                'hinweis' => $positionen->count() . ' Gericht/Menü'],
            ['key' => 'preise', 'label' => 'Preise vollständig', 'status' => $pPreise,
                'hinweis' => $ohnePreis > 0 ? "{$ohnePreis} ohne Preis" : 'alle bepreist'],
            ['key' => 'allergene', 'label' => 'Allergene bekannt', 'status' => $pAllergene,
                'hinweis' => $unbekannt > 0 ? "{$unbekannt} unklar" : 'geerdet'],
            ['key' => 'branding', 'label' => 'Branding gesetzt', 'status' => $pBranding,
                'hinweis' => $hatBranding ? 'Farbe/Logo/Footer' : 'Standard'],
        ];

        // „Bereit" = die harten Punkte (Rubriken/Positionen/Preise/Allergene) sind erledigt.
        $hart = ['rubriken', 'positionen', 'preise', 'allergene'];
        $bereit = collect($punkte)->whereIn('key', $hart)->every(fn ($p) => $p['status'] === 'erledigt');

        return ['punkte' => $punkte, 'bereit' => $bereit];
    }

    /**
     * Preis-Ampel je Position: gruen (Preis da), rot (kein Preis). Für die Editor-Sicht.
     *
     * @return array<int, array{status:string, vk:?float, quelle:string}>
     */
    public function preisAmpeln(Team $team, int $karteId): array
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)
            ->with(['sections.items.dish', 'sections.items.concept'])
            ->findOrFail($karteId);

        $out = [];
        foreach ($karte->sections as $rubrik) {
            foreach ($rubrik->items as $pos) {
                if (! in_array($pos->type, ['gericht_ref', 'menue_ref'], true)) {
                    continue;
                }
                $preis = $this->karten->positionPreis($pos);
                $out[$pos->id] = [
                    'status' => $preis['vk'] !== null ? 'gruen' : 'rot',
                    'vk' => $preis['vk'],
                    'quelle' => $preis['quelle'],
                ];
            }
        }

        return $out;
    }
}
