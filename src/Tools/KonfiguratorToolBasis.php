<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistLeistungVorlage;

/**
 * Spec 94 · Gemeinsame Hülle der Konfigurator-Tools (`foodalchemist.<ressource>.<VERB>`). Jedes Tool nennt Ressource, Verb,
 * Beschreibung, Schema und ob es nur liest; die Arbeit macht der Service (KonfiguratorService, LeistungVorlageService,
 * KonfiguratorPricingService, PresentationService). Fehler: Prüfung → VALIDATION_ERROR, geerbt → ACCESS_DENIED, fehlt → NOT_FOUND.
 * Rechte über FaRechteToolHuelle (schreibend = ab Kuratieren).
 */
abstract class KonfiguratorToolBasis extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    abstract protected function ressource(): string;

    abstract protected function verb(): string;

    abstract protected function beschreibung(): string;

    /** @return array{0: array<string,mixed>, 1: list<string>} Properties und Pflichtfelder */
    abstract protected function felder(): array;

    abstract protected function ausfuehren(Team $team, array $a): mixed;

    protected function lesend(): bool
    {
        return false;
    }

    /**
     * Destruktive Tools verlangen confirm=true wie angebote.DELETE. Geprüft im Tool selbst, nicht über `required` im Schema:
     * so bleibt der Fehlercode CONFIRM_REQUIRED, egal ob die Registry Pflichtfelder vorab prüft.
     */
    protected function destruktiv(): bool
    {
        return false;
    }

    /** @return list<string> */
    protected function beispiele(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'foodalchemist.' . $this->ressource() . '.' . $this->verb();
    }

    public function getDescription(): string
    {
        return 'Spec 94 Konfigurator · ' . $this->beschreibung();
    }

    public function getSchema(): array
    {
        [$props, $pflicht] = $this->felder();

        return ['type' => 'object', 'properties' => $props] + ($pflicht !== [] ? ['required' => $pflicht] : []);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if ($this->destruktiv() && ($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Löschen erfordert confirm=true (destruktive Aktion).', 'CONFIRM_REQUIRED');
        }
        try {
            return ToolResult::success($this->ausfuehren($team, $arguments));
        } catch (GeerbtNurLesenException $e) {
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        } catch (ModelNotFoundException) {
            return ToolResult::error('Nicht gefunden oder für dieses Team nicht sichtbar.', 'NOT_FOUND');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        $lesend = $this->lesend();

        return [
            'confirmation_required' => $this->destruktiv(),
            'category' => $lesend ? 'query' : 'action',
            'tags' => ['foodalchemist', 'konfigurator', 'spec94', $lesend ? 'read' : 'write'],
            'read_only' => $lesend, 'idempotent' => $lesend, 'risk_level' => $lesend ? 'safe' : 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => $lesend ? [] : ['updates'],
            'related_tools' => ['foodalchemist.konfiguratoren.GET', 'foodalchemist.konfiguratoren.PREISE'],
            'examples' => $this->beispiele(),
        ];
    }

    /** Schema der Regel- und Preisfelder einer Leistung (Vorlage oder Konfigurator-Leistung). */
    protected static function regelSchema(): array
    {
        $zahl = ['type' => 'number'];

        return [
            'kategorie' => ['type' => 'string', 'enum' => array_keys(FoodAlchemistLeistungVorlage::KATEGORIEN)],
            'bezeichnung' => ['type' => 'string'],
            'notiz' => ['type' => 'string'],
            'mengen_regel' => ['type' => 'string', 'enum' => FoodAlchemistLeistungVorlage::MENGEN_REGELN, 'description' => 'fest: anzahl · schluessel: 1 je schluessel_n (bezug gaeste|leistung) mit min/max/reserve_pct · stufen: stufen_json'],
            'anzahl' => $zahl,
            'bezug' => ['type' => 'string', 'enum' => FoodAlchemistLeistungVorlage::BEZUEGE],
            'bezug_leistung_id' => ['type' => 'integer', 'description' => 'bei bezug=leistung: Id der Leistung, an der die Menge hängt (z. B. Teamleitung je 8 Servicekräfte)'],
            'schluessel_n' => $zahl, 'min' => $zahl, 'max' => $zahl, 'reserve_pct' => $zahl,
            'ab_pax' => ['type' => 'integer'], 'bis_pax' => ['type' => 'integer'],
            'stufen_json' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['bis_pax' => ['type' => 'integer', 'description' => 'bis zu dieser Gästezahl; weglassen = darüber hinaus (letzte Stufe)'], 'anzahl' => $zahl]]],
            'preis_je' => ['type' => 'string', 'enum' => FoodAlchemistLeistungVorlage::PREIS_JE],
            'stunden_quelle' => ['type' => 'string', 'enum' => FoodAlchemistLeistungVorlage::STUNDEN_QUELLEN],
            'stunden' => $zahl, 'stunden_zuschlag' => $zahl,
            'haeufigkeit' => ['type' => 'string', 'enum' => FoodAlchemistLeistungVorlage::HAEUFIGKEITEN],
            'ek_satz' => ['type' => 'number', 'description' => 'EK je Stück/Einsatz/Stunde, netto'],
            'markup_class_id' => ['type' => 'integer', 'description' => 'Aufschlagsklasse mit bereich=leistung; liefert den VK-Vorschlag'],
            'vk_modus' => ['type' => 'string', 'enum' => FoodAlchemistLeistungVorlage::VK_MODI],
            'vk_satz' => ['type' => 'number', 'description' => 'nur bei vk_modus=fixed'],
            'vk_begruendung' => ['type' => 'string', 'description' => 'Pflicht bei vk_modus=fixed'],
        ];
    }

    protected static function kopfSchema(): array
    {
        $zahl = ['type' => 'number'];

        return [
            'name' => ['type' => 'string'], 'kunden_titel' => ['type' => 'string'], 'claim' => ['type' => 'string'], 'intro' => ['type' => 'string'],
            'ort' => ['type' => 'string'], 'crm_company_id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => \Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator::STATUS],
            'verantwortlich_user_id' => ['type' => 'integer', 'description' => 'bekommt Anfragen per Mail'], 'pax_vorgabe' => ['type' => 'integer'],
            'stunden_lunch' => $zahl, 'stunden_dinner' => $zahl, 'stunden_day_night' => $zahl, 'tageszeit_aktiv' => ['type' => 'boolean'],
            'kombi_rabatt_pct' => $zahl, 'paket_toleranz_pct' => $zahl, 'rundung' => ['type' => 'number', 'enum' => [0.01, 0.1, 0.5, 1.0]],
            'gueltig_bis' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            'reihenfolge' => ['type' => 'string', 'enum' => \Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator::REIHENFOLGEN, 'description' => 'Schritte im Link: Zeitfenster zuerst (Standard) oder Konzept zuerst'],
        ];
    }

    protected static function konzeptSchema(): array
    {
        return [
            'rolle' => ['type' => 'string', 'enum' => ['haupt', 'addon']],
            'lunch_concept_id' => ['type' => 'integer'], 'dinner_concept_id' => ['type' => 'integer'],
            'concept_id' => ['type' => 'integer', 'description' => 'Add-on: das Concept selbst'],
            'kunden_titel' => ['type' => 'string'], 'claim' => ['type' => 'string'], 'abschnitt' => ['type' => 'string'],
            'addon_tageszeit' => ['type' => 'string', 'enum' => ['lunch', 'dinner', 'beide']], 'gebunden_an_id' => ['type' => 'integer'],
            'indikativ_lunch' => ['type' => 'boolean'], 'indikativ_dinner' => ['type' => 'boolean'], 'position' => ['type' => 'integer'],
        ];
    }

    protected static function zuordnungSchema(): array
    {
        return [
            'konfigurator_konzept_id' => ['type' => 'integer', 'description' => 'leer = Grundausstattung'],
            'gilt_fuer' => ['type' => 'string', 'enum' => ['lunch', 'dinner', 'beide']],
            'ersetzt_leistung_id' => ['type' => 'integer', 'description' => 'Grundausstattungs-Leistung, die diese Zusatz-Leistung ersetzt'],
            'position' => ['type' => 'integer'],
        ];
    }

    protected static function id(array $a, string $feld = 'id'): int
    {
        $id = (int) ($a[$feld] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException("{$feld} fehlt.");
        }

        return $id;
    }
}
