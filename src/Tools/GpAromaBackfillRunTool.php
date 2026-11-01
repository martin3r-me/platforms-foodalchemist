<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpAromaBackfillService;
use Platform\FoodAlchemist\Support\KiUmleitung;

/**
 * #1002: Kern-Anker + Geschmacksprofil für GPs mit Lücke nachziehen — DIESELBE Logik wie der Artisan-Befehl `foodalchemist:gp-aroma-backfill`
 * (gemeinsamer {@see GpAromaBackfillService}, kein Nachbau). Per MCP läuft die KI immer extern: der Lauf legt KI-Aufträge an, der Client
 * beantwortet sie (ki_auftraege.GET → ANTWORT) und ruft das Tool erneut auf. Jedes Team bearbeitet NUR seine pflegbaren GPs (team-eigen);
 * das Master-Team zusätzlich die globalen Stamm-GPs. Ohne `apply` ist es ein Trockenlauf (zählt, schreibt nichts).
 */
class GpAromaBackfillRunTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public const LIMIT_STANDARD = 50;

    public const LIMIT_MAX = 300;

    public function getName(): string
    {
        return 'foodalchemist.gp_aroma_backfill.RUN';
    }

    public function getDescription(): string
    {
        return 'Zieht Kern-Anker und Geschmacksprofil für GPs mit Lücke nach (nur die pflegbaren GPs des eigenen Teams; das Master-Team zusätzlich die globalen '
            . 'Stamm-GPs). Erst der kostenlose Geschwister-Teil (gleiche Hauptzutat), dann die KI — per MCP immer über KI-AUFTRÄGE: ki_auftraege.GET holen, '
            . 'ki_auftraege.ANTWORT beantworten, dieses Tool erneut aufrufen (beantwortete Aufträge werden dabei übernommen). Ohne apply=true ein '
            . 'Trockenlauf (Zählung + Kostenschätzung, schreibt nichts). Nur Lücken: ein vorhandenes Profil/ein Anker wird nie neu bezahlt, ein manuelles '
            . 'Profil nie angefasst. Keine Lücke sind: die Anker-Antwort neutral (kein Aroma-Träger, wird festgehalten), ein Profil mit lauter Nullen von '
            . 'Hand/KI/Geschwistern und Non-Food (Warengruppe 16, wird nie gefragt). limit begrenzt die KI-Calls je Aufruf (Standard 50, max 300). Antwort: Zählung, Auftrags-Zähler (angelegt/beantwortet/'
            . 'verbraucht/doppelt/verwaist) und der Hinweis zur Schleife.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nur' => ['type' => 'string', 'enum' => ['anker', 'profil', 'beide'], 'default' => 'beide', 'description' => 'Was nachgezogen wird.'],
                'nur_geschwister' => ['type' => 'boolean', 'default' => false, 'description' => 'Nur den kostenlosen Geschwister-Teil, keine KI.'],
                'neu_schaetzen' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['ai', 'geschwister', 'gemini', 'rule', 'anker_neutral']],
                    'description' => 'Vorhandene Profile dieser Quellen neu schätzen und überschreiben (manuell wird nie angefasst). anker_neutral = die Anker-Frage '
                        . 'für GPs mit „geprüft, kein Anker" erneut stellen (sie zählen sonst nicht als Lücke).'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::LIMIT_MAX, 'default' => self::LIMIT_STANDARD,
                    'description' => 'Höchstens so viele GPs je Aufruf in den KI-Teil (Standard ' . self::LIMIT_STANDARD . ', max ' . self::LIMIT_MAX . ').'],
                'apply' => ['type' => 'boolean', 'default' => false, 'description' => 'true = schreiben (Geschwister) und KI-Aufträge anlegen; false = Trockenlauf.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (! $context->user instanceof \Platform\Core\Models\User) {
            return ToolResult::error('Kein unterstützter Nutzer im Kontext.', 'NO_USER');
        }
        $nur = (string) ($arguments['nur'] ?? 'beide');
        if (! in_array($nur, ['anker', 'profil', 'beide'], true)) {
            return ToolResult::error('nur muss anker, profil oder beide sein.', 'VALIDATION_ERROR');
        }
        $neu = array_values(array_unique(array_map('strval', (array) ($arguments['neu_schaetzen'] ?? []))));
        if (array_diff($neu, ['ai', 'geschwister', 'gemini', 'rule', 'anker_neutral']) !== []) {
            return ToolResult::error('neu_schaetzen erlaubt nur ai, geschwister, gemini, rule, anker_neutral (ein manuelles Profil wird nie neu geschätzt).', 'VALIDATION_ERROR');
        }
        $limit = (int) ($arguments['limit'] ?? self::LIMIT_STANDARD);
        if ($limit < 1 || $limit > self::LIMIT_MAX) {
            return ToolResult::error('limit muss zwischen 1 und ' . self::LIMIT_MAX . ' liegen.', 'VALIDATION_ERROR');
        }
        $apply = ($arguments['apply'] ?? false) === true;
        $extern = KiUmleitung::externFuerLauf($context);   // MCP-Herkunft ∧ Schalter → KI-Aufträge; sonst Plattform-Provider (interner Weg)

        try {
            $r = app(GpAromaBackfillService::class)->lauf($team, $context->user, [
                'fuer_team' => true, 'nur' => $nur, 'apply' => $apply, 'nur_geschwister' => ($arguments['nur_geschwister'] ?? false) === true,
                'limit' => $limit, 'extern' => $extern, 'neu_quellen' => $neu, 'chunk' => 25, 'pause' => 0,
            ]);
        } catch (\Platform\FoodAlchemist\Exceptions\KiDeaktiviertException) {
            return ToolResult::error('KI ist für dieses Team deaktiviert — der KI-Teil braucht sie. Mit nur_geschwister=true läuft der kostenlose Teil.', 'KI_DEAKTIVIERT');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        if ($r['pflegbar_gesamt'] === 0 && $r['neu'] === null) {
            return ToolResult::success(['hinweis' => 'Keine pflegbaren GPs mit Lücke für dieses Team — es ist nichts nachzuziehen.', 'apply' => $apply,
                'luecken' => $r['luecken'], 'pflegbare_gps_mit_luecke' => 0]);
        }
        $ki = $r['ki'];
        $z = $ki['zaehler'] ?? null;

        return ToolResult::success([
            'apply' => $apply, 'extern' => $extern, 'limit' => $limit,
            'luecken' => $r['luecken'],
            'geschwister' => $r['geschwister'],
            'ki_geplant' => $r['ki_geplant'],
            'ki_calls_dieser_lauf' => $r['ki_calls'],
            'kosten_schaetzung' => ['eur' => $r['kosten']['eur_unbekannt'] ? null : round($r['kosten']['eur'], 2), 'tokens_in' => (int) $r['kosten']['tin'], 'tokens_out' => (int) $r['kosten']['tout'], 'basis' => $r['kosten']['basis']],
            'geschwister_uebernommen' => $apply ? $r['geschrieben'] : null,
            'ki_teil' => $ki === null ? null : [
                'bearbeitet' => $ki['bearbeitet'], 'schritte_wartend' => $ki['ausstehend'], 'uebernommen' => $ki['uebernommen'], 'offene_auftraege' => $ki['offene_auftraege'],
                'zaehler' => $z,
            ],
            'warnungen' => $r['warnungen'],
            'hinweis' => ! $apply
                ? 'Trockenlauf — nichts geschrieben. Mit apply=true starten.'
                : ($extern ? 'Extern: offene KI-Aufträge mit foodalchemist.ki_auftraege.GET holen, mit ki_auftraege.ANTWORT beantworten und dieses Tool erneut aufrufen — beantwortete Aufträge werden dabei übernommen. '
                    . 'Zähler „doppelt“ > 0 heißt: der Fingerabdruck ist instabil.' : 'Plattform-Provider (interner Aufruf).'),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gp', 'aroma', 'sensorik', 'anker', 'backfill', 'ki', 'extern', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.ki_auftraege.GET', 'foodalchemist.ki_auftraege.ANTWORT', 'foodalchemist.gps.ENRICH'],
            'examples' => ['Trockenlauf für Anker und Profil meiner GPs.', 'Zieh 50 Profile nach (apply=true), beantworte die Aufträge und wiederhole.'],
        ];
    }
}
