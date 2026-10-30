<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

/**
 * #1003 PR7 · Planung am Handy — bedienbar, aber nur der Handy-Umfang (Dominique 30.10.): Auftrag geben für Gericht,
 * Basisrezept und Concept (Import liegt in der eigenen Komponente RezeptImport), Kaskade starten, Lauf abbrechen und
 * wieder aufnehmen, Ergebnis lesen, Fotos zu einem Schritt hochladen bzw. übernehmen. Alle KI-Starts laufen als UI-Klick
 * über die Plattform-KI, dieselben Methoden wie am Computer.
 * Gesperrt bleiben: Freigeben, Verwerfen (Schritt, alle, Planung), Plan ändern, Konzept nachhängen, Favorit, Lücken melden,
 * Neu generieren/anreichern, Ausgabe-Formen aus dem Brief, Composer, Trendradar-Kombinierer, Duplizieren, Vorlagen speichern.
 */
trait HandyPlanung
{
    use HandyLesebereich;

    public function handyLeseMethoden(): array
    {
        return [
            // Übersicht und Editor
            'neuePlanung', 'schnellErstellen', 'schnellImport', 'oeffne', 'waehle', 'speichern', 'kaskadeGestartet', 'importAngelegt',
            // Auftrag (Brief, Leitplanken, Diktat, KI-Plan) und Start
            'reglerPill', 'briefVorlage', 'titelVorschlagen', 'suchbegriffEntfernen', 'suchbegriffHinzufuegen', 'leitplankenAusBriefing',
            'briefDiktatUebernehmen', 'agentDiktatUebernehmen', 'voiceKomponentenUebernehmen', 'wissenVorschau', 'kiKopf',
            'planFeldSpeichern', 'planVerwerfen', 'goKaskade',
            // Ergebnis: Lauf beobachten, abbrechen, wieder aufnehmen; nur ansehen
            'pruefeLauf', 'laufAbbrechen', 'laufWiederAufnehmen', 'laufFortsetzen',
            'waehleSchritt', 'waehleKnoten', 'setzeFortschrittFilter',
            // Fotos zu einem Schritt (Gericht am Handy fotografieren)
            'fotoHochladen', 'fotoPickerOeffnen', 'fotoPickerSchliessen', 'fotoUebernehmen',
            '_startUpload', '_finishUpload', '_uploadErrored', '_removeUpload',
            'nextPage', 'previousPage', 'gotoPage', 'setPage', 'resetPage',
        ];
    }
}
