<?php

namespace Platform\FoodAlchemist\Services\Conformance;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\GpStatus;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\GpService;

/**
 * Konformitäts-Adapter für Grundprodukte (GP) — prüft gegen das Regelwerk Grundprodukte
 * (§3 Warengruppen, §6 Benennungsschema/Singular, §8 Pflichtangaben, §9 Zustand, §11 Derivate).
 *
 * v1 OHNE Selbstheilung: GP hat keinen Freitext-Revise wie ein Rezept — §6-Naming/§8-
 * Pflichtangaben zu korrigieren ist eine gezielte Feld-Änderung, kein Umschreiben. Bis
 * ein GP-Feld-Revise steht, meldet der Critic Verstöße nur als Hinweis (unterstuetztHeilung=false).
 */
class GpConformanceAdapter implements ConformanceAdapter
{
    public function artifactType(): string
    {
        return 'gp';
    }

    public function unterstuetztHeilung(): bool
    {
        return true;   // LA-First-Re-Derive (Slice 5) — heilt tentative GPs aus dem Quell-LA
    }

    public function pruefauftrag(Team $team, int $id): array
    {
        $gp = app(GpService::class)->find($id, $team);
        if ($gp === null) {
            throw new \RuntimeException('Grundprodukt nicht gefunden oder nicht sichtbar.');
        }

        $kontext = [
            'artefakt_typ' => 'Grundprodukt (GP)',
            'name' => $gp->name,
            'warengruppe' => $gp->commodity_group_code,          // §3
            'sub_kategorie' => $gp->sub_category,
            'zustand' => $gp->condition,                          // §9 frisch|TK|trocken|konserviert
            'bio' => $gp->bio,
            'verarbeitung' => $gp->processing,
            'form' => $gp->form,
            'ist_derivat' => (bool) $gp->is_derivat,              // §11
            'ist_platzhalter' => (bool) $gp->is_platzhalter,     // Platzhalter: §3/§8 bewusst ausgenommen
        ];

        // LA-First-Erdung: das GP wird AUS seinem Lead-Lieferantenartikel abgeleitet, nicht umgekehrt.
        // Das Quell-LA mitgeben, damit der Critic Ableitungs-Verluste sieht (eine am GP fehlende
        // Pflichtangabe, die im LA belegt ist) — und beim Heilen die konformen Werte AUS dem LA
        // ableiten kann statt zu raten (keine Erfindungen). `leadLa.supplier` ist über GpService::find
        // schon eager geladen.
        $la = $gp->leadLa;
        if ($la !== null) {
            $kontext['quell_lieferantenartikel'] = [
                'hinweis' => 'GP wird aus diesem LA abgeleitet (LA-First). Prüfe die GP-Felder gegen diese Quelle: '
                    . 'fehlt am GP eine §-Pflichtangabe, die hier belegt ist, ist das ein Ableitungs-Verlust (Verstoß). '
                    . 'Was das LA NICHT hergibt, ist am GP kein Erfindungs-Anlass.',
                'bezeichnung' => $la->designation,
                'regulierter_name' => $la->regulated_name,
                'marketing_name' => $la->marketing_name,
                'marke' => $la->brand,
                'hersteller' => $la->manufacturer,
                'herkunft' => $la->origin,
                'lieferant' => $la->supplier?->name,
                'bio' => $la->is_organic,
                'vegan' => $la->is_vegan,
                'vegetarisch' => $la->is_vegetarian,
                'alkohol' => $la->is_alcohol,
            ];
        }

        return [
            'kontext' => $kontext,
            'regelwerk_praefixe' => ['regelwerk-gp-'],
            'target_table' => 'foodalchemist_gps',
        ];
    }

    /**
     * LA-First-Selbstheilung (Slice 5): das GP aus seinem Quell-LA §-konform NEU ableiten —
     * kein Erfinden, kein Freitext-Rewrite. Nur TENTATIVE GPs (approved = Mensch entscheidet in
     * der Review-Queue; zudem gibt es keine per-Feld-Provenienz, die eine manuelle Kuratierung
     * schützt). Der LLM gibt nur aus dem LA ABLEITBARE Werte zurück (Rest null); angewendet über
     * den §6-validierten {@see GpNamingService::updateGp} (gp_key bleibt stabil). Best-effort.
     */
    public function revise(Team $team, int $id, string $direktive): void
    {
        $gp = app(GpService::class)->find($id, $team);
        if ($gp === null || $gp->status !== GpStatus::Tentative) {
            return;                                              // nur tentative GPs autonom heilen
        }
        $la = $gp->leadLa;

        $vorschlag = app(AiGatewayService::class)->propose('gp.conformance_revise', [
            'verstoesse' => $direktive,
            'gp' => [
                'name' => $gp->name,
                'warengruppe' => $gp->commodity_group_code,
                'zustand' => $gp->condition,
                'sub_kategorie' => $gp->sub_category,
                'bio' => $gp->bio,
            ],
            'quell_lieferantenartikel' => $la === null ? null : [
                'bezeichnung' => $la->designation,
                'regulierter_name' => $la->regulated_name,
                'marketing_name' => $la->marketing_name,
                'marke' => $la->brand,
                'hersteller' => $la->manufacturer,
                'herkunft' => $la->origin,
                'bio' => $la->is_organic,
                'vegan' => $la->is_vegan,
                'vegetarisch' => $la->is_vegetarian,
                'alkohol' => $la->is_alcohol,
            ],
        ]);

        $werte = $vorschlag->werte;
        $neuName = trim((string) ($werte['name'] ?? ''));
        $neuZustand = trim((string) ($werte['zustand'] ?? ''));
        $neuWg = trim((string) ($werte['warengruppe'] ?? ''));
        $neuSub = trim((string) ($werte['sub_kategorie'] ?? ''));

        // Nichts aus dem LA ableitbar → kein Write, der Verstoß bleibt Hinweis.
        if ($neuName === '' && $neuZustand === '' && $neuWg === '' && $neuSub === '') {
            return;
        }

        try {
            // name IMMER mitgeben (sonst rendert updateGp aus unvollständigen Teilen einen Fehlnamen);
            // leere condition/warengruppe/sub_category → updateGp behält den Bestand.
            app(GpNamingService::class)->updateGp($team, $gp, [
                'name' => $neuName !== '' ? $neuName : $gp->name,
                'condition' => $neuZustand ?: null,
                'commodity_group_code' => $neuWg ?: null,
                'sub_category' => $neuSub ?: null,
            ]);
        } catch (\Throwable $e) {
            // §6-Validierung schlug fehl o. ä. → best-effort, der Verstoß bleibt Hinweis.
        }
    }
}
