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
