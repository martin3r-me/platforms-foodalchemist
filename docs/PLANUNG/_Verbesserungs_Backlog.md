# Verbesserungs-Backlog — Kandidaten für künftige Specs

> **Zweck:** Die autonome Routine `fa-specs-umsetzung` liest sich in jedem Lauf tief in echten Code ein. Dabei fällt Dinge auf, die **über den aktuellen Auftrag hinausgehen** — Muster, die man vereinheitlichen könnte, Fähigkeiten, die auf ein neues Level gehoben werden könnten, Drift zwischen Code und Regelwerk. Diese Beobachtungen landen hier statt verloren zu gehen. Nach dem Gesamtlauf hat Dominique daraus eine Verbesserungs-/Anpassliste.
>
> **Diese Liste ist ein Vorschlags-Speicher, keine Aufgabenliste.** Nichts hier ist freigegeben.

---

## ⛔ Regeln für die Routine (verbindlich)

1. **NIE selbst umsetzen.** Ein Eintrag hier ist eine Beobachtung für Dominique — **kein Selbstauftrag**. Auch nicht „schnell mit dabei". Wer hier einträgt, baut es nicht. Scope-Drift ist der Hauptgrund, warum autonome Läufe entgleisen.
2. **Max. 1–3 Einträge pro Lauf.** Lieber ein guter als fünf schwache. Wer alles notiert, notiert Rauschen.
3. **Nur konkret.** Datei/Service/Zeile nennen, echten Effekt benennen. „Code könnte sauberer sein" ist kein Eintrag. „`X` und `Y` implementieren dieselbe Regel zweimal, Drift-Risiko bei Regelwerk-Änderung" ist einer.
4. **Erst gegen Bestand prüfen.** Steht die Beobachtung schon drin (oder in einer Spec)? Dann nicht doppeln — höchstens die vorhandene Zeile mit einem zweiten Fundort ergänzen.
5. **Kein Ersatz für einen Bug.** Ein echter Fehler gehört als Signal/Bug behandelt, nicht als „Verbesserungsidee" geparkt.
6. **Status setzen nur bis `neu`.** Über `geprüft` / `in Spec übernommen` / `verworfen` entscheidet ausschließlich Dominique.

## Kategorien
`architektur` · `datenmodell` · `faehigkeit` (Level-up) · `mcp-luecke` · `test-luecke` · `regelwerk-drift` · `performance` · `ux` · `tech-debt` · `infra`

## Eintrags-Format
```
### V-<nr> · <kurztitel>
- **Kategorie:** <aus Liste>   **Größe:** S/M/L/XL   **Status:** neu
- **Gefunden:** <YYYY-MM-DD>, Lauf zu <Etappe> · `pfad/datei.php:zeile`
- **Beobachtung:** was ist da, faktisch.
- **Warum es zählt:** welcher konkrete Schaden/Nutzen — nicht „wäre schöner".
- **Level-up-Vorschlag:** was man stattdessen tun könnte.
- **Berührt:** <Specs/Bereiche>
```

---

## Einträge

### V-001 · Suite crasht im Sandbox-Default-Memory-Limit
- **Kategorie:** infra   **Größe:** S   **Status:** neu
- **Gefunden:** 2026-07-25, Phase-0-Audit · `sandbox-food-alchemist` (PHP-Default 128M)
- **Beobachtung:** `vendor/bin/pest` bricht mit *Fatal: Allowed memory size of 134217728 bytes exhausted* ab (Blade-View-Render + Whoops-Frame). Grün nur mit `php -d memory_limit=1G`.
- **Warum es zählt:** Der Crash sieht wie ein Code-Fehler aus, ist aber Konfiguration. Jeder (Mensch oder Routine), der die Suite ohne Flag startet, liest ein falsches Ergebnis und sucht am falschen Ende.
- **Level-up-Vorschlag:** `memory_limit` in der Sandbox-Test-Konfiguration verankern (`phpunit.xml` `<ini>` bzw. `.env.testing`/Wrapper-Skript), damit der nackte Aufruf verlässlich ist.
- **Berührt:** alle Etappen (Test-Gate)

### V-002 · Zwei Signal-Quellen mit unterschiedlicher Mechanik
- **Kategorie:** architektur   **Größe:** M   **Status:** neu
- **Gefunden:** 2026-07-25, Signal-Audit · `src/Services/DataQualityService.php` (372 Z.) + `src/Services/SignalDetektorService.php` (781 Z.)
- **Beobachtung:** Signale entstehen auf zwei Wegen — `DataQualityService` über `gap()`-Aufrufe (Ebenen-Lücken, hartkodiert in einer langen Methode) und `SignalDetektorService` über je eine public Detektor-Methode. Beide schreiben in dieselbe `foodalchemist_signals`, aber mit eigener Dedup-/Payload-Konvention.
- **Warum es zählt:** Ein neuer Signal-Typ muss man an der richtigen von zwei Stellen ergänzen, mit unterschiedlichem Muster. Bei Spec 21 kommen ~20 neue Typen dazu — die Divergenz wird dort erstmals teuer.
- **Level-up-Vorschlag:** gemeinsamer Detektor-Contract (ein Interface `SignalDetector` mit `key()`/`typ()`/`severity()`/`detect()`), Registry statt hartkodierter Kette. Beide Bestandsquellen implementieren ihn, ohne Verhaltensänderung.
- **Berührt:** Spec 21, Spec 05

### V-003 · Neuer Signal-Typ erfordert drei synchrone Edits
- **Kategorie:** tech-debt   **Größe:** S   **Status:** neu
- **Gefunden:** 2026-07-25, Signal-Audit · `src/Enums/SignalTyp.php` (case + `label()`-match + `icon()`-match)
- **Beobachtung:** Jeder Typ steht an drei Stellen derselben Datei; ein vergessener match-Arm ist erst zur Laufzeit sichtbar (`UnhandledMatchError`).
- **Warum es zählt:** Spec 21 fügt ~20 Typen hinzu = 60 Edits mit drei Vergessens-Chancen pro Typ.
- **Level-up-Vorschlag:** Label/Icon als eine Map bzw. per Attribut am Case, plus ein Pest-Test, der über `SignalTyp::cases()` iteriert und Label+Icon je Case erzwingt (fängt es zur Testzeit statt zur Laufzeit).
- **Berührt:** Spec 21

### V-004 · Registrierte, aber ungenutzte Prompt-Keys
- **Kategorie:** tech-debt   **Größe:** S   **Status:** neu
- **Gefunden:** 2026-07-25, Spec-03-Audit · `src/Services/Ai/AiGatewayService.php:45-46`
- **Beobachtung:** `recipe.review` und `vk.review` stehen in der Allow-Liste, haben aber **keinen Konsumenten** im Modul (0 Treffer außerhalb der Liste).
- **Warum es zählt:** Eine Allow-Liste, die Keys ohne Aufrufer führt, verliert ihre Aussagekraft — man kann nicht mehr ablesen, was das System wirklich kann.
- **Level-up-Vorschlag:** Entweder mit 03·L6 einlösen (dann sind sie korrekt) oder bis dahin als „reserviert für L6" kommentieren. Zusätzlich ein Test, der Allow-Liste gegen tatsächliche `propose()`-Aufrufe abgleicht.
- **Berührt:** Spec 03 (L6)

### V-005 · Jedes Lücken-Prädikat steht zweimal im DataQualityService
- **Kategorie:** tech-debt   **Größe:** M   **Status:** neu
- **Gefunden:** 2026-07-25, Lauf zu 21·S0 · `src/Services/DataQualityService.php` — Zähl-Seite `basisrezepte()`/`gerichte()`/`gp()` (ab Z. 103) vs. `queryFor()` (ab Z. 300)
- **Beobachtung:** Die Zähl-Methoden bauen ihr Prädikat inline (`$this->rezepte($team,false)->whereNull('ek_total_eur')`), `queryFor()` baut für „reinschauen"/`countFor()` **dasselbe Prädikat ein zweites Mal** (`case 'br_ek_null'`). Betrifft aktuell 13 von 14 Metriken. Der Docblock über `queryFor()` behauptet „dieselben Prädikate … eine Regel-Stelle, kein Drift" — faktisch sind es zwei Stellen, die nur zufällig übereinstimmen.
- **Warum es zählt:** Ändert man eine Regel nur auf der Zähl-Seite, zeigt die Ampel eine Zahl, die Objekt-Liste eine andere — und der Fixer-Lifecycle („Signal schließen, wenn `countFor()==0`") schließt gegen das *alte* Prädikat. Spec 21 verdoppelt den Bestand: Tranche A allein bringt 11 weitere Doppel-Prädikate, C+D nochmal ~10.
- **Level-up-Vorschlag:** Prädikat je Metrik **einmal** definieren (Map `metrik → Closure(Builder)`); die Zähl-Seite ruft `queryFor(...)->count()` statt eigenem Where. Vor Tranche A sinnvoller als danach — dann werden die 11 neuen Checks gleich einstellig definiert.
- **Berührt:** Spec 21 (S1/S4), Spec 05
- **Nachtrag 2026-07-26 (Lauf zu 21·S1a):** Für die **Tranche-A-Checks** ist das Muster jetzt gebaut (`rezeptQualitaetChecks()` — ein Register mit `q`-Closure je Check, `queryFor()` schlägt dort nur nach). Die **13 Altbestand-Metriken** der Kaskaden-Ebenen (`gp`/`basisrezepte`/`gerichte`) doppeln ihr Prädikat unverändert weiter; der Vorschlag ist damit halb eingelöst und wird durch das existierende Vorbild kleiner.

### V-006 · `rezept_verwaist` kann über `updated_at` strukturell nie feuern
- **Kategorie:** datenmodell   **Größe:** M   **Status:** neu
- **Gefunden:** 2026-07-26, Lauf zu 21·S1a · `src/Services/DataQualityService.php` (Check `rezept_verwaist`) vs. `RecipeRecomputeService`
- **Beobachtung:** Der Check verlangt „seit >180 Tagen unberührt". `recipes.updated_at` wird aber von **jedem** Aggregations-/Recompute-Lauf mitgeschrieben. Messung am Bestand: `updated_at` liegt bei **allen** Rezepten zwischen 2026-06-18 und 2026-07-07, **0** Rezepte sind älter als 180 Tage; `last_modified_by` steht auf `aggregator` (1317), `promoter_260` (849), `taxo_313`/`merge_307` (je 63) — also fast überall auf einem Maschinen-Lauf. Unreferenziert wären **2095** Rezepte; nach der 180-Tage-Klausel bleiben **0**.
- **Warum es zählt:** Das Signal ist nicht „gerade grün", sondern **prinzipiell** grün — jeder Bulk-Recompute setzt die Uhr für den gesamten Bestand zurück. Dieselbe Klausel steckt in Spec 21 Tranche D (`foodbook_stale`) und in jeder künftigen „seit … unverändert"-Regel; die Fehlerklasse vererbt sich also.
- **Level-up-Vorschlag:** Fachliche Berührung getrennt stempeln — z. B. `content_touched_at`, gesetzt nur bei Änderungen an *inhaltlichen* Feldern (Name, Zutaten, Zubereitung, Kategorie), nie bei Aggregat-Spalten. Die Trennung ist saubere Beute, weil `RecipeRecomputeService` ausschließlich Aggregate schreibt (Modell-Docblock sagt das explizit) — die Grenze existiert also schon, sie ist nur nicht als Zeitstempel abgebildet. Alternative ohne Schema-Change: Alter aus dem Activity-Log der inhaltlichen Felder ableiten.
- **Berührt:** Spec 21 (Tranche A `rezept_verwaist`, Tranche D `foodbook_stale`), Spec 05

### V-007 · Zwei Hauptgruppen-Bäume, nur einer kann stillgelegt werden
- **Kategorie:** datenmodell   **Größe:** M   **Status:** neu
- **Gefunden:** 2026-07-26, Lauf zu 21·S1a · `foodalchemist_dish_main_groups` (hat `is_inactive`) vs. `foodalchemist_recipe_main_groups` (hat es nicht) · FK in `2026_06_11_000012_create_foodalchemist_recipe_taxonomy_tables.php:45`
- **Beobachtung:** VK-Gerichte hängen an `dish_main_group_id` → `dish_main_groups`; Basisrezepte an `category_id` → `recipe_categories.main_group_id` → **`recipe_main_groups`** (31 Zeilen). Nur die VK-Seite trägt `is_inactive` (6 stillgelegte Gruppen aus der 269er-Neutralisierung). Folge im Code: jeder Check/Filter über „gültige Kategorie" muss nach Rezept-Art verzweigen, und für Basisrezepte lässt sich „stillgelegt" gar nicht ausdrücken — der Tranche-A-Check kann dort nur „Kategorie fehlt" prüfen.
- **Warum es zählt:** Eine Produktions-Hauptgruppe kann man heute nur löschen (`cascadeOnDelete` auf die Kategorien!) oder behalten — es gibt kein „nicht mehr verwenden, Bestand bleibt lesbar". Das ist genau der Fall, für den die VK-Seite `is_inactive` bekommen hat. Solange das fehlt, wandern ausgemusterte Produktions-Gruppen entweder als scheinbar gültig durch die Picker oder reißen beim Löschen Kategorien mit.
- **Level-up-Vorschlag:** `is_inactive` auf `recipe_main_groups` spiegeln (+ optional auf `recipe_categories`) und die beiden Bäume hinter *einem* Vokabular-Contract lesen, damit Checks/Picker/Filter nicht je Rezept-Art verzweigen müssen.
- **Berührt:** Spec 21 (`rezept_kategorie_problem`), VK-Taxonomie Modell A, Settings-Taxonomie-UI
