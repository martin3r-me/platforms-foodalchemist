# Spec 80 · Kaskade: Suchbegriffe, Komponenten-Plan, Fortschritt-Baum

Stand 2026-10-09 · Branch `docs/spec80-kaskade-suchbegriffe-komponenten` · Abgestimmt mit Dominique
Anlass: demo Session #138 / Lauf #79 / Step #542 · Prüfbericht + Design-Mockup: https://claude.ai/artifact/GuryE5WDUdJNXJEousfvBH

## Warum

Briefing auf demo: „Petersilienpüree, grün, mit einer Petersilienmatte eingefärbt, 100 Pax, à la carte, Haute Cuisine,
from scratch, nur Bestand". Erwartet war ein **Basisrezept aus zwei Unterrezepten**. Tatsächlich lief Folgendes:

1. Wissen, Bestand und Pairing werden **einmal vorab** gesucht, mit dem **Rohtext** des Briefings. Geprüft werden nur
   die ersten 16 Wörter (`GenerationContextService::SONDIERUNG_MAX_TOKENS`). Bei #542 waren das Füllwörter wie „was",
   „ist", „pax", „pro", „person". Die Basis Petersilienwurzel hat erst der Generator gewählt, danach wurde nichts mehr
   nachgeschlagen. Ergebnis: kein Wissen zur Petersilienwurzel, kein Bestandspüree (#374), kein Pairing-Plan, obwohl
   Anker 2863 „Petersilienwurzel" existiert.
2. Für Basisrezepte gibt es **keinen Komponenten-Plan**. Der Generator-Prompt sagt sogar „EIN Baustein … keine
   Mehr-Komponenten-Zusammenstellung". Unterrezepte entstehen nur zufällig: 7 von 32 Basisrezept-Läufen auf demo.
3. Bestand wird **nach Namen** gezogen, ohne zu prüfen, ob die Funktion passt. „Petersilienmatte" (Blattgrün zum
   Einfärben) landete auf #2069 „Garnitur: Kräutermatte Petersilie (Vegan)" (Gel-Blatt). Weitere Fälle: Cremige
   Polenta → „Chip: Polenta", Rote-Bete-Püree → „Püree: Kartoffel-Rote-Bete", Gemüsefond → „Gemüsefond: Bohne-Speck".
4. 86 % der Bestandsverweise zeigen auf **nicht freigegebene** Rezepte (376 von 435).
5. Die **Fortschritt-Ansicht** ist eine lange Liste. Basisrezepte stehen getrennt von ihrem Gericht, die Gliederung
   der Ausgabe (Foodbook, Speisekarte …) ist nicht sichtbar, die Breite bleibt ungenutzt.

## Zielbild (Abnahme-Beispiel)

Briefing wie oben. Ablauf nach Spec 80:

1. **Leitplanken + Suchbegriffe:** Ansatz 8 kg (100 Pax × 80 g umgerechnet), Haute Cuisine, from scratch, Bestand
   „Nur Bestand". Suchbegriffe als Chips: *Petersilienwurzel · glatte Petersilie · Püree · Blattgrün-Matte · grün halten*.
2. **Komponenten-Plan** (zur Bestätigung):

   | Komponente | Menge im Ansatz | Funktion | Quelle |
   |---|---|---|---|
   | Püree: Petersilienwurzel | 1.500 g | Basis | Bestand #374 (freigegeben) |
   | Matte: Petersilie | 100 g | Farbe | neu (keine passende Matte im Bestand; #2069 abgelehnt: Gel-Blatt ≠ Farbe) |
   | Butter, Salz | Rest | Würze, Bindung | Grundprodukte |

3. Nach „Plan übernehmen": Wurzel „Püree: Petersilienwurzel (grün)" mit **Verweiszeilen**; #374 wird übernommen;
   „Matte: Petersilie" wird als Kind-Step mit **Ansatz 100 g** gebaut.
4. **Fortschritt:** links der Baum, in der Mitte die Wurzel mit zwei Unterrezepten, rechts das Rezept mit „Woher das
   kommt" (Suchbegriffe, Wissen, Bestand, Pairing).
5. Die Planung heißt „Püree: Petersilienwurzel (grün)".

## Grundregeln (entschieden 2026-10-09)

- **Basisrezept = Ansatz.** Menge + Einheit (kg/l/Stk), nie Portionen oder Pax. Die Portion entscheidet das Gericht.
  Pax × Portion aus einem Briefing wird höchstens in einen Ansatz umgerechnet.
- **Bestand = nur freigegebene Basisrezepte** (`status = approved`). Entwürfe des **eigenen Laufs** bleiben über die
  Kind-Bindung (`bindCompletedChild`) nutzbar; das ist keine Bestandsübernahme.
- **Der Mensch bestätigt den Plan.** Wie beim Gerichtsvorschlag (`proposal_first`): erst Plan, dann Bau.
- **Kein Raten über Namensähnlichkeit** (Pairing, Spec 60). Erlaubt sind kuratierte Zuordnungen und exakte Treffer auf
  Wortbestandteile.
- **Schnellstart-Planungen heißen wie ihr erstes Ergebnis** (Platzhalter „Freies Basisrezept/Gericht/Concept" wird ersetzt).

## Teil 0 · Schon gebaut (Fix-PR `fix/kaskade-basisrezept-retry-leitplanken`)

- `RecipeService::keyVergeben` prüft mit `withTrashed()` → keine Duplicate-entry-Kollision nach Neu-generieren.
- Leitplanken kennen den Tab (`BriefingLeitplankenService::ausBriefing(..., $scope)`), Basisrezept fragt nach dem
  Ansatz, Pax × Portion → kg, scope-fremde Felder werden gemeldet. `ziel_menge`/`ziel_einheit` in
  `ALLOWED_GENERATION_PARAMS`. Verdeckte Pax/Portion gehen im Basisrezept-Tab nicht mehr in den Lauf.
- Basisrezept-Tab: sichtbare Auswahl „Bestand nutzen" (Gemischt / Nur Bestand / Komplett neu), `reglerParams`
  respektiert sie; im Gericht-Tab setzt „nur Bestand" den Kreativ-Modus „Datenbank".
- Planung übernimmt beim ersten Ergebnis dessen Namen (`PlanningSessionService::PLATZHALTER_TITEL`).

## Teil A · Suchbegriffe (Zwischenschritt)

**A1 · Modell.** Die Leitplanken-Antwort bekommt ein Feld `suchbegriffe`:

```json
{ "zutaten": ["Petersilienwurzel", "glatte Petersilie", "Butter"],
  "komponenten": ["Püree", "Blattgrün-Matte"],
  "techniken": ["passieren", "grün halten"],
  "aromen": ["frisch-grün"] }
```

Gespeichert an der Sitzung (neue JSON-Spalte `foodalchemist_planning_sessions.suchbegriffe`, je Scope ein Satz:
`{rezept: {...}, gericht: {...}, concept: {...}}`) und beim Go eingefroren in `cascade_runs.params.suchbegriffe`.
Jeder Begriff trägt die Quelle `ki` oder `mensch`.

**A2 · Prompt.** `planung.leitplanken` liefert die Suchbegriffe im selben Aufruf mit (kein Zusatzaufruf). Regeln:
Grundform statt Komposita (Petersilienpüree → Petersilie + Püree), Lebensmittel im Singular nach GP-Regelwerk §6.1,
keine Mengen, keine Füllwörter. Fehlt beim Go ein Satz (Leitplanken nie abgeleitet), erzeugt ein kleiner Aufruf
`planung.suchbegriffe` (Tier C, temp 0) die Begriffe nach.

**A3 · Oberfläche.** Im Erstellen-Tab unter dem Briefing: Chip-Reihe „Suchbegriffe", gruppiert (Zutaten, Komponenten,
Techniken, Aromen). Chip entfernen (×), Begriff hinzufügen (Eingabe + Enter). Von Hand ergänzte Chips sind markiert.
Ein erneutes „Leitplanken ableiten" überschreibt nur KI-Chips, nie die des Menschen.

**A4 · Verwendung.** An allen drei Such-Stellen ersetzen die Suchbegriffe den Rohtext:

| Stelle | Heute | Neu |
|---|---|---|
| Bestand/GP-Erdung `GenerationContextService::forGeneration` | `leitTokens($description, 16)` | Suchbegriffe zuerst (Zutaten, Komponenten), danach Briefing-Tokens ohne Füllwörter/Zahlen bis zum Budget |
| Wissen `KnowledgeContextService` (Retrieval) | Briefing-Text | Suchbegriffe als Query, Briefing als Nebenkontext |
| Pairing `Pairing\KombinationsPlan::fuer` | dieselben 16 Tokens | Suchbegriffe „zutaten" + „aromen" |

Stoppwortliste (`was, ist, pro, person, pax, keine, für, mit, …`) und Zahlen-Filter gelten auch im Fallback.

**A5 · Anker-Auflösung.** `PairingService::ankerIdExakt` bleibt exakt. Zwei Ergänzungen davor:
1. **Kuratierte Grundbegriffe:** Zuordnung Begriff → Standard-Anker (Petersilie → 2862 „Glatte Petersilie").
   Vor dem Bau prüfen, ob `foodalchemist_terminology_aliases` das tragen kann; sonst eigene Tabelle
   `foodalchemist_pairing_anchor_aliases (begriff, anchor_id, team_id NULL = global)`. Pflege per MCP.
2. **Wortzerlegung mit exaktem Treffer:** Kompositum gegen die Anker-Namen zerlegen (längster exakter Präfix, Fugen
   -n/-s/-en), jeder Teil wird exakt aufgelöst. Kein Teiltreffer = kein Anker.

**A6 · Transparenz.** `context_snapshot` schreibt `suchbegriffe`, `pairing: {anker: [...], ohne_anker: [...]}` und
die angebotenen Bestandskandidaten (IDs + Score). Die kaputte Sonde `pairing_keys` (sucht „pair" im Key-Namen, der
Plan heißt seit Spec 60 `kombinationsplan`) wird ersetzt. Bleibt der Plan leer: Hinweis „kein Anker für: …".

**A7 · Tests.** Leitplanken liefern Suchbegriffe; Mensch-Chips überleben Neu-Ableitung; Fallback-Aufruf bei leerem
Satz; `forGeneration` nimmt Suchbegriffe vor Briefing-Tokens; Stoppwörter fliegen raus; „Petersilienpüree" → Anker
Glatte Petersilie über Alias + Zerlegung; unbekanntes Wort → kein Anker; Snapshot enthält `pairing.anker`.

## Teil B · Komponenten-Plan für Basisrezepte

**B1 · Ablauf.** Basisrezept-Go startet wie beim Gericht mit einem **Plan-Step**:

1. `GenerateRecipePlanJob` (analog `GenerateDishProposalJob`): ein KI-Aufruf `recipe.komponenten_plan` mit Briefing,
   Leitplanken, Suchbegriffen und Bestandskandidaten je Komponente (B3). Step → `geplant`,
   `context_snapshot.komponenten = [...]`.
2. Der Mensch prüft die **Plan-Karte** im Fortschritt (D9): Komponente streichen, Menge ändern, Bestandskandidat
   tauschen/ablehnen, Komponente ergänzen.
3. „Plan übernehmen": Bestandskomponenten werden als `skipped` + `ref_id` übernommen, Lücken werden Kind-Steps, die
   Wurzel wird generiert mit **fest vorgegebenen Verweiszeilen** (der Generator ergänzt nur Grundprodukte wie Butter,
   Salz und die Zubereitung).
4. **Ein-Komponenten-Plan** (z. B. „Fond: Kalb"): kein Gate, direkt bauen wie heute.

Der Schalter liegt in `params.plan_first` (persistiert wie `proposal_first`, damit `regeneriereStep` ihn kennt).
MCP-Start ohne Plan bleibt beim heutigen Pfad.

**B2 · Komponente.**

```json
{ "name": "Matte: Petersilie", "funktion": "farbe", "menge": 100, "einheit": "g",
  "suchbegriffe": ["glatte Petersilie", "Blattgrün"],
  "bestand": { "recipe_id": null, "abgelehnt": [{"recipe_id": 2069, "grund": "Gel-Blatt, Funktion Garnitur ≠ Farbe"}] },
  "neu": true }
```

Funktions-Vokabular (geschlossen, erweiterbar): `basis · farbe · bindung · wuerze · textur · aroma · saeure · fett ·
garnitur`. Namen nach Basisrezept-Regelwerk §1 (Typ-Präfix: Bezeichnung).

**B3 · Bestand mit Funktionsprüfung.** Je Komponente Kandidaten aus **freigegebenen** Basisrezepten (Suche mit den
Komponenten-Suchbegriffen). Drei Prüfungen, in dieser Reihenfolge:
1. **Typ-Präfix passt** (Matte ≠ Garnitur/Gel, Chip ≠ Beilage, Püree ≠ Püree anderer Hauptzutat). Kompatibilitäts-
   tabelle im Code, kanonische Präfixe aus Regelwerk §1.2.
2. **Diät passt** (`diaet_hart` des Laufs gegen die Diät-Flags des Kandidaten; Speck ≠ vegetarisch).
3. **Gleiche kulinarische Funktion?** Kurzes KI-Urteil über den vorhandenen Prompt `component.replacement_suggest`,
   nur wenn 1 und 2 bestanden sind und der Score unter der Sicherheitsschwelle liegt.
Abgelehnte Kandidaten stehen mit Grund im Plan (Transparenz). Dieselbe Prüfung gilt im Gericht-Pfad
(`RecipeGeneratorService::validiereProposedSub`, `ziehtAusBestand`).

**B4 · Bestand = nur freigegeben.** Status-Filter `approved` an allen Stellen:
`GenerationContextService` (Rezept-Kandidaten), `RecipeGeneratorService::validiereProposedSub` (heute
stub/draft/review/approved) und `ziehtAusBestand` (heute draft/review/approved),
`RecipeDependencyWorkflowService::planChildren` (Übernahme), `Pairing\KombinationsPlan` (`basisrezepteMitAroma`).
Bestehende Läufe mit Verweisen auf Entwürfe bleiben unverändert.

**B5 · Generator-Prompt.** `recipe.generator` bekommt eine Ausnahme: Liegt ein bestätigter Plan vor, ist das Rezept
eine **Zusammenstellung**; die Verweiszeilen sind gesetzt und dürfen nicht dekonstruiert werden. Ohne Plan gilt der
heutige Ein-Baustein-Satz.

**B6 · Kinder.** Kind-Ansatz = Menge der Plan-Zeile (`ziel_menge`/`ziel_einheit` überschreiben, heute erbt das Kind
den Wurzel-Ansatz: `RecipeDependencyWorkflowService` ~Z. 175/236). Das Kind bekommt die Suchbegriffe seiner
Komponente, nicht die der Wurzel.

**B7 · Tests.** Plan-Step wird `geplant` mit Komponenten; Übernahme legt Bestand als `skipped` + Lücken als Kind-Steps
an; Kind-Ansatz = Zeilenmenge; Ein-Komponenten-Plan baut direkt; Entwurf im Bestand wird nicht angeboten;
Typ-Präfix lehnt Gel-Blatt für „Matte" ab; Diät lehnt Speck bei vegetarisch ab; `regeneriereStep` kennt `plan_first`.

## Teil C · Robustheit (Rest aus dem Prüfbericht)

- **C1 Neu generieren während der Nachphase sperren.** Guard in `regeneriereStep` auch auf `phase !== null` und
  laufende Anreicherung. Nachphasen-Jobs (`ConformanceCheckJob`, Selbstheilung, Anreicherung) tragen die
  `generator_run_id` ihres Versuchs und brechen still ab, wenn der Step weitergewandert ist.
- **C2 Server-Whitelist.** `regeneriereStep`/`verwirfStep` nur für `done`/`failed`; Bestandsverweise (`skipped`) nie
  löschen.
- **C3 Selbstheilung fasst Verweiszeilen nicht an.** Befunde zu Verweiszeilen gehen an das verlinkte Rezept.
- **C4 „Keine Diät" = ausdrücklich leer.** `filterGenerationParams` lässt `diaet_hart: []` als Wert durch; die
  Leitplanken ersetzen den Regler und zeigen die Änderung.
- **C5 Abbruch-Guard** in `markStepFailed`: ein abgebrochener Lauf springt nicht mehr auf „Zu prüfen".
- **C6 Protokoll.** `recipe.ueberarbeiten` mit `target_table/target_id`; `catch` in `ConformanceService`/
  `ConformanceCheckJob` protokollieren und Befund „Heilung fehlgeschlagen" schreiben.
- **C7 Status-Anzeige.** Stufe mit gescheiterten Schritten heißt „fehlgeschlagen", nie „erledigt"
  (`Planung\Index::stufenAusSteps`). `laufFortsetzen` zeigt bei vorhandenen Fehlern nur eine Meldung mit
  „Neu generieren" statt „arbeitet vermutlich noch".

## Teil D · Fortschritt-Ansicht (Design)

Abgenommen am Mockup (Artifact, Version 8). Ersetzt die heutige Liste in `partials/ergebnis.blade.php` +
`partials/step-zeile.blade.php` im Reiter „Fortschritt".

**D1 · Aufbau.** Kopf über die volle Breite, darunter drei Spalten:

```
┌ Kopf: Gerichte 4/4 → Basisrezepte 11/18 → Freigegeben 0/22      [Alle][Zu prüfen][Fehler][Aus Bestand] ┐
├──────────────┬───────────────────────────────────────┬─────────────────────────────────────────────────┤
│ Baum         │ Pfad: Foodbook › Bankett › Herbstmenü │ Rezept des gewählten Eintrags                   │
│ Lauf         │ ● 1 Schritt gescheitert …             │  Kopf, Herkunft-Chip, EK/VK/Marge bzw. Ansatz   │
│ Foodbook     │ ┌ SUP Kürbiscremesuppe …  EK  Status ┐│  Zutaten (Menge · Einheit · Zutat · Herkunft)    │
│  Format      │ │ └ Suppe: Kürbis   neu   1,48 € ●  ││  Zubereitung                                    │
│   Kapitel    │ │ └ Crunch: Hasel.  Bestand 0,31 € ●││  Woher das kommt (Suchbegriffe, Wissen,         │
│    Konzept   │ │ 3 Basisrezepte · 1 offen [Freigeben]│   Bestand, Pairing)                            │
│ Speisekarte  │ └──────────────────────────────────┘│  Offene Hinweise (Konformität)                  │
│  Rubrik …    │ ┌ HG … ┐                             │  [Freigeben] [Zutaten bearbeiten] [KI-Assistent]│
└──────────────┴───────────────────────────────────────┴─────────────────────────────────────────────────┘
```

Spaltenbreiten: Baum `minmax(200px, .55fr)`, Mitte `1.1fr`, Rezept `1fr`. Unter 1.100 px rutscht der Baum über die
Mitte, unter 900 px eine Spalte (Rezept unter der Liste). Baum und Rezept sind `sticky`, das Rezept scrollt in sich.

**D2 · Baum.** Zeigt die Gliederung **so, wie sie in der Ausgabe angelegt wird**. Datenquelle:
`cascade_runs.source_owner_type/id` + Step-Baum (`parent_step_id`) + `chapter_id`/`slot_id` am Step.

| Lauf-Art | Ebenen |
|---|---|
| Foodbook-Vollkaskade | Foodbook → Format → Kapitel → Konzept → Gericht |
| Speisekarte | Speisekarte → Rubrik → Gericht |
| Speiseplan | Speiseplan → Woche → Tag → Linie → Gericht |
| Concept | Concept → Gang/Station → Gericht |
| Gericht / Basisrezept (Depth-1) | nur „Lauf"; Baum entfällt, Mitte nutzt die Breite |

Jeder Knoten: Ebene (klein, Großbuchstaben, über dem Namen), Name (einzeilig, gekürzt mit `title`), Zähler rechts
(„3 offen" bzw. „1 Fehler" in Fehlerfarbe). Oberster Knoten „Alle Ergebnisse". Klick filtert die Mitte auf die
Gerichte darunter und setzt den Pfad. Ein Gericht in zwei Ausgaben erscheint in beiden Zweigen.

**D3 · Mitte: Cluster.** Je Gericht eine Karte:
- Kopf: Gang-Kürzel (`SUP`, `VOR`, `HG`; bei Basisrezept-Wurzel `BR`), Name einzeilig, EK Portion, Status-Chip.
- Darunter **jede Komponente als Zeile**: Baum-Strich, Name, Herkunft-Chip (`neu gebaut` · `aus Bestand` ·
  `Bestand unfertig` · `Fehler`), EK, Zustandspunkt.
- Fuß: „N Basisrezepte · M offen" + „Gericht + Basisrezepte freigeben".
- Gescheiterte Schritte: rote Meldung oben angeheftet, nur wenn der gewählte Zweig den Fehler enthält.
- Mehrfach genutzte Basisrezepte: in jedem Cluster, im Rezept „Genutzt in: …".

**D4 · Rechts: Rezeptansicht.** Für Gericht und Basisrezept dieselbe Bauart:
- Kopf: Ebene · für wen, Name, Herkunft-Chip.
- Kennzahlen: Gericht = EK Portion / VK netto / Marge; Basisrezept = EK Ansatz / Ansatz / Konformität.
- **Zutaten**: Menge · Einheit · Zutat · Herkunft (Lieferant bzw. „Bestand #374" / „neu, Unterrezept").
  Verweiszeilen sind Links und öffnen das Unterrezept in derselben Spalte.
- **Zubereitung**: nummerierte Schritte.
- **Woher das kommt**: Suchbegriffe (Chips), Wissen (Dossier-Titel statt Slugs), Bestandstreffer inkl. abgelehnter
  Kandidaten mit Grund, Pairing (Anker + 3★-Partner) oder „kein Anker für …".
- **Offene Hinweise**: Konformitäts- und Review-Befunde.
- Aktionen je Zustand: Freigeben · Zutaten bearbeiten · KI-Assistent · Neu generieren · Aus Bestand wählen ·
  Bestand anreichern · Im Editor öffnen.
- „Zutaten bearbeiten" öffnet den bestehenden Zutaten-Editor als Overlay in voller Breite (nicht in der Spalte).
- Inhalte lädt die Spalte erst beim Klick (`wire:key` je Eintrag, siehe Livewire-Fallen); Liste bleibt schnell.

**D5 · Kopf.** Stufenleiste mit Zähler + Balken je Stufe (Gerichte, Basisrezepte, Freigegeben), Filter-Chips
Alle / Zu prüfen / Fehler / Aus Bestand. Filter wirken zusätzlich zum Baum.

**D6 · Zustände.**

| Step-Status | Punkt | Chip |
|---|---|---|
| `done` + keine Hinweise | grün | neu gebaut |
| `done` + Hinweise / Bestand unfertig | gelb | neu gebaut / Bestand unfertig |
| `skipped` (Übernahme, freigegeben) | grau | aus Bestand |
| `failed` | rot | Fehler |
| `geplant` | gelb, Umriss | geplant |
| `queued`/`running` | animiert (nur ohne `prefers-reduced-motion`) | läuft … + Phase |
| `freigegeben` | grün, gefüllt | freigegeben |

**D7 · Plan-Karte (für Teil B).** Steht ein Plan-Step auf `geplant`, zeigt die Mitte statt des Clusters die
Plan-Karte: Tabelle der Komponenten (Name, Menge, Funktion, Quelle), je Zeile Bestand tauschen/ablehnen, Menge
ändern, streichen; „+ Komponente"; abgelehnte Kandidaten mit Grund aufklappbar; „Plan übernehmen" startet den Bau.

**D8 · Sprache und Look.** Nur `--fa-*`-Tokens und `x-fa`-Bausteine, keine festen Farben. Wording „Ansatz", nie
„Charge" oder „Portionen" beim Basisrezept. Zahlen tabellarisch (`tabular-nums`). Tastatur: Zeilen und Baumknoten
fokussierbar, Enter öffnet.

**D9 · Dateien.** Neue Partials `fortschritt/kopf`, `fortschritt/baum`, `fortschritt/cluster`,
`fortschritt/rezept`, `fortschritt/plan-karte`. Daten-Methoden in `Planung\Index`: `fortschrittBaum()`,
`fortschrittCluster(?knoten)`, `fortschrittRezept(stepId)`. Alte `ergebnis`/`step-zeile` bleiben bis zur Abnahme
hinter einem Schalter, danach entfernen.

**D10 · Tests.** Render-Test je Lauf-Art (Baum-Ebenen korrekt), Filter + Baum kombiniert, Fehler-Meldung nur im
betroffenen Zweig, Rezeptansicht zeigt Verweiszeilen als Links und „Woher das kommt", Freigabe je Cluster gibt
Gericht + Basisrezepte frei. Browserprüfung im Host (food-alchemist.de) und demo-Layout, schmal und breit.

## Teil F · Struktur-Elemente (Titel, Titel mit Preis, Freitext, Leerzeile)

Concepts, Formate, Foodbooks und Speisekarten enthalten neben Gerichten **Struktur-Elemente**:
`header` (Titel), `header_preis` (Titel mit Preis/Staffel), `text` (Freitext), `spacer` (Leerzeile)
(`ConceptService::STRUKTUR_TYPEN`, `FoodAlchemistFormatSlot::STRUKTUR_TYPEN`). Die Kaskade muss sie so anlegen und
erhalten, dass Format, Foodbook und Speisekarte **genau so herauskommen wie geplant**.

**F1 · Heute schon richtig (Stand main).**
- Der Concept-Fan-out füllt nur Gericht-Slots, Struktur-Slots bleiben unberührt
  (`PlanningCascadeService::fanoutConceptInvention`, Filter `whereNotIn('type', [...STRUKTUR])`).
- Ein vorhandenes Format überträgt Titel, Freitext und Leerzeilen in die Speisekarte (`SpeisekarteService` ~Z. 365)
  und ins Foodbook (`FoodbookService` ~Z. 1952), in Reihenfolge.
- Der Concept-Generator legt je Gang einen Titel an (`ConceptGeneratorService` ~Z. 591).

**F2 · Lücke.** Der Planungsrahmen (`FoodAlchemistPlanningFrameSlot::SLOT_TYPES = gang|station|kapitel`) kennt keine
Struktur-Elemente. Die Voll-Kaskade (`starteVollkaskade` → `vollkaskadeSlots`) erzeugt je Rahmen-Slot ein Concept und
hängt es an die Ausgabe. Titel, Freitexte, Leerzeilen und Titel mit Preis **zwischen** oder **in** den Concepts
entstehen dabei nicht und müssen heute von Hand nachgetragen werden. Verdacht (per Test zu belegen): Steht im Ziel
(Kapitel/Rubrik) schon Struktur, landen neue Concepts hinten statt an der geplanten Stelle.

**F3 · Lösung.**
1. **Rahmen kennt Struktur:** `SLOT_TYPES` um `header`, `header_preis`, `text`, `spacer` erweitern, mit `sort`.
   Die Kaskade legt sie 1:1 in der Ausgabe an (Foodbook: `header_frei`/`header_frei_preis`/`text`/`spacer`,
   Speisekarte: Positionen `header`/`text`/`spacer`, Concept: Blöcke) — kein KI-Aufruf, keine Steps.
2. **Reihenfolge ist Vertrag:** Concepts und Struktur-Elemente werden in Rahmen-Reihenfolge eingefügt
   (`sort`), nicht angehängt. Vorhandene Struktur im Ziel bleibt an ihrer Stelle.
3. **KI-Texte nur auf Wunsch:** Freitext-Slots mit Vermerk „von der KI formulieren" (z. B. Hinführung zum Menü)
   bekommen einen Text im Kunden-Wording; sonst bleibt der vorgegebene Text. Titel mit Preis übernimmt den
   berechneten Preis p. P. des darunterliegenden Concepts, wenn kein fester Preis gesetzt ist.
4. **Leere Struktur ist kein Fehler:** Reife-/Coverage-Prüfungen zählen Struktur-Elemente nie als offene Position
   (heute schon so in `ConceptReifeAdapter`, `DataQualityService`; für Foodbook/Speisekarte prüfen).

**F4 · Fortschritt zeigt Struktur.** Im Baum (D2) und in der Mitte (D3) erscheinen Struktur-Elemente **an ihrer
Stelle** als ruhige, nicht klickbare Zeilen: Titel fett, Titel mit Preis mit Preis rechts, Freitext kursiv
einzeilig gekürzt, Leerzeile als schmaler Abstand. So entspricht die Reihenfolge im Fortschritt der Ausgabe.

**F5 · Tests.** Speisekarten-Vollkaskade mit Rahmen „Titel · Gang · Freitext · Leerzeile · Gang" → Positionen in genau
dieser Reihenfolge; dasselbe für Foodbook-Kapitel und Format; Fan-out füllt keine Struktur-Slots; Titel mit Preis
zeigt den Concept-Preis; vorhandene Struktur im Ziel bleibt an ihrer Stelle; Druck/PDF der Speisekarte zeigt die
Leerzeile (DomPDF-Fallen beachten).

## Teil E · Offen (Entscheidung nötig)

- **Freigabe-Rückstau:** 249 unentschiedene Entwürfe, 49 von 79 Läufen auf „Zu prüfen". Eigene Liste „offene
  Entscheidungen" im Cockpit? Ab wann werden alte Entwürfe automatisch verworfen?
- **Funktions-Vokabular** (B2) final abstimmen.
- **Suchbegriffe auch im Gericht- und Concept-Tab?** Vorschlag: ja, gleiche Mechanik.

## MCP (Lockstep)

- `planung_leitplanken.EXTRACT` liefert `suchbegriffe`.
- Kaskaden-Status-DTO zeigt `suchbegriffe`, Plan-Komponenten und `pairing.anker/ohne_anker`.
- Neu: `planung_kaskade.PLAN_UEBERNEHMEN` (Plan-Step-ID + optional geänderte Komponenten).
- Pflege der Anker-Grundbegriffe (A5) per MCP-Tool.

## Pakete und Reihenfolge

| PR | Inhalt | Aufwand | Abhängig von |
|---|---|---|---|
| 0 | Fix-PR (Teil 0) | erledigt, PR offen | — |
| 1 | Suchbegriffe A1–A4, A6–A7 | M | 0 |
| 2 | Anker-Grundbegriffe + Wortzerlegung A5 | M | 1 |
| 3 | Bestand nur freigegeben + Funktionsprüfung B3–B4 | M | — |
| 4 | Komponenten-Plan B1–B2, B5–B7 | L | 1, 3 |
| 5 | Robustheit C1–C7 | M | — (parallel möglich) |
| 6 | Fortschritt-Ansicht D1–D6, D8–D10 | L | 0 (parallel zu 1–4) |
| 7 | Plan-Karte D7 | M | 4, 6 |
| 8 | Struktur-Elemente im Rahmen + Fortschritt F1–F5 | M | 6 (F4) |

Jedes Paket: eigener Branch, volle Suite grün, demo-Deploy, Abnahme an einem echten Lauf.

## Abnahme gesamt

- Das Petersilien-Briefing ergibt das Zielbild oben (Plan mit #374 + neuer Matte, Ansatz 100 g, Wurzel aus Verweisen).
- Pairing-Plan bei Läufen mit benannter Hauptzutat nicht leer; leerer Plan nennt die Wörter ohne Anker.
- Stichprobe 20 Bestandsübernahmen: keine Funktions- oder Diät-Fehlzuordnung, nur freigegebene Rezepte.
- Anteil Basisrezept-Läufe mit Unterrezept steigt (heute 7 von 32), wo das Briefing eine Kombination beschreibt.
- Fortschritt: Speisekarten- und Foodbook-Lauf zeigen den Baum wie angelegt; kein „Erledigt" mit Fehler.
- Titel, Titel mit Preis, Freitext und Leerzeilen aus dem Rahmen stehen in Foodbook, Speisekarte und Format an
  der geplanten Stelle, auch im Druck.

## Anhang · Codestellen (origin/main a1d89ab9)

| Thema | Stelle |
|---|---|
| Token-Grenze 16 | `src/Services/GenerationContextService.php:34`, `:288` |
| Pairing-Plan | `src/Services/GenerationContextService.php:180`, `src/Services/Pairing/KombinationsPlan.php:42` |
| Pairing-Sonde | `src/Services/RecipeGenerationContextService.php:307` |
| Leitplanken | `src/Services/BriefingLeitplankenService.php`, Prompt `config/foodalchemist.php` `planung.leitplanken` |
| Gerichtsvorschlag (Vorbild B1) | `src/Jobs/GenerateDishProposalJob.php`, `PlanningCascadeService.php:~153` (`proposal_first`) |
| Bestand-Status | `RecipeGeneratorService.php:997` (validiereProposedSub), `:1066/:1085` (ziehtAusBestand) |
| Kind-Steps | `RecipeDependencyWorkflowService.php:400` (planChildren), `:175/:236` (Kind-Params) |
| Neu generieren / Verwerfen | `PlanningCascadeService.php:~2440`, `:~2540` |
| Status-Stufen | `src/Livewire/Planung/Index.php:3347` (stufenAusSteps), `:2929` (laufFortsetzen) |
| Fortschritt-Views | `resources/views/livewire/planung/partials/ergebnis.blade.php`, `step-zeile.blade.php` |
| Struktur-Typen | `ConceptService::STRUKTUR_TYPEN` (Z. 404), `FoodAlchemistFormatSlot::STRUKTUR_TYPEN`, `FoodAlchemistPlanningFrameSlot::SLOT_TYPES` |
| Voll-Kaskade | `PlanningCascadeService::starteVollkaskade` (~Z. 345), Fan-out-Filter ~Z. 1310 |
| Format → Ausgabe | `SpeisekarteService` ~Z. 365, `FoodbookService` ~Z. 1952 |
