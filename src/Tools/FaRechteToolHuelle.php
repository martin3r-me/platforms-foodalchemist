<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\FaRechte;

/**
 * Spec 77a · MCP-Grundprüfung für ALLE FA-Tools an einer Stelle: beim Registrieren wird jedes Tool in
 * diese Hülle gelegt. Schreibende Tools (`read_only = false`) verlangen mindestens Kuratieren, lesende
 * laufen für jede Rolle. Ohne Benutzer im Kontext (System) keine Prüfung. Höhere Stufen (Freigeben,
 * Admin) prüfen die Services selbst. Die KI kann so nie mehr als ihr Benutzer (Spec 61 §5.3).
 *
 * Die Hülle reicht Name, Beschreibung, Schema und Metadaten unverändert durch.
 */
class FaRechteToolHuelle implements ToolContract, ToolMetadataContract
{
    /**
     * Schreibende Tools, die trotzdem jede Rolle nutzen darf: sie ändern nur die eigene Ansicht
     * des Benutzers (z. B. Betriebs-Brille), keine Team-Daten.
     */
    public const FUER_JEDE_ROLLE = ['foodalchemist.outlets.SET_ACTIVE'];

    public function __construct(private readonly ToolContract $tool) {}

    public function innen(): ToolContract
    {
        return $this->tool;
    }

    public function getName(): string
    {
        return $this->tool->getName();
    }

    public function getDescription(): string
    {
        return $this->tool->getDescription();
    }

    public function getSchema(): array
    {
        return $this->tool->getSchema();
    }

    public function getMetadata(): array
    {
        return $this->tool instanceof ToolMetadataContract ? $this->tool->getMetadata() : [];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $user = $context->user;
        if ($user instanceof User && ! ($this->getMetadata()['read_only'] ?? false) && ! in_array($this->getName(), self::FUER_JEDE_ROLLE, true)) {
            $team = $context->team instanceof Team ? $context->team : ($user->currentTeamRelation ?? null);
            if ($team instanceof Team && ! app(FaRechte::class)->darf($user, $team, FaRolle::Kuratieren)) {
                $rolle = app(FaRechte::class)->rolle($user, $team);

                return ToolResult::error('Dieses Tool schreibt und braucht mindestens die Rolle „Kuratieren“ (Mitglied). Deine Rolle: „'
                    .$rolle->label().'“. Die Rolle pflegt ein Team-Admin in den Team-Einstellungen der Plattform.', 'FORBIDDEN');
            }
        }

        return $this->tool->execute($arguments, $context);
    }
}
