<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\PreisVerlaufService;

/** Preisverlauf als Stufenlinie je Lieferantenartikel (la_id) oder je Grundprodukt (gp_id: eine Linie je LA) samt linearem Trend. Nur lesend. */
class PreiseVerlaufGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.preise_verlauf.GET';
    }

    public function getDescription(): string
    {
        return 'Preisverlauf als Stufenlinie (Preis gilt bis zum nächsten Stand) aus den Preiszeilen UND der Necta-Historie. '
            .'Mit la_id: eine Linie des Lieferantenartikels; mit gp_id: eine Linie je Lieferantenartikel des Grundprodukts (Lead-LA markiert). '
            .'Preis je Basiseinheit (€/kg, €/l, €/Stk, wie PriceService.vergleichspreis), Rohpreis je Gebinde je Stufe. Artikel mit fremder Basiseinheit werden weggelassen und gezählt (weggelassen). '
            .'Zeitraum: zeitraum 3m|6m|12m|alles (Standard 12m) oder von/bis (Y-m-d). Je Linie ein Trend (lineare Regression auf der Stufenlinie, zeitgewichtet): '
            .'steigung_pro_monat (€ je Basiseinheit und Monat) und prozent_pro_jahr; unter zwei Ständen null. Sichtbarkeit wie überall (Team, Stamm-Freischaltung).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'la_id' => ['type' => 'integer', 'description' => 'Lieferantenartikel'],
            'gp_id' => ['type' => 'integer', 'description' => 'Grundprodukt (eine Linie je LA)'],
            'zeitraum' => ['type' => 'string', 'enum' => ['3m', '6m', '12m', 'alles']],
            'von' => ['type' => 'string', 'description' => 'Y-m-d (mit bis statt zeitraum)'],
            'bis' => ['type' => 'string', 'description' => 'Y-m-d'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $laId = (int) ($arguments['la_id'] ?? 0);
        $gpId = (int) ($arguments['gp_id'] ?? 0);
        if (($laId > 0) === ($gpId > 0)) {
            return ToolResult::error('Genau eines von la_id oder gp_id angeben.', 'VALIDATION_ERROR');
        }
        $svc = app(PreisVerlaufService::class);
        try {
            $zeitraum = isset($arguments['von']) || isset($arguments['bis']) ? 'frei' : (string) ($arguments['zeitraum'] ?? '12m');
            [$von, $bis] = $svc->fenster($zeitraum, $arguments['von'] ?? null, $arguments['bis'] ?? null);
        } catch (\Throwable) {
            return ToolResult::error('von/bis müssen Daten im Format Y-m-d sein.', 'VALIDATION_ERROR');
        }

        if ($gpId > 0) {
            $gp = app(GpService::class)->find($gpId, $team);
            if ($gp === null) {
                return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
            }

            return ToolResult::success($this->ohneIntern($svc->verlaufFuerGp($team, $gp, $von, $bis)) + ['gp_id' => $gpId]);
        }

        $r = $svc->verlauf($team, [$laId], $von, $bis, $laId);
        if ($r['linien'] === [] && $r['weggelassen'] === 0 && $r['ohne_verlauf'] === 0) {
            return ToolResult::error('Lieferantenartikel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success($this->ohneIntern($r) + ['la_id' => $laId]);
    }

    /** Zeitstempel-Felder der Zeichnung gehören nicht in die Antwort (Daten genügen). */
    private function ohneIntern(array $r): array
    {
        foreach ($r['linien'] as &$l) {
            $l['punkte'] = array_map(fn ($s) => ['von' => $s['von'], 'bis' => $s['bis'], 'preis_je_basis' => $s['wert'], 'preis_gebinde' => $s['preis_gebinde'], 'quelle' => $s['quelle']], $l['segmente']);
            unset($l['segmente']);
            if ($l['trend'] !== null) {
                $l['trend'] = ['steigung_pro_monat' => $l['trend']['steigung_pro_monat'], 'prozent_pro_jahr' => $l['trend']['prozent_pro_jahr'], 'mittelwert' => $l['trend']['mittelwert']];
            }
        }

        return $r;
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'preise', 'verlauf', 'trend'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe', 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [], 'related_tools' => ['foodalchemist.gps.GET', 'foodalchemist.suppliers.GET'],
            'examples' => ['Wie hat sich der Preis von Butter in 12 Monaten entwickelt?', 'Preisverlauf von GP 123 seit Januar, mit Trend.'],
        ];
    }
}
