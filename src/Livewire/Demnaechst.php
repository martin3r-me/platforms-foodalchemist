<?php

namespace Platform\FoodAlchemist\Livewire;

use Livewire\Component;

/**
 * R7 (Dominique): «In Planung»-Seite — die künftigen Domänen sind in der
 * Sidebar sichtbar, der Klick landet hier auf dem Phase-2-Überblick
 * (Scope-Stand: docs/14_ROADMAP_PHASE2.md, M10/M11+).
 */
class Demnaechst extends Component
{
    /**
     * Statisch aus 14_ROADMAP_PHASE2 — bewusst kein DB-Zeug (reine Vorschau).
     * fa-pass: `icon` = Heroicon-Name (gleich der Zuordnung im View), `status` muss für
     * fertige Bereiche „Fertig" enthalten (der View filtert darauf), `name` ist Schlüssel im View.
     */
    public const DOMAENEN = [
        ['icon' => 'heroicon-o-book-open', 'name' => 'Foodbook / Portfolio', 'status' => 'Fertig, im Modul verfügbar',
            'idee' => 'Speisekarten und Menüs zusammenstellen: Kapitel mit Gerichten, Texten und Varianten, Verkaufstexte im Ton der Marke, Preisstand beim Versand festgehalten, PDF-Export.'],
        ['icon' => 'heroicon-o-calculator', 'name' => 'Kalkulation (HK2)', 'status' => 'Geplant, noch nicht terminiert',
            'idee' => 'Kalkulation auf Basis der Herstellkosten 2: Wareneinsatz plus Arbeitszeit mal Stundensatz plus Gemeinkosten-Zuschläge. Die Arbeitszeit je Rezept ist schon gepflegt.'],
        ['icon' => 'heroicon-o-building-office-2', 'name' => 'Produktionsplanung', 'status' => 'Geplant, noch nicht terminiert',
            'idee' => 'Produktionsaufträge aus Bestellmengen, daraus hochgerechnete Basisrezepte und Tagespläne je Station und Gerät.'],
        ['icon' => 'heroicon-o-calendar-days', 'name' => 'Speiseplan', 'status' => 'Fertig, im Modul verfügbar',
            'idee' => 'Wochen- und Zykluspläne aus Gerichten mit Diät- und Allergen-Abdeckung, Eignung je Verpflegungsbereich als Filter.'],
        ['icon' => 'heroicon-o-document-text', 'name' => 'Speisekarte', 'status' => 'Fertig, im Modul verfügbar',
            'idee' => 'À-la-carte-Karte fürs Restaurant: Rubriken mit Gerichten, festen Menüs und Getränken, Allergen- und Zusatzstoff-Fußnoten nach LMIV, Bruttopreise auf der Druckkarte, eigenes Erscheinungsbild, Wechsel- und Saisonkarten per Duplizieren, Texte mit KI.'],
        ['icon' => 'heroicon-o-shopping-cart', 'name' => 'Einkauf', 'status' => 'Geplant, noch nicht terminiert',
            'idee' => 'Bestellvorschläge aus dem Produktionsplan und dem bevorzugten Lieferantenartikel. Die Vorbestellzeiten der Lieferanten sind schon importiert.'],
        ['icon' => 'heroicon-o-archive-box', 'name' => 'Lager', 'status' => 'Geplant, noch nicht terminiert',
            'idee' => 'Bestände je Artikel und Grundprodukt, Wareneingang gegen Bestellung, Chargen für die Allergen-Rückverfolgung.'],
        ['icon' => 'heroicon-o-chart-bar', 'name' => 'Controlling', 'status' => 'Geplant, noch nicht terminiert',
            'idee' => 'Soll- und Ist-Wareneinsatz, Margen-Entwicklung, Auswertung der KI-Kosten.'],
    ];

    public function render()
    {
        return view('foodalchemist::livewire.demnaechst', ['domaenen' => self::DOMAENEN])
            ->layout('foodalchemist::layouts.standalone');
    }
}
