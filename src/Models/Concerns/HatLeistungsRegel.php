<?php

namespace Platform\FoodAlchemist\Models\Concerns;

/**
 * Spec 94 · Gemeinsame Rechen- und Preisfelder von Leistungs-Vorlage und Konfigurator-Leistung (die Leistung ist eine
 * Kopie der Vorlage). Werte-Listen hier, damit Service, Oberfläche und MCP dieselben Werte prüfen.
 */
trait HatLeistungsRegel
{
    public const KATEGORIEN = ['personal' => 'Personal', 'equipment' => 'Equipment', 'logistik' => 'Logistik', 'sonstiges' => 'Sonstiges'];

    public const MENGEN_REGELN = ['fest', 'schluessel', 'stufen'];

    public const BEZUEGE = ['gaeste', 'leistung'];

    public const PREIS_JE = ['stueck', 'stunde'];

    public const STUNDEN_QUELLEN = ['einsatzzeit', 'fest'];

    public const HAEUFIGKEITEN = ['je_event', 'je_tageszeit'];

    public const VK_MODI = ['auto', 'fixed'];

    /** Felder, die beim Übernehmen aus der Vorlage kopiert werden (Rechnen und Preis). */
    public const REGEL_FELDER = [
        'kategorie', 'bezeichnung', 'notiz', 'mengen_regel', 'anzahl', 'bezug', 'bezug_leistung_id', 'schluessel_n', 'min', 'max',
        'ab_pax', 'bis_pax', 'stufen_json', 'reserve_pct', 'preis_je', 'stunden_quelle', 'stunden', 'stunden_zuschlag', 'haeufigkeit',
        'ek_satz', 'markup_class_id', 'vk_satz', 'vk_modus', 'vk_begruendung',
    ];

    public function initializeHatLeistungsRegel(): void
    {
        $this->mergeCasts([
            'anzahl' => 'decimal:2',
            'schluessel_n' => 'decimal:2',
            'min' => 'decimal:2',
            'max' => 'decimal:2',
            'ab_pax' => 'integer',
            'bis_pax' => 'integer',
            'stufen_json' => 'array',
            'reserve_pct' => 'decimal:2',
            'stunden' => 'decimal:2',
            'stunden_zuschlag' => 'decimal:2',
            'ek_satz' => 'decimal:2',
            'vk_satz' => 'decimal:2',
            'position' => 'integer',
        ]);
    }
}
