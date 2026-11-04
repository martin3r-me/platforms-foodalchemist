<?php

namespace Platform\FoodAlchemist\Models\Concerns;

/**
 * Spec 94 · Gemeinsame Rechen- und Preisfelder von Leistungs-Vorlage und Konfigurator-Leistung (die Leistung ist eine
 * Kopie der Vorlage). Werte-Listen hier, damit Service, Oberfläche und MCP dieselben Werte prüfen.
 */
trait HatLeistungsRegel
{
    // Spec 94b: die Kategorien sind ein Vokabular (FoodAlchemistVocabLeistungskategorie, LeistungsKategorieService), keine Konstante mehr.

    public const MENGEN_REGELN = ['fest', 'schluessel', 'stufen'];

    public const BEZUEGE = ['gaeste', 'leistung'];

    public const PREIS_JE = ['stueck', 'stunde'];

    public const STUNDEN_QUELLEN = ['einsatzzeit', 'fest'];

    public const HAEUFIGKEITEN = ['je_event', 'je_tageszeit'];

    public const VK_MODI = ['auto', 'fixed'];

    /** Spec 94b: im All-in-Preis oder Einzelabrechnung nach Aufwand (nicht im Preis pro Person, im Link „zzgl. nach Aufwand“). */
    public const ABRECHNUNGEN = ['all_in', 'aufwand'];

    /** Felder, die beim Übernehmen aus der Vorlage kopiert werden (Rechnen und Preis). */
    public const REGEL_FELDER = [
        'kategorie', 'bezeichnung', 'notiz', 'mengen_regel', 'anzahl', 'bezug', 'bezug_leistung_id', 'schluessel_n', 'min', 'max',
        'ab_pax', 'bis_pax', 'stufen_json', 'reserve_pct', 'preis_je', 'stunden_quelle', 'stunden', 'stunden_zuschlag', 'haeufigkeit',
        'ek_satz', 'markup_class_id', 'vk_satz', 'vk_modus', 'vk_begruendung', 'abrechnung', 'abrechnung_einheit_vocab_id',
        'planung_json',   // Spec 94b §3: Planungsdetails (Komponenten, Attribute, interne Notiz), nie im Kundenlink
    ];

    /** Spec 94b §3: Komponente je Einheit der Leistung, je Gast oder je Stunde. */
    public const PLANUNG_JE = ['einheit', 'gast', 'stunde'];

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
            'planung_json' => 'array',
            'reserve_pct' => 'decimal:2',
            'stunden' => 'decimal:2',
            'stunden_zuschlag' => 'decimal:2',
            'ek_satz' => 'decimal:2',
            'vk_satz' => 'decimal:2',
            'position' => 'integer',
            'abrechnung_einheit_vocab_id' => 'integer',
        ]);
    }
}
