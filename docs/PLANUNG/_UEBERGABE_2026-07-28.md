# Übergabe — Stand 2026-07-28, Nachmittag

> Für eine neue Session: alles, was du brauchst, um direkt weiterzumachen. Selbst-erklärend, keine Vorgeschichte nötig.
> **Reihenfolge der Lektüre:** dieses Dokument → [_Fahrplan_Routine_Umsetzung.md](_Fahrplan_Routine_Umsetzung.md) (die **Arbeits-Reihenfolge-Tabelle oben** ist maßgeblich) → die Spec-Datei der jeweiligen Etappe.

---

## 1. Wo wir stehen — in einem Satz

**Die Fähigkeiten sind der Nutzung weit vorausgelaufen.** 61 autonome Läufe haben Spec 03 (KI-Erstell-Strecke), Spec 21 (Signal-System), Spec 12/R2.4 (Marge-Solver), Spec 13 (Katalog-Ingest) und den halben Spec-22-Härtungsblock gebaut. Aber: es gibt **kein einziges befülltes Planungsgerüst** in Produktion, die Datenqualitäts-Heilung ist auf demo **nie gelaufen**, und der erste echte End-to-End-Lauf von R2.4 fand am 28.07. in einer Test-Fixture statt — nicht mit echten Daten.

**Empfehlung, mit der die neue Session anfangen sollte:** ein echter Kundenfall komplett durch (§3), nicht die nächste Etappe.

---

## 2. Verifizierter Zustand (am 28.07. gemessen, nicht vermutet)

### Drei Datenbanken, drei Rollen — nicht verwechseln

| DB | Rolle | Rezepte | Planungs-Tabellen |
|---|---|---|---|
| `foodalchemist` (lokal) | **Testbett** (Function-First-Fixture) | 95 (26 VK im Pool) | ✓ · 4 Gerüste, alle Pest-Fixture (Owner-IDs 990002+) |
| `foodalchemist_full` (lokal) | Volumen-/Realitäts-Referenz | 3218 | ✗ **fehlt** |
| **demo** (`demo.bhgdigital.de`) | **Master** | **2297** (Team 6) | ✓ · **1 Gerüst, leer** (Foodbook 8, 0 Slots/Regeln) |

⚠️ **Der lokale Abzug ist vom 12.07., 09:22** (jüngste Migration darin: `2026_07_12_000020`). Die Planungs-Migration ist vom **13.07.** — einen Tag später. Deshalb fehlt die Tabelle dort. **Jede „Master"-Zahl im [_Daten_Journal.md](_Daten_Journal.md) misst diesen 16-Tage-alten Stand, nicht demo.** Das hat am 28.07. schon zu einem Fehlschluss geführt (D-019 → S3c-Zurückstellung).

**demo erreichbar per MCP:** `https://demo.bhgdigital.de/mcp/sse`, Team 6 „Demo". Erst `core.context.GET`, dann `tool_registry.SEARCH`, Aufrufe über `execute`. Read-only-Tools sind als `read_only: true` markiert. SSH ist vorhanden (Dominique), DB-Name und App-Pfad auf demo sind **nicht dokumentiert** — erfragen.

### R2.4 funktioniert — erster Beweis am 28.07.

Testgerüst **#6** liegt lokal auf Konzept `#990001`. 3 Gänge / 5 Positionen, Band 25–45 €, Diät-Quote „min 1× vegi". Ergebnis: alle Positionen befüllt, **rollen-treu** (`hg_fremdlinge: 0`), **53 ms**, VK 34,27 € p. P. (Ziel 35), DB 24,75 € p. P., Wareneinsatz 27,8 %. `erklaere()` nennt `slot_rollen` als bindend; Preisband und Diät-Quote binden **nicht** (Deltas 0).

**Aber: `verfahren = heuristik`, nicht `exakt` — `knoten: 0`, Branch-and-Bound lief gar nicht.** Ursache verstanden: S3b macht bewusst **kein `reject`** im Slot-Filter (Lesart (b)), also behält jeder Slot alle **26** Kandidaten → Suchraum ≈ C(26,2)² × 26 ≈ **2,7 Mio** gegen `EXAKT_RAUM_MAX = 200_000` → `INF`, B&B übersprungen. **Folge: der exakte Solver ist schon bei einem 3-Gang-Menü unerreichbar, und jedes Delta in der Erklärung ist nur eine Untergrenze** (das System sagt das selbst im `hinweis` — ehrlich, aber schwächer als „so weit bist du vom Optimum").

Das ist ein Trade-off, der bei der Lesart-Entscheidung nicht auf dem Tisch lag: **(b) erhält Füllbarkeit, kostet Beweisbarkeit.**

### Die Perf-DoD ist offen
„~1.000 Gerichte < 15 s" ist **nie gemessen** worden. Braucht Volumen — 26 Kandidaten sind nicht 1.000. Das ist das letzte offene Gate an Spec 12.

---

## 3. Der empfohlene erste Schritt: ein echter Fall

Nimm ein Menü, das ohnehin kalkuliert werden muss. Dann: **Gerüst auf demo anlegen → Solver assemblieren lassen → ins Foodbook → bepreisen → anschauen.** Ein Nachmittag.

Er beantwortet die Fragen, die derzeit theoretisch diskutiert werden:
- **Beweisbar optimal oder gut-und-erklärbar?** Daran hängt die exakt-vs-füllbar-Frage. Am Schreibtisch nicht entscheidbar.
- **Sehen echte Slot-Titel wie Rollen oder wie Verkaufszeilen aus?** Das entscheidet S3c. (Im 12.07.-Abzug waren 5 von 6 Marketing-Prosa: „Main – Hyper Local · Geschmack aus der Region, neu gedacht".)
- **Ist die Portfolio-Enge real?** Im Testbestand haben **8 von 13** Hauptgruppen genau **ein** Gericht (D-021). Wenn das draußen auch so ist, ist „Rollen-Treue" ein Einkaufs-Thema, kein Software-Thema.

**Zweiter Schritt, unabhängig davon:** Spec 05 **Etappe 1 auf demo fahren** — deterministisch, kostenlos, 0 Datenabfluss. Die Spec sagt selbst „demo zeigt noch den ungeheilten Stand"; die lokalen Läufe waren nur Generalprobe. Reihenfolge: Backup → `lead-la-repick --apply` → `gp-allergen-backfill --apply` → `recompute --all --apply` → `data-quality --signals`. Solange die Basis ungeheilt ist, misst jede Ampel darüber auf Sand.

---

## 4. Offene Entscheidungen (alle bei Dominique)

| # | Entscheidung | Kontext |
|---|---|---|
| 1 | **exakt oder füllbar?** | Lesart (b) ist gebaut. Mittelweg denkbar: B&B **nur über die rollen-treue Teilmenge** exakt fahren → Optimum innerhalb der Rolle bewiesen, Bruch bleibt als Ausweichoption sichtbar. Erst nach dem echten Fall entscheiden |
| 2 | **Slot: zwei Felder oder eins?** | Rolle als FK **neben** dem Titel, oder Titel bleibt beides. **Es gibt 0 echte Slot-Zeilen zu migrieren** — die Wahl ist völlig frei, kein Altlast-Zwang. Betrifft `12·S3c` (geparkt) und R4.1 |
| 3 | **Master-Sync demo → lokal?** | Bringt aktuelles Schema + realistisches Volumen (⇒ Perf-DoD messbar). Bringt **keine** Datenqualität (demo ist ungeheilt). **Einbahnig: nur demo → lokal, nie umgekehrt.** Off-peak (264k Lieferantenartikel). Alten Abzug vom 12.07. behalten |
| 4 | **V-Backlog-Laufband** | 80 V-Einträge, 21 D-Einträge, ~2 neue je Etappe, grob 15 aufgelöst. Wächst schneller als es abgearbeitet wird. Der Ausweg ist Nutzung, nicht mehr Etappen — viele Einträge betreffen Pfade, die noch niemand gegangen ist |
| 5 | Test-Reste auf demo | Slot-Titel `test` und `fjsfhsjdfhskjdf`. Nur Aufräumen |

---

## 5. Die Routine

**`fa-specs-umsetzung` ist DEAKTIVIERT** (Scheduled-Tasks / „Scheduled" in der Seitenleiste). 61 Läufe gefahren, stündlich, ~1 Etappe pro Lauf. Sie schaltet sich nicht selbst wieder ein.

**Ihr Arbeitsdokument** ist [_Fahrplan_Routine_Umsetzung.md](_Fahrplan_Routine_Umsetzung.md) — die **Arbeits-Reihenfolge-Tabelle oben überstimmt die Phasen-Nummern**.

**Noch offen für sie:** `12·S3b-2` (V-081, schließt eine halb erfüllte DoD) · `22·H4b-2` · `22·H5` (der große: „eine Wahrheit an sieben Stellen", V-018) · `22·H6` · `22·H7`.
**Ausdrücklich NICHT ihre Sache:** `08·P6b` (Vault-Material) · `05·Etappe 2` (demo/Egress/Review-Queues) · `Phase 7` (Vektor-DB, wartet auf Martin) · `12·S3c` (geparkt, s. Entscheidung 2).

**Zwei Beobachtungs-Kanäle**, die sie je Lauf füllt:
- [_Verbesserungs_Backlog.md](_Verbesserungs_Backlog.md) — „der Code macht das falsch" (Entwickler-Sicht, 80 Einträge)
- [_Daten_Journal.md](_Daten_Journal.md) — „die Daten sehen so aus" (F&B-Sicht, 21 Einträge)

**Bug-Politik** steht im Fahrplan („🐞"): Klasse 1 selbst verursacht → gleicher Commit · Klasse 2 eng+beweisbar+lokal → eigener `fix()`-Commit mit vorher rotem Test · Klasse 3 alles andere (Money-Path, Auswahlregel, unklare Reichweite) → melden auf Office Board 54, **nicht** fixen. Ein Bug-Fund verschiebt nie die Etappe.

**Cluster-Triage** der Backlog-Befunde: [22_Haertung_Verbesserungs_Cluster.md](22_Haertung_Verbesserungs_Cluster.md) — 9 Cluster, 7 Etappen H1–H7.

---

## 6. Fallen, die am 28.07. real zugeschlagen haben

1. **Tests brauchen `php -d memory_limit=1G vendor/bin/pest`** (aus der Sandbox). Im 128M-Default crasht die Suite mit *Fatal: memory exhausted* — das **sieht wie ein Code-Fehler aus, ist Konfiguration**. Suite-Stand ~1700 Tests, ~14 Min.
2. **`view:cache` meldet Erfolg für ein kaputtes Blade** — es schreibt PHP heraus, ohne es zu parsen. Und `php -l` auf die Blade-**Quelle** sagt nichts. Was wirklich prüft: `view:clear && view:cache && for f in storage/framework/views/*.php; do php -l "$f"; done`.
3. **Nie `git add -A`** — Dominique editiert parallel. Nur eigene Dateien gezielt stagen.
4. **Nur `platforms-foodalchemist` anfassen** — Core/CRM/UI/Fremdmodule sind tabu.
5. **Der Master-Abzug ist 16 Tage alt** (s. §2). Nicht mit demo verwechseln.
6. **Kein Verhaltenswechsel ohne Golden-Test.** Am 28.07. hat der Golden-Test aus 12·S3a einen echten Drift gefangen (`COALESCE(ai_confidence, 0…)` statt `1.0` beim Batch-Umbau) — ohne ihn hätte sich der Pairing-Graph **still** verschoben. Bestands-Fixtures fangen das oft nicht, weil sie die Annahme enthalten, die sie bestätigen.

---

## 7. Was ich nicht empfehlen würde

Am Solver weiterschrauben, bevor ein echter Fall gesagt hat, was zählt. S3c, der exakte Pfad, Rollen-Feinheiten — das sind Verfeinerungen an einer Maschine, deren Anforderungen noch geraten werden.

---

*Erstellt 2026-07-28. Letzter Routine-Commit: `ebf0ec6` (Lauf 61). Der Lauf hat `12·S3c` korrekt **geparkt statt gebaut**, nachdem die Zurückstellung mitten im Lauf im Fahrplan landete — seine angefangene Arbeit (7 Dateien + Migration) hat er verworfen. Es liegt kein S3c-Code im Repo.*
