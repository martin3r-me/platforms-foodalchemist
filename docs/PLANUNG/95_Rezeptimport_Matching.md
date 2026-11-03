# Spec 95 · Rezeptimport: Zutaten treffen den Bestand

Stand 2026-11-02 · Auftraggeber Dominique · Kurator prüft und merged · Anlass: Pilot-Import KOCHBuch_2026 (32 Rezepte, IDs 35–66)

## Warum

Beim Erstellen eines Basisrezepts (`recipes.GENERATE`) landen die Zutaten zuverlässig am richtigen Grundprodukt, beim Import
(`recipes.EXTRACT`) und in der Anreicherung (`recipes.ENRICH` → `minteFehlendeGps`) oft nicht. Im Pilot:

| Zutat im Rezept | wurde zu | richtig wäre |
|---|---|---|
| Frischkäse | Frischkäse: Grillpaprika | Frischkäse: frisch, Natur |
| Parmesan | Hippe: Parmesan, gebacken | Parmesan |
| Weißwein | Weißwein-Creme: Pulver / (Dr. Oetker) | Weißwein: zum Kochen |
| Crème fraîche (1 EL) | **neues GP** „Crème fraîche (1 EL)“ | Creme Fraiche: frisch, 30 % Fett |
| Brauner Zucker (optional) | **neues GP** „Brauner Zucker (optional)“ | Rohrzucker: trocken, braun |
| Butter, Raumtemperatur | offen | Butter: frisch |

Dominique 02.11.: „Das Matching funktioniert doch, wenn man ein Basisrezept erstellt — warum da nicht?“

## Befund (Code-Analyse 02.11.)

Beide Wege laufen durch denselben Resolver (`RecipeGeneratorService` → `IngredientMatchService::matchIngredient`). Der
Unterschied ist das, was ankommt:

1. **GENERATE** gibt der KI `gp_kandidaten` mit IDs (`GenerationContextService`), die KI benennt die Zutaten danach und liefert
   die `gp_id` mit. Viele Zeilen sind gebunden, bevor die Textsuche läuft.
2. **EXTRACT** übernimmt den Wortlaut treu („Butter, Raumtemperatur“, „Crème fraîche (1 EL)“), ohne `gp_id`.
3. Die Freitext-Bereinigung (`ZutatFreitext::varianten`) läuft nur, wenn der Score **unter 0,50** bleibt. Zusätze wie „(1 EL)“
   landen oft knapp darüber (FuzzyLow) — nicht bereinigt, nicht verdrahtet, die Zeile bleibt offen und wird später gemintet.
4. `TokenEngine::tokenize` faltet nur ä/ö/ü/ß. „Crème fraîche“ und „Creme Fraiche“ sind verschiedene Tokens.
5. Der Kopfwort-Boden (0,90) hebt jede Geschmacksvariante („Frischkäse: Grillpaprika“) auf Exact. Die Basisvarianten-Regel
   stuft danach richtig auf FuzzyLow herab, weil sie „Natur“ selbst als Variante zählt und keine Basis findet.
6. Der **Post-Match-Draw** (`drawKandidaten`, Generator und `gps.MATCH`) zieht danach den ersten semantischen Kandidaten über
   0,70 — ohne Hauptzutat-Schutz und ohne Basisvarianten-Regel. So gewannen „Hippe: Parmesan“ und „Frischkäse: Grillpaprika“.
7. Der Mint (`LaFirstGpService::mintFromLa`) fragt den Bestand nur über `matchIngredient`, nicht über den Draw. Was nur
   semantisch zu finden ist („Brauner Zucker“ → Rohrzucker braun), wird neu angelegt. Der GP-Name entfernt nur eine führende Menge,
   darum heißt das neue GP „Crème fraîche (1 EL)“.

Die Regel „Bestand vor Neuanlage“ fehlt also nicht — sie verliert, weil sie mit ungereinigtem Text und ohne Draw prüft.

## Stufe 1 (dieses Paket)

Alles im gemeinsamen Resolver, damit EXTRACT, ENRICH, GENERATE und `gps.MATCH` denselben Weg gehen (Kurator-Lehre 3).

**1.1 Hinweis-Zusätze vor dem Abgleich entfernen** — `ZutatFreitext::ohneHinweise()`, aufgerufen am Anfang von
`matchIngredient` und `candidatesFor`:
- Klammer-Segment, das nur eine Menge mit Einheit („(1 EL)“, „(ca. 50 g)“, „(2 Stück)“) oder nur Hinweiswörter enthält
  („(optional)“, „(nach Belieben)“).
- Komma-Segment am Ende, das nur aus Hinweiswörtern besteht („, Raumtemperatur“, „, zimmerwarm“, „, optional“).
- Nur ganze Segmente, nie Teilstrings (Kurator-Lehre 1: Babyspinat/Frischkäse/Currypulver). Prozent-, Type- und
  Bestimmungswort-Klammern bleiben („Milch (1,5 %)“, „Öl (Raps)“). Zustand und Verarbeitung („frisch gerieben“) sind keine
  Hinweise und bleiben.

**1.2 Akzente falten** — `TokenEngine::tokenize` faltet zusätzlich é/è/ê/ë, à/â/á, î/ï/í, ô/ó, û/ù/ú, ç, ñ. Beide Seiten
(Zutat und GP-Name) laufen durch dieselbe Funktion. Der LIKE-Vorfilter trifft auf MySQL weiter, weil die Kollation
akzent-unabhängig vergleicht.

**1.3 Neutraler Geschmack ist Basis** — in `BasisVariante::ungenannt` zählt ein Merkmal, das nur aus „natur“, „naturell“,
„neutral“ oder „pur“ besteht, nicht als Variante. „Frischkäse“ findet damit die eine Basis „Frischkäse: frisch, Natur“.
Entscheidung über das Feld, nicht über Namenswörter (Kurator-Lehre 4).

**1.4 Draw mit denselben Schutzregeln** — `drawKandidaten` bekommt optional Team und Zutat. Ein GP-Kandidat wird nur gezogen,
wenn (a) seine Hauptzutat zur Zutat passt (`hauptzutatPasst`, wie im Matcher) und (b) er keinen ungenannten Geschmack und
keine ungenannte Marke trägt (`BasisVariante::ungenannt`). Gilt für Generator und `gps.MATCH`.

**1.5 Bestand vor Neuanlage beim Mint** — `mintFromLa` zieht vor dem Anlegen über denselben Draw (Modus hybrid, Boden 0,70).
Trifft der Draw, wird das Bestands-GP genommen statt eines neuen. Der Name eines neuen GP läuft durch `ohneHinweise` und
`ohneMenge` („Crème fraîche (1 EL)“ → „Crème fraîche“).

**Abnahme Stufe 1** (Tests je Einstieg, Test-GPs ohne Slug wo es um den Score geht, 0,90-Boden gegengeprüft):
- „Crème fraîche (1 EL)“, „Butter, Raumtemperatur“, „Brauner Zucker (optional)“ treffen dasselbe GP wie ohne Zusatz.
- „Milch (1,5 %)“ und „Öl (Raps)“ verhalten sich wie vorher (Gegenprobe).
- „Frischkäse“ → Frischkäse Natur, nie die Grillpaprika-Variante; „Frischkäse mit Grillpaprika“ → Grillpaprika.
- Draw zieht nie „Hippe: Parmesan“ für „Parmesan“ und nie ein Marken-GP ohne genannte Marke.
- `mintFromLa` legt kein neues GP an, wenn der Draw ein Bestands-GP findet.
- Alle bestehenden Matcher-Tests bleiben grün.

## Stufe 1b (Neuabgleich der 32 Pilot-Rezepte, 03.11.)

- `ohneHinweise` erkennt auch „(½ TL)“ und Bereiche „(2–3 EL)“ (Halbgeviertstrich, Bruch ohne Ziffer).
- Gleichstand am Kopf-Boden 0,90: die Hauptzutat, die die Zutat wörtlich nennt, schlägt das Kompositum mit gleichem Anfang
  („Mango“ → Mango, nicht „Mangold: Streifen“ — Fehler bestand schon vor Spec 95).

## Stufe 2 (freigegeben Dominique 03.11., Regel festgelegt mit dem Kurator)

1. **Doktrin LA-First** (Dominique: „die GP entstehen aus den Lieferantenartikeln“): ein GP entsteht nur aus einem WIRKLICH passenden
   Artikel. Passt keiner, entsteht **kein** GP, die Zeile bleibt offen (Sourcing-Lücke) — nie raten, nie „ohne Lead anlegen“.
2. **Passend** (`LaCandidateFinder::passtFuerMint`, genutzt von `bestMitGrundwort`): das Grundwort der Zutat ist das ERSTE
   Produktwort der Bezeichnung nach Abzug von Marke/Hersteller (`MarkeAusArtikel`: Necta-Brand, Kürzel-Karte, Hersteller-Kurzname)
   und Lieferantenname; Zahlen, Zustandswörter und Kürzel unter drei Buchstaben zählen nicht. „Buko Frischkäse Natur“ (Marke Buko)
   trifft „Frischkäse“; „Grießbrei 2-3 P. Mondamin“ trifft „Mondamin“ nicht; „WOK Ginger & Lime, Ingwerpüree …“ trifft
   „Ingwerpüree“ nicht.
3. **Kein Widerspruch in der Struktur** (`strukturWiderspruch`, über die Merkmale des Artikels, nicht über die Bezeichnung):
   Verarbeitung ist eine Zubereitung (`BasisVariante::ZUBEREITUNG` ohne „geschält“), die die Zutat nicht nennt — „Gepocheerd Scharrel
   Ei“ (pochiert) ist kein „Ei“; die Zutat nennt einen Zustand und der Artikel hat einen anderen; der Artikel ist aromatisiert
   (Geschmackszutat außer der Hauptzutat), was die Zutat nicht nennt. Ohne Struktur kein Widerspruch.
4. **Anker als Folge-Job:** `minteFehlendeGps` zieht den Anker nicht mehr synchron (`ankerNachziehen` blockierte im externen KI-Modus
   den ganzen Enrich-Lauf), sondern löst je GP `GpAromaNachholen::ausloesen` aus — Job nach dem Commit, je GP eindeutig, nie auf
   einer sync-Queue. Ergebnis `anker = {nachgelagert: n}`.
5. **EXTRACT mit Kandidaten:** `recipe.extract` bekommt `gp_kandidaten` (dieselbe Liste wie GENERATE, eigener Hinweis: Text bleibt
   wörtlich) und darf je Zeile eine `gp_id` vorschlagen; der Generator prüft sie (`validiereProposedGp`) und fällt sonst auf den
   Textabgleich zurück. Abschaltbar über `foodalchemist.import.gp_kandidaten`.

Offen danach: gemintete GPs vollständig (Warengruppe, Zustand, Struktur, Slug); Mengen-Plausibilität (Kurkuma 3 g → gemahlen).

Nicht Teil von Spec 95: Kaskaden-Runden, `onQueue`, Doppel-ENRICH, Lagerung (Kurator-Paket ENRICH).

## Stufe 3 · Spec 95c (Entscheid Dominique 03.11., nach dem Neuabgleich der 286 Pilot-Zeilen)

Gemessen nach Stufe 2: 65,4 % automatisch richtig, 3,5 % automatisch falsch, 29,7 % offen (44 davon mit richtigem Vorschlag).

1. **Pflichtangabe mit nur einer Basis** ist keine offene Variante (`BasisVariante::hart`): „Weißwein“ → „Weißwein: zum Kochen“.
   Mehrere Basen (Crème fraîche 30 % / 38 %) bleiben Review; nennt der Text einen anderen Wert, bleibt die Angabe hart.
2. **Gewürzmenge** (bis 15 g/ml, 3 TL, 1 EL, Msp./Prise; `BasisVariante::istGewuerzmenge`): gibt es eine getrocknete Form, ist sie
   gemeint — „Kurkuma 3 g“ → gemahlen, „Oregano (2 TL)“ → gerebelt. Gemahlen/gerebelt gilt dort als Naturform. Ein genannter Zustand
   entscheidet weiter selbst; ohne Menge bleibt die Rohform. `matchIngredient` bekommt `menge`, der Generator und `gps.MATCH` geben sie mit.
3. **Zehe als Einheit** (`Support\Zaehlform`): „Knoblauchzehen“ sucht „Knoblauch“; die Zeile mit Einheit Stück bekommt die Zehe als
   Einheit, wenn ihr Gewicht bekannt ist (GP-Form, Zähl-Default oder Vokabular). Nur echte Zählformen am Wortende.
   Daten: Form „zehe“ an GP 7877 über `gp_forms.ESTIMATE` bzw. von Hand (kein Datenlauf).
4. **Synonyme** (`TerminologyService::ALIAS_GROUPS`): Abrieb = Schale (Zitrone, Orange, Limette), Limone = Zitrone.
5. **Nie schlechter als Kandidat 1** (`IngredientMatchService::kandidatEins`): bleibt der Pool-Treffer unter dem Gate, zählt Kandidat 1
   der Kandidatenliste (lexikalisch bzw. lexikalisch+semantisch), wenn er besser liegt und Produktform, Verwechslungs-Falle und
   Hauptzutat besteht — „Cheddar, gerieben“ → Cheddarkäse, nicht Cheddar-Sauce.
6. **`recipe_ingredients.PUT` erdet nie still** (eigener PR): bestehende Zeilen behalten ihren Stand, neue Zeilen nur ohne `offen_halten`.
