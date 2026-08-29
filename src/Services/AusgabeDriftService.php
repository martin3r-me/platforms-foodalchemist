<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistPresentation;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;

/**
 * Ebene 2 · Ausgabe-Drift — das eigentliche Controlling-Loch: die veröffentlichte Kundensicht
 * (Foodbook/Speisekarte/Speiseplan) friert ihre Preise beim Publish ein, während der interne VK
 * per Auto-Aufschlag (Strategie A) mitläuft. So driftet der eingefrorene Kundenpreis unbemerkt von
 * der Kostenrealität weg — und der Auto-Aufschlag maskiert genau das.
 *
 * Zwei Publish-Stores, beide werden geprüft:
 *  - Team-Core (Baseline-Brille): der inline `presentation_snapshot_json` auf dem Doc-Kopf
 *    ({@see \Platform\FoodAlchemist\Models\Concerns\HasPresentation}) → NULL-Lane.
 *  - Betrieb: die betriebs-scopte {@see FoodAlchemistPresentation} (`snapshot_json`) → Betriebs-Lane.
 *
 * Verfahren: EINGEFRORENE Preise ({@see PresentationService::preisPfade}) gegen die AKTUELL live
 * gerechneten derselben Ausgabe ({@see PresentationService::livePreisIndex}) — matched über eine
 * stabile Positions-Identität. Kein Preis-Index-Schema, keine ID-Verdrahtung: derselbe Walker
 * liest beide Seiten. Preislose Ausgaben (Speiseplan ohne `price_display`) liefern nichts →
 * keine Drift → kein Fehlalarm. Republish = neu einfrieren = Drift weg.
 */
class AusgabeDriftService
{
    public function __construct(
        private PresentationService $presentations,
        private TeamSettingsService $settings,
    ) {
    }

    /**
     * Alle gedrifteten Ausgaben EINER Lane. `$outlet=null` = Team-Core (inline Doc-Snapshots),
     * sonst der Betrieb (dessen Präsentationen). Die Leitplanke (`max_vk_delta_pct`) ist eine
     * Team-Policy — die Betriebs-Dimension steckt in den PREISEN, nicht in der Toleranz.
     *
     * @return list<array{ref_type:string, ref_id:int, doc_type:string, doc_id:int, label:string, dedup_key:string, outlet_id:?int, zeilen:list<array{label:?string,frozen:float,live:float,delta_pct:float,richtung:string}>, max_delta_pct:float}>
     */
    public function abgedriftet(Team $team, ?FoodAlchemistOutlet $outlet = null): array
    {
        $schwelle = $this->settings->maxVkDeltaPct($team);
        $out = [];

        foreach ($this->ausgaben($team, $outlet) as $a) {
            $frozen = $this->presentations->preisPfade($a['frozen']);
            if ($frozen === []) {
                continue;   // preislos veröffentlicht (z. B. GV-Aushang) → nichts einzufrieren
            }

            // Live mit denselben Freigabe-Settings + Betriebs-Kontext neu rechnen.
            $liveSettings = (array) ($a['frozen']['settings'] ?? []);
            $liveSettings['price_display'] = true;   // eingefroren WAREN Preise (frozen ≠ leer)
            if ($outlet !== null) {
                $liveSettings['outlet'] = $outlet;
            }
            try {
                $live = $this->presentations->livePreisIndex($team, $a['doc_type'], $a['doc_id'], $liveSettings);
            } catch (\Throwable $e) {
                continue;   // Doc gelöscht/nicht mehr sichtbar → keine Drift-Aussage
            }

            $zeilen = [];
            foreach ($frozen as $key => $f) {
                if (! isset($live[$key])) {
                    continue;   // Struktur geändert (Zeile weg) — kein Preis-Drift, sondern Republish-Grund an anderer Stelle
                }
                $fn = $f['net'];
                $ln = $live[$key]['net'];
                if ($fn <= 0) {
                    continue;
                }
                $delta = abs($ln - $fn) / $fn * 100;
                if ($delta >= $schwelle) {
                    $zeilen[] = [
                        'label' => $f['label'],
                        'frozen' => round($fn, 2),
                        'live' => round($ln, 2),
                        'delta_pct' => round($delta, 1),
                        'richtung' => $ln > $fn ? 'erhoehen' : 'senken',
                    ];
                }
            }

            if ($zeilen !== []) {
                $out[] = [
                    'ref_type' => $a['ref_type'],
                    'ref_id' => $a['ref_id'],
                    'doc_type' => $a['doc_type'],
                    'doc_id' => $a['doc_id'],
                    'label' => $a['label'],
                    'dedup_key' => $a['dedup_key'],
                    'outlet_id' => $outlet?->id,
                    'zeilen' => $zeilen,
                    'max_delta_pct' => max(array_map(fn ($z) => $z['delta_pct'], $zeilen)),
                ];
            }
        }

        return $out;
    }

    /**
     * Die veröffentlichten Ausgaben EINER Lane als einheitliche Zeilen.
     *
     * @return list<array{ref_type:string, ref_id:int, doc_type:string, doc_id:int, label:string, dedup_key:string, frozen:array<string,mixed>}>
     */
    private function ausgaben(Team $team, ?FoodAlchemistOutlet $outlet): array
    {
        if ($outlet !== null) {
            return $this->betriebsAusgaben($team, $outlet);
        }

        return $this->teamCoreAusgaben($team);
    }

    /** Team-Core: der inline-Kopf-Snapshot der drei Doc-Typen (freigegeben + mit Snapshot). */
    private function teamCoreAusgaben(Team $team): array
    {
        $typen = [
            PresentationService::TYPE_FOODBOOK => FoodAlchemistFoodbook::class,
            PresentationService::TYPE_SPEISEKARTE => FoodAlchemistSpeisekarte::class,
            PresentationService::TYPE_SPEISEPLAN => FoodAlchemistSpeiseplan::class,
        ];

        $out = [];
        foreach ($typen as $type => $class) {
            $docs = $class::visibleToTeam($team)
                ->where('presentation_enabled', true)
                ->whereNotNull('presentation_snapshot_json')
                ->get();
            foreach ($docs as $d) {
                $snap = $d->presentation_snapshot_json;
                if (! is_array($snap)) {
                    continue;
                }
                $out[] = [
                    'ref_type' => $type,
                    'ref_id' => (int) $d->id,
                    'doc_type' => $type,
                    'doc_id' => (int) $d->id,
                    'label' => $this->docLabel($d),
                    'dedup_key' => 'ausgabe-drift-core-' . $type . '-' . $d->id,
                    'frozen' => $snap,
                ];
            }
        }

        return $out;
    }

    /** Betrieb: die betriebs-scopten Präsentationen (freigegeben). */
    private function betriebsAusgaben(Team $team, FoodAlchemistOutlet $outlet): array
    {
        $out = [];
        $pres = FoodAlchemistPresentation::where('team_id', $team->id)
            ->where('outlet_id', $outlet->id)
            ->where('enabled', true)
            ->get();
        foreach ($pres as $p) {
            $snap = $p->snapshot_json;
            if (! is_array($snap)) {
                continue;
            }
            $out[] = [
                'ref_type' => 'presentation',
                'ref_id' => (int) $p->id,
                'doc_type' => (string) $p->presentable_type,
                'doc_id' => (int) $p->presentable_id,
                'label' => (string) ($snap['title'] ?? ($p->presentable_type . ' #' . $p->presentable_id)),
                'dedup_key' => 'ausgabe-drift-pres-' . $p->id,
                'frozen' => $snap,
            ];
        }

        return $out;
    }

    private function docLabel(object $d): string
    {
        foreach (['label', 'name', 'code'] as $f) {
            $v = $d->{$f} ?? null;
            if (is_string($v) && trim($v) !== '') {
                return $v;
            }
        }

        return '#' . ($d->id ?? '?');
    }
}
