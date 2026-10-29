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
    public const FUER_JEDE_ROLLE = ['foodalchemist.outlets.SET_ACTIVE', 'foodalchemist.standorte.SET_BRILLE'];

    private ?ToolContract $tool;

    /** Spec 84 E-4: verzögert gebaute Hülle — Klasse + gecachter Name/Metadaten, das Tool selbst erst bei Bedarf. */
    private ?string $klasse = null;

    private ?string $name = null;

    private ?array $metadaten = null;

    public function __construct(ToolContract $tool)
    {
        $this->tool = $tool;
    }

    /**
     * Spec 84 E-4 · Hülle ohne das Tool zu bauen: Name und Metadaten kommen aus dem Scan-Cache, Beschreibung, Schema und
     * Ausführung bauen das Tool beim ersten Zugriff (Schema darf Config lesen, darum nicht gecacht). Spart je Request
     * das Laden und Instanziieren aller FA-Tools.
     *
     * @param  class-string<ToolContract>  $klasse
     */
    public static function verzoegert(string $klasse, string $name, array $metadaten): self
    {
        $h = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $h->tool = null;
        $h->klasse = $klasse;
        $h->name = $name;
        $h->metadaten = $metadaten;

        return $h;
    }

    public function innen(): ToolContract
    {
        return $this->tool ??= new ($this->klasse)();
    }

    public function getName(): string
    {
        return $this->name ?? $this->innen()->getName();
    }

    public function getDescription(): string
    {
        return $this->innen()->getDescription();
    }

    public function getSchema(): array
    {
        return $this->innen()->getSchema();
    }

    public function getMetadata(): array
    {
        if ($this->metadaten !== null) {
            return $this->metadaten;
        }
        $t = $this->innen();

        return $t instanceof ToolMetadataContract ? $t->getMetadata() : [];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        // Spec 84 §3.3 (A-1): jedes FA-Tool läuft im Team seines Aufrufs — FaTeam::aktuell() und jeder darin versandte Job sehen
        // dieses Team, auch nach core.team.switch. imTeam stellt danach den vorigen Stand wieder her (Tool-Loop: kein Nachhängen).
        // Spec 84 E-1: Mess-Abschnitt INNEN, mit der Team-ID aus FaTeam — eine Wahrheit für Messung, Riegel und Abrechnung.
        $user = $context->user;
        $kTeam = $context->team instanceof Team ? $context->team : ($user instanceof User ? ($user->currentTeamRelation ?? null) : null);
        $messen = fn () => \Platform\FoodAlchemist\Support\LangsameAbfragen::imEingang(
            'mcp:' . $this->getName(),
            \Platform\FoodAlchemist\Support\FaTeam::aktuellId(),
            // Spec 84 B3: MCP-Tool = Eingang; mit Nutzer ist fehlender Team-Kontext ein Fehler
            fn () => \Platform\FoodAlchemist\Models\Scopes\TeamSicht::imEingang('mcp', $user instanceof User, fn () => $this->ausfuehren($arguments, $context)),
        );

        return $kTeam instanceof Team ? \Platform\FoodAlchemist\Support\FaTeam::imTeam($kTeam, $messen) : $messen();
    }

    private function ausfuehren(array $arguments, ToolContext $context): ToolResult
    {
        $user = $context->user;
        // Spec 82 §13 C3: Vollsperre eines beendeten Mandanten — jedes FA-Tool FORBIDDEN außer Export und Plattform-Steuerung,
        // auch für Plattform-Admins (keine Inhalts-Ausnahme, §13 G)
        if ($user instanceof User && ! \Platform\FoodAlchemist\Support\TeamSperre::toolErlaubt($this->getName())) {
            $sTeam = $context->team instanceof Team ? $context->team : ($user->currentTeamRelation ?? null);
            if ($sTeam instanceof Team && \Platform\FoodAlchemist\Support\TeamSperre::istGesperrt($sTeam)) {
                return ToolResult::error(\Platform\FoodAlchemist\Support\TeamSperre::MELDUNG, 'FORBIDDEN');
            }
        }
        // Spec 82 §13 G: Plattform-Admin ohne echte Mitgliedschaft sieht keine Inhalte eines Kunden-Teams — jedes FA-Tool FORBIDDEN außer der
        // Plattform-Steuerung (Verwaltungsdaten). Die Tool-Hülle ist die zentrale Stelle für alle FA-Tools (statt je Tool in team()).
        if ($user instanceof User && ! \Platform\FoodAlchemist\Support\InhaltsSperre::toolErlaubt($this->getName())) {
            $gTeam = $context->team instanceof Team ? $context->team : ($user->currentTeamRelation ?? null);
            if ($gTeam instanceof Team && \Platform\FoodAlchemist\Support\InhaltsSperre::istGesperrt($user, $gTeam)) {
                return ToolResult::error(\Platform\FoodAlchemist\Support\InhaltsSperre::MELDUNG, 'FORBIDDEN');
            }
            // Spec 84 §4.4 (E3): Tool-Aufruf im Support-Zugang → Protokoll inkl. lesend/schreibend
            if ($gTeam instanceof Team && ($sf = \Platform\FoodAlchemist\Support\InhaltsSperre::perSupportZugang($user, $gTeam)) !== null) {
                app(\Platform\FoodAlchemist\Services\SupportFreigabeService::class)->protokolliereZugriff($sf, $user, $gTeam, 'mcp:'.$this->getName(), ! ($this->getMetadata()['read_only'] ?? false));
            }
        }
        // Spec 77b: Bereich des Tools (Team gebucht ∧ User nicht eingeschränkt) — gilt auch für lesende Tools
        $bereich = \Platform\FoodAlchemist\Support\FaBereiche::fuerTool($this->getName());
        if ($user instanceof User && $bereich !== null) {
            $bTeam = $context->team instanceof Team ? $context->team : ($user->currentTeamRelation ?? null);
            if ($bTeam instanceof Team && ! app(FaRechte::class)->darfBereich($user, $bTeam, $bereich)) {
                return ToolResult::error('Der Bereich „'.(\Platform\FoodAlchemist\Support\FaBereiche::KATALOG[$bereich] ?? $bereich)
                    .'“ ist für dich bzw. dein Team nicht freigeschaltet.', 'FORBIDDEN');
            }
        }
        if ($user instanceof User && ! ($this->getMetadata()['read_only'] ?? false) && ! in_array($this->getName(), self::FUER_JEDE_ROLLE, true)) {
            $team = $context->team instanceof Team ? $context->team : ($user->currentTeamRelation ?? null);
            if ($team instanceof Team && ! app(FaRechte::class)->darf($user, $team, FaRolle::Kuratieren)) {
                $rolle = app(FaRechte::class)->rolle($user, $team);

                return ToolResult::error('Dieses Tool schreibt und braucht mindestens die Rolle „Kuratieren“ (Mitglied). Deine Rolle: „'
                    .$rolle->label().'“. Die Rolle pflegt ein Team-Admin in den Team-Einstellungen der Plattform.', 'FORBIDDEN');
            }
        }

        // #1002: KI-Tools laufen per MCP extern (Auftrag statt OpenAI-Call), in Transaktion mit Rollback bis zur Antwort
        if (\Platform\FoodAlchemist\Support\KiUmleitung::umleiten($this->getName())) {
            return \Platform\FoodAlchemist\Support\KiUmleitung::ausfuehren(fn () => $this->innen()->execute($arguments, $context));
        }

        return $this->innen()->execute($arguments, $context);
    }
}
