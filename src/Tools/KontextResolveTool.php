<?php

namespace Platform\FoodAlchemist\Tools;

use Carbon\Carbon;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\KontextResolver;
use Platform\FoodAlchemist\Support\KontextSicht;
use Platform\FoodAlchemist\Support\MachbarkeitKontext;

/**
 * Spec 82 §7 · Schritt 6 — KI-Marken-Kontext LESEN, genau so wie ihn die Erzeugung bekommt: verbindliche Vorgaben,
 * Team-DNA (feldweise geerbt), Teamprofil, Endkunde (nur mit CRM-Firma), Angebot/Foodbook/Concept, Tonalität, mit
 * Quellenliste und Budget-Bilanz. Schreibt nichts.
 *
 * Sichtbarkeit (§13 G): nur für Teams, die der Aufrufer sehen darf (eigenes Team, Unter-Teams über das Oberteam,
 * kuratierte Mandanten für das Master-Team). Bei autarken Mandanten gibt es auch für den Plattform-Admin keinen Inhalt.
 */
class KontextResolveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.kontext.RESOLVE';
    }

    public function getDescription(): string
    {
        return 'Liest den KI-Marken-Kontext, den eine Erzeugung bekommt (Reihenfolge: verbindliche Vorgaben → Team-DNA → Teamprofil → '
            .'Endkunde → Angebot → Foodbook → Concept → Tonalität; mit projekt_id zusätzlich Standort und Projekt-Akte, Kunden-Bleibt steht dann vorn und hat Vorrang), mit Quellenliste und Budget-Bilanz (gekürzt/verworfen). '
            .'Optional ein Objekt (foodbook|angebot|concept|speisekarte + objekt_id), Ziel-Team (team_id), Gäste und Termin. '
            .'Nur lesend. Fremde oder autarke Mandanten: FORBIDDEN, auch für Plattform-Admins.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'team_id' => ['type' => 'integer', 'description' => 'Ziel-Team (optional, Standard: eigenes Team). Unter-Team des eigenen Teams oder kuratierter Mandant (Master).'],
                'objekt_typ' => ['type' => 'string', 'enum' => ['foodbook', 'angebot', 'concept', 'speisekarte']],
                'objekt_id' => ['type' => 'integer', 'description' => 'Id des Objekts im Ziel-Team (mit objekt_typ).'],
                'projekt_id' => ['type' => 'integer', 'description' => 'Optional, nur Master-Team (Agentur-Akte, Spec 82 §9): Projekt. Fügt Standort (Pilot oder standort_id), Kunde (Bleibt vorn, Vorrang vor dem Team) und Projekt-Block hinzu.'],
                'standort_id' => ['type' => 'integer', 'description' => 'Optional mit projekt_id: Kunden-Standort statt Pilot.'],
                'gaeste' => ['type' => 'integer', 'description' => 'Optional: Gästezahl für die Anlass-Zeile.'],
                'termin' => ['type' => 'string', 'description' => 'Optional: Termin (YYYY-MM-DD).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ziel = ! empty($arguments['team_id']) ? Team::find((int) $arguments['team_id']) : $team;
        if ($ziel === null || ! KontextSicht::darf($team, $ziel)) {
            // gleiche Antwort für „gibt es nicht“ und „darfst du nicht sehen“ — kein Rückschluss auf fremde Teams
            return ToolResult::error('Team nicht sichtbar.', 'FORBIDDEN');
        }

        $objekt = null;
        if (! empty($arguments['objekt_typ'])) {
            $klasse = match ((string) $arguments['objekt_typ']) {
                'foodbook' => FoodAlchemistFoodbook::class,
                'angebot' => FoodAlchemistAngebot::class,
                'concept' => FoodAlchemistConcept::class,
                'speisekarte' => FoodAlchemistSpeisekarte::class,
                default => null,
            };
            if ($klasse === null || empty($arguments['objekt_id'])) {
                return ToolResult::error('objekt_typ braucht objekt_id (foodbook|angebot|concept|speisekarte).', 'VALIDATION_ERROR');
            }
            $objekt = $klasse::visibleToTeam($ziel)->whereKey((int) $arguments['objekt_id'])->first();
            if ($objekt === null) {
                return ToolResult::error(ucfirst((string) $arguments['objekt_typ']).' nicht gefunden.', 'NOT_FOUND');
            }
        }
        $termin = null;
        if (! empty($arguments['termin'])) {
            try {
                $termin = Carbon::parse((string) $arguments['termin']);
            } catch (\Throwable) {
                return ToolResult::error('termin: Datum im Format YYYY-MM-DD.', 'VALIDATION_ERROR');
            }
        }
        $ausfuehrung = new MachbarkeitKontext($ziel, $context->user, isset($arguments['gaeste']) ? (int) $arguments['gaeste'] : null, $termin);
        $resolver = app(KontextResolver::class);
        $ids = [];
        if ($objekt === null && ! empty($arguments['projekt_id'])) {
            $projekt = \Platform\FoodAlchemist\Models\FoodAlchemistProjekt::where('team_id', $ziel->id)->find((int) $arguments['projekt_id']);
            if ($projekt === null) {
                return ToolResult::error('Projekt nicht gefunden (nur im Master-Team).', 'NOT_FOUND');
            }
            $ids = ['projekt_id' => (int) $projekt->id, 'crm_company_id' => (int) $projekt->crm_company_id, 'standort_id' => ! empty($arguments['standort_id']) ? (int) $arguments['standort_id'] : null];
        }
        $r = $objekt !== null ? $resolver->fuerObjekt($ziel, $objekt, $ausfuehrung) : $resolver->fuer($ziel, $ids, $ausfuehrung);

        return ToolResult::success(['team_id' => (int) $ziel->id] + $r);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'kontext', 'dna', 'marken-kontext', 'budget'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.canvas.GET', 'foodalchemist.canvas.PUT', 'foodalchemist.team_profile.GET'],
            'examples' => ['Welchen Marken-Kontext bekommt die Erzeugung für Foodbook 12?', 'Was ist bei LAGO verbindlich vorgegeben, und wurde etwas gekürzt?'],
        ];
    }
}
