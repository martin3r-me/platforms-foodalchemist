# Spec 22 — Härtung: Cluster-Triage des Verbesserungs-Backlogs

> **Anlass:** 40 autonome Routine-Läufe haben 55 Befunde in [_Verbesserungs_Backlog.md](_Verbesserungs_Backlog.md) gesammelt (V-001…V-055), alle auf `neu`. Diese Spec ist die **Triage**: sie gruppiert die Einträge nach ihrer gemeinsamen Wurzel, bewertet je Cluster das Risiko und schneidet daraus Bau-Etappen. Sie ersetzt nicht das Backlog — dort bleibt die Beweislage je Einzelfund.
>
> **Kern-Erkenntnis der Triage:** Die Einträge sehen einzeln alle nach S/M aus (31×S, 18×M, **kein einziges L/XL**). Das täuscht. Rund ein Dutzend beschreiben *dieselbe* Wurzel aus verschiedenen Richtungen — zusammengefasst sind sie der XL-Fund, der in der Liste fehlt. Und die riskanteste Gruppe ist **nicht** die größte.

---

## 1. Die Cluster (nach Risiko, nicht nach Anzahl)

### ⛔ C1 · Geld wird an mehreren Stellen verschieden gerechnet · 4 Einträge · **höchstes Risiko**
`V-041` · `V-046` · `V-053` · `V-014`

Vier unabhängige Befunde, eine Wurzel: **dieselbe Geldgröße hat mehr als eine Lesart**, und die Abweichung ist nach oben hin unsichtbar.

- **V-041** ist der schärfste: `sales_unit_count` wird an einer Stelle *multipliziert* (Darreichung), an drei Stellen *dividiert* (Kalkulation, Paket, Cockpit-Fallback). Gemessenes Beispiel: wahre Wareneinsatz-Quote 25 %, angezeigte **2,5 %** — Faktor 10, in Richtung „sieht gut aus". Darauf steht das Signal `wareneinsatz_ueber_ziel`: es meldet bei jedem Gericht mit mehreren Einheiten je Verkauf **systematisch zu selten**.
- **V-053**: „aktiver Preis" ist in der DQ-Ampel laxer definiert als im Money-Path (kein `status`, kein `valid_to`) → die Ampel meldet **zu wenige** Preis-Lücken.
- **V-046**: der Kandidaten-Pool filtert Preisbänder auf der Legacy-Spalte, alles andere liest den Darreichungs-Resolver → Gerichte fallen lautlos aus dem Pool.
- **V-014**: ein GP ohne Lead-LA blockiert die EK-Kette nicht, sondern **mittelt** über alle Artikel — das Rezept gilt als vollständig bepreist, obwohl niemand einen Artikel gewählt hat. Kein Feld unterscheidet „gewählt" von „gemittelt".

**Warum zuerst:** Das ist die einzige Gruppe, deren Fehler *nach außen* geht — in Angebote, Kalkulationen, Margen. Alle vier melden zu günstig bzw. zu sauber, nie zu teuer. Und L8 (Auto-VK) baut seit heute Preise auf genau diesen Zahlen.

### 🔶 C2 · Lauf-Buchhaltung: `bulk_runs` weiß nicht woran, nicht wie es endete, und niemand kann fragen · 5 Einträge
`V-047` · `V-054` · `V-055` · `V-032` · `V-013`

Vier Befunde beschreiben **eine Tabelle** aus vier Richtungen — das Backlog sagt das selbst („gehört mit V-047 und V-054 in *eine* Etappe"):
- `V-047` kein `context`: der Lauf sagt nicht, **woran** er arbeitete (welche Datei, welcher Lieferant)
- `V-054` kein `failed`: ein abgebrochener Lauf bleibt **für immer `running`** → `ingest.STATUS` antwortet drei Monate später „läuft gerade"; 5 von 7 Jobs haben keinen `failed()`-Hook
- `V-055` keine allgemeine Quittung: zwei Ablagen (Cache mit 15-Min-TTL vs. Tabelle), **über MCP gar kein Weg**, einen eingereihten Lauf abzufragen
- `V-032` kein Eloquent-Model: die einzigen Fach-Tabellen ohne Model → kein Activity-Log für Läufe, die Provider-Geld kosten
- `V-013` Fixer-Fehlschläge ohne Spur: `catch` verschluckt alles, „0 gefixt weil nichts auflösbar" ist von „0 gefixt weil alles geworfen hat" nicht unterscheidbar

**Warum früh:** Einzeln gebaut wird dieselbe Tabelle viermal angefasst. Und es blockiert konkret: jede künftige asynchrone MCP-Fläche (12·S2b `assemblierung.POST`, Findings-Batch, Bulk-Anreicherung) müsste die Quittung ein viertes Mal erfinden.

### 🔶 C3 · Signal-Lifecycle: das gerade gebaute Cockpit widerspricht sich selbst · 4 Einträge
`V-011` · `V-009` · `V-033` · `V-008`

- **V-011** ist der Kern: die Ampel emittiert nur bei `wert > 0` — für „war 42, ist jetzt 0" gibt es **keinen Zweig**. Geschlossen wird nur über den Fixer-Knopf. Wer eine Lücke von Hand behebt, lässt ein **Phantom-Signal** stehen. Die Zeitreihe (E1) und die Zustands-Zeile (E2) zählen offene Signal-Zeilen — sie halten den Trend hoch, während die Ampel-Hälfte längst 0 misst. **Zwei Zahlen im selben Cockpit, die sich widersprechen.**
- **V-009**: kein `last_seen_at`/`seen_count` → Spec 21 §7 Punkt 6 (Wiederkehr-Historie) ist mit dem heutigen Schema **nicht baubar**; ein Dauerbrenner sinkt in der Liste nach unten, obwohl er gerade wieder zuschlug.
- **V-033**: 29 von 37 Signal-Typen rendern **gar keinen** Handlungs-Hinweis (`plan = null`) — „Urteilssache" und „der Weg führt woanders hin" sehen identisch aus. Genau diese Typen bleiben liegen.
- **V-008**: die Ursache hinter mehreren Signalen wird nicht gemessen — sechs `*_aggregated_at`-Stempel existieren, kein Check liest sie. „Falsch gerechnet" ist meist „vor der letzten Änderung gerechnet".

**Warum früh:** Spec 21 ist gerade fertig geworden. Diese vier untergraben ihre Kernzusage (Trend + Rausch-Guard) von innen.

### 🟡 C4 · Eine Regel, zwei Stellen — Drift durch Doppel-Definition · 9 Einträge · **der versteckte XL**
`V-005` · `V-010` · `V-018` · `V-023` · `V-036` · `V-042` · `V-044` · `V-003` · `V-048`

Der größte Cluster nach Anzahl, und der Grund, warum die Liste kein L/XL zeigt: **jeder Einzelfall ist ein S, das Muster ist ein XL.**

Spitzenreiter **V-018**: „die Gerichte eines Konzepts" wird an **sieben** Stellen neu zusammengesetzt — zwei davon zehn Zeilen auseinander in derselben Datei. Ob ein Gericht, das im Slot *und* im Paket steht, einmal oder zweimal zählt, beantwortet jede Stelle für sich. Daran hängen Preis pro Person, Coverage-Ampel, Kundendokument, Planungsblatt, Kohäsion und die Signale.

Gemeinsames Gegenmittel: **eine Auflöse-Stelle je Frage, plus ein Registry-Test, der die Kopien gegeneinander hält.** Das Muster ist im Haus bewährt (`PromptRegistryTest` hat in S5b-2 einen echten Fund geliefert).

### 🟡 C5 · Geteilte Schreibwege ohne Vertrag · 6 Einträge
`V-027` · `V-034` · `V-028` · `V-052` · `V-030` · `V-040`

- **V-027** (zwei unabhängige Fundorte): `syncIngredients` und `SupplierItemService::setAllergens/setNutrition/setDeclarations` sind **Voll-Ersetzer** — jeder nicht mitgeschickte Wert fällt auf Default. Beim Zutaten-Sync verliert man Handpflege; **bei den Allergenen fällt eine Angabe an den Gast still auf `unbekannt`, ohne Historie zum Rückweg.** Zwei Etappen (L1a, S1c) haben es im Aufrufer umgangen statt an der Wurzel geheilt.
- **V-030** (zweiter Fundort ist preis-relevant): der Prompt-Ausgabe-Vertrag steht nur als Prosa im `task`. Bei Textfeldern ist Drift eine leere Anzeige — bei `portion_g` ist sie ein **falscher Preis, der grün gemeldet wird**.
- `V-034` `??`-Defaults zeigen in die gefährliche Richtung · `V-028` KI-Pfade schreiben am `updateVk`-Gate vorbei (L8 schreibt jetzt Preise dort) · `V-052` Plausibilitäts-Bänder kennt nur der Importer, nicht der geteilte Service · `V-040` Ein-Zeilen-Auflösung UI-only

### 🟡 C6 · Eingabe ohne Wirkung — Felder, die Arbeit einsammeln und verwerfen · 5 Einträge
`V-022` · `V-025` · `V-021` · `V-043` · `V-004`

- `V-022` Kapitel-Ziele werden gepflegt und **nie ausgewertet** (früher Ausstieg ohne Gerüst)
- `V-025` `claim` ist schreibbar über zwei MCP-Tools und wird von **keiner** Ausgabe gelesen (`description` war derselbe Fall — nur zufällig entdeckt)
- `V-021` `snapshot_at` wird von vier Stellen gelesen und **nirgends geschrieben** → der Versand-Freeze ist toter Code, `foodbook_stale` kann nur Preis-Drift melden
- `V-043` von 40 Routing-Kombinationen sind **5 wirksam**, welche steht nur im PHP; eine eingetragene Zeile wirkt oder wirkt nicht, ohne Warnung
- `V-004` zwei Prompt-Keys ohne Konsument

**Gemeinsames Muster:** kein Fehler, keine Warnung — nur Stille. Die teuerste Form, weil der Nutzer daraus schließt, es habe funktioniert.

### 🟢 C7 · Mengen werden je Element aufgelöst · 3 Einträge
`V-045` (Halbschritt gebaut) · `V-049` · `V-050`

`V-049`: es gibt keinen Einstieg „rechne **diese Menge** Rezepte neu" — vier Aufrufer schleifen `recomputeAndPropagate`; bei 200 Basisrezepten in 5 Gerichten werden diese 5 Gerichte **200-mal** gerechnet. S1b musste deshalb eine willkürliche Kappung (`MAX_RECOMPUTE = 1000`) einziehen.
`V-050`: der Preis-Sprung-Detektor kennt nur „das ganze Team" und deckelt auf 500 GPs — welche 500, entscheidet die `pluck`-Reihenfolge.

**Gehört zu Spec 12·R2.4**, nicht hierher: die Perf-DoD hängt daran.

### 🟢 C8 · Datenmodell blockiert eine Fähigkeit · 5 Einträge
`V-019` · `V-020` · `V-006` · `V-007` · `V-051`

- **V-019** ist der interessanteste: `role='kern'` liest sich wie Identität, ist im Bestand ein **Beutel** (Ø 4 Anker je Rezept, Spitze 24). „Worum geht es in diesem Gericht" hat damit **keine Datenquelle** — welcher Anker gewinnt, entscheidet die Einfüge-Reihenfolge. Ein Erstentwurf hätte „beide Gänge fangen mit Butter an" als gleiche Hauptzutat gemeldet.
- **V-020**: 20 `status`-Spalten, 2 Model-Consts — das Vokabular lebt im **Migrations-Kommentar**, also an der einen Stelle, die kein Code liest. Bereits zwei deutsche/englische Doppel-Lesarten im Bestand.
- `V-006` `rezept_verwaist` kann über `updated_at` **prinzipiell nie** feuern (jeder Recompute setzt die Uhr zurück) · `V-007` zwei Hauptgruppen-Bäume, nur einer stilllegbar · `V-051` Nährwerte als einziger Detail-Block ohne Lineage, und der einzige, der gemittelt wird

### ⚪ C9 · Einzelstücke ohne gemeinsame Wurzel · 11 Einträge
`V-001` · `V-012` · `V-015` · `V-016` · `V-017` · `V-029` · `V-031` · `V-035` · `V-037` · `V-038` · `V-039`

Darunter zwei mit belegtem Wiederholungs-Schaden:
- **V-012** (drei Fundorte, ~25 Min verbrannt): Blades können **stillschweigend unkompiliert** bleiben; `view:cache` meldet trotzdem Erfolg, weil es nur PHP herausschreibt ohne es zu parsen. Die Bau-Rahmen-Zeile „Blade vor Push `php -l`" gibt eine Sicherheit, **die sie nicht hat**.
- **V-001**: die Suite crasht im 128M-Default und sieht wie ein Code-Fehler aus.

---

## 2. Bau-Etappen (mein Schnitt)

| # | Etappe | Cluster | Größe | Begründung |
|---|---|---|---|---|
| **H1** | **Quick-Wins Test & Infra** | aus C4/C9 | S | `V-001` memory_limit in `phpunit.xml` · `V-012` Pest-Test über alle 105 Blades (`compileString` + „kein `<?php(`" + „keine übrige Direktive") · `V-003` Registry-Test über `SignalTyp::cases()` · `V-048` `flushAll()` statt Handliste · `V-044` Enum weglassen, in `execute()` validieren. **Alles Riegel, kein Verhaltensrisiko, belegter Wiederholungs-Schaden.** |
| **H2** | **Geld-Wahrheiten vereinheitlichen** | C1 | M–L | Erst **messen** (Report-Command: wie viele Gerichte mit `sales_unit_count > 1`, wie viele mit Darreichungs-Preis ≠ Rezept-Preis, wie viele Lead-LAs nur mit statusfremder Preiszeile), dann `sales_unit_count` verbindlich definieren und die Divisions-Stellen ziehen; `aktivPreisFuerLead` auf `PriceService::scopeAktiv`; Pool auf Resolver-Preis; `ek_price_basis` (`lead`/`mixed`/`avg`). **Reihenfolge Pflicht:** die Messzahl entscheidet, ob das ein stiller Dauerfehler oder ein Randfall ist. |
| **H3** | **Lauf-Buchhaltung in einem Zug** | C2 | M | `BulkRunTyp`-Enum + `context`-json (V-047) + `failed`-Pfad & Alters-Kriterium (V-054) + Models (V-032) + generisches `foodalchemist.runs.GET` (V-055) + `failed()`-Hooks für die 5 Jobs ohne + Log/`failed:int` im Fixer (V-013). **Eine Migration, eine Etappe** — einzeln wird die Tabelle viermal angefasst. |
| **H4** | **Signal-Lifecycle schließen** | C3 | M | `V-011` Gegenzweig „gemessen 0 ⇒ offenes Signal schließen" (die Prädikate existieren als `countFor()`) · `V-009` `last_seen_at`/`seen_count` additiv · `V-033` dritte Plan-Art `navigate` + Registry-Test „jeder Typ hat Fixer, Assist, navigate oder Begründung" · `V-008` Signal `rezept_aggregat_veraltet` über die vorhandenen Stempel, Fixer `recompute` existiert schon. |
| **H5** | **Eine Auflöse-Stelle je Frage** | C4 | L | Beginnt mit **V-018** (`ConceptService::gerichtZeilen()` als einzige Auffaltung, 7 Fundorte) — der teuerste Einzelfall. Dann `V-005` Rest-13-Metriken auf das Register-Muster, `V-010` Label-Map, `V-023`+`V-042` Generator-Eingabe-Vertrag (`GenerationOptions`), `V-036` Registry-Test. |
| **H6** | **Verträge an den Schreibwegen** | C5 | M–L | `V-027` `array_key_exists` statt `?? null` in **beiden** Fundorten (Zutaten + die drei LA-Setter) + `patchIngredient` · `V-030` `fields`-Array je Registry-Eintrag mit Typ **und Wertebereich** · `V-028` `updateVk` mit Lineage-Modus · `V-052` Bänder in den Service · `V-034` Befund-DTO. |
| **H7** | **Stille Felder entscheiden** | C6 | M | Je Fall **eine Entscheidung, nichts dazwischen**: `V-025` `claim` ausgeben **oder** aus Schema+Feldliste entfernen · `V-021` Versand-Freeze bauen (`snapshot_at` als Abfallprodukt des Exports) · `V-022` frühen Ausstieg auflösen · `V-043` `SUPPORTED`-Konstante + Log-Warnung + DQ-Check · `V-004` mit L6 eingelöst prüfen. Plus der Pest-Test „jedes als kundensichtbar deklarierte Feld kommt in der Dokument-Projektion vor". |

**Nicht in dieser Spec:**
- **C7** → gehört als Voraussetzung in **Spec 12·R2.4** (`V-045` zweiter Halbschritt, `V-049` `recomputeMany`, `V-050` `?array $gpIds`). Ohne sie ist die Perf-DoD nicht erreichbar.
- **C8** → einzeln entscheiden, keine gemeinsame Etappe. `V-019` (Identitäts-Anker) ist ein eigener Fähigkeits-Ausbau mit Reichweite bis Spec 09 und R2.4 — Kandidat für eine eigene Spec, nicht für Härtung.
- **C9-Rest** → Einzelticket-Material; `V-035`/`V-037` gehören inhaltlich zu Spec 03 (Generator-Strecke), `V-015`/`V-016`/`V-017` zu Spec 21/Concepter.

## 3. Reihenfolge + Begründung

```
H1 (Riegel, S)  →  H2 (Geld, messen zuerst)  →  H3 (Läufe, eine Migration)
   →  H4 (Signale)  →  H5 (Auflöse-Stellen, L)  →  H6 (Verträge)  →  H7 (stille Felder)
```

**H1 zuerst**, weil es Riegel sind: der Blade-Test und der Enum-Test fangen genau die Fehlerklassen, die in den nächsten Etappen entstehen. **H2 vor allem anderen Fachlichen**, weil dort Zahlen nach außen gehen und L8 seit heute Preise darauf baut. **H5 spät**, weil es das größte Stück ist und von H1s Registry-Test-Muster profitiert.

## 4. Bewusste Nicht-Ziele

- **Kein Rundum-Refactor.** Jede Etappe hat einen benannten Schaden; was nur „schöner" wäre, bleibt liegen.
- **Kein Verhaltenswechsel ohne Golden-Test.** Gilt besonders für H2 (Geld) und H5 (`V-018`): erst Ausgabe einfrieren, dann umbauen, dann byte-identisch prüfen. Die Fehlerklasse dieser Umbauten ist die **stille Verschiebung**, nicht der Crash.
- **Kein Status-Setzen im Backlog durch die Routine.** Die Einträge bleiben `neu`, bis Dominique je Cluster entscheidet; diese Spec ist der Vorschlag, nicht die Freigabe.

---

*Erstellt 2026-07-27 (Session-Triage über 55 Backlog-Einträge aus 40 Routine-Läufen). Quelle je Einzelfund: [_Verbesserungs_Backlog.md](_Verbesserungs_Backlog.md). Fahrplan: [_Fahrplan_Routine_Umsetzung.md](_Fahrplan_Routine_Umsetzung.md).*
