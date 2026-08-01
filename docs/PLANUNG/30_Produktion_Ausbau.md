# Spec 30 — Produktions-Modul: Ausbau zum Küchen-Werkzeug

**Status:** umgesetzt (E0–E7) · **Entschieden mit Dominique am 2026-08-01** ·
**Vorgänger:** Spec 18 (Produktionsauftrag), Spec 20 (Produktion + Einkauf v2)

Bis hierher konnte das Modul genau eine Sache: *Ziele rein → Rezepte explodiert → Zettel raus.*
Es endete, bevor gekocht wurde. Diese Spec macht aus dem Rechenergebnis ein Arbeitsdokument:
Zeilen sind anfassbar, Arbeit ist verteilbar, Erledigtes ist abhakbar.

| Frage | Entscheidung |
|---|---|
| Job des Moduls | Planung **und** Ausführung, gestuft |
| Bestand | **Nicht-Ziel, bleibt** (Spec 17 S4 unverändert) |
| Editor | voll: Ziele + Zeilen + Zuteilung |
| Zuteilung | volle Kapazitätsplanung — Posten mit Kapazität, Überlast-Warnung, Verteilen über Tage |
| Abarbeiten | **nur abhaken** — Zeilen-Status, keine Ist-Mengen |

---

## 1. Architekturentscheidung: Posten statt Personen

`ARCHITEKTUR.md` §10 führt „Touren- und **Personalplanung**" unter den Nicht-Zielen; Spec 18 und
Spec 20 notieren beide „keine Stationen-/Personal-Zuweisung in der Produktion". Kapazitätsplanung
berührt diese Grenze — sie wird deshalb hier neu gezogen, **an der Sache statt am Menschen**:

> Wir planen **Posten** (Arbeitsplätze mit einer Kapazität in Minuten pro Tag), **nicht Menschen**.
> Kein Schichtplan, keine Verfügbarkeiten, keine Abwesenheiten, keine Personalstammdaten.
> Der Verantwortliche je Zeile bleibt ein **freier Name** — ein Etikett, kein Datensatz.

**Konsequenz, die im Code durchgehalten wird:** `assignee` ist ein String und bleibt einer — kein
FK, kein `user_id`, kein Index, und es gibt **keine einzige Aggregation** darüber. Auslastung wird
ausschließlich je *Posten* gerechnet. Das ist die Wand gegen den Weg Autocomplete → `user_id` →
Verfügbarkeiten → Schichten → Stundenkonten. Sobald jemand „Auslastung je Person" baut, ist die
Grenze gefallen — und man sieht es im Diff. Wording konsequent „Verantwortlich", nie
„zugewiesen an", nie „Mitarbeiter".

**Rückweg:** `foodalchemist_production_stations` droppen, `station_id`/`assignee`/`vorlauf_tage`
aus den Zeilen entfernen. Der Rest des Moduls hängt nicht daran.

## 2. Architekturentscheidung: ein Auftrag = ein Tag, Vorproduktion ist ein Offset

Spec 18 führt „Mehrtages-Produktionszeiträume" als Nicht-Ziel. Statt den Auftrag zu einem Zeitraum
zu machen, trägt die **Zeile** einen Rückwärts-Offset `vorlauf_tage`. Der Auftrag behält genau ein
`production_date` — Semantik ab jetzt: **Liefer-/Einsatztag** (Spaltenkommentar, Docblock, Label;
umbenannt wird nichts, das kostet MCP-Tools, Blades, Tests und Docs und bringt null Funktion).

Ein Offset statt eines absoluten Datums, weil das Datum still falsch würde: verschiebt sich das
Event von Freitag auf Samstag, wandert der ganze Plan automatisch mit, und die Invariante
„Vorproduktion ≤ Liefertag" ist strukturell unverletzbar statt geprüft.

`plan_date` (= `production_date − vorlauf_tage`) ist eine **abgeleitete** Spalte für
`WHERE … BETWEEN`; Datumsarithmetik divergiert zwischen SQLite (Testsuite) und MySQL. **Genau ein
Schreiber:** `ProductionOrderService::syncPlanDates()`.

**Unangetastet bleiben:** kein Bestand · kein Wareneingang · kein Netting · kein Auto-Sync zur
Bestellung · keine Ist-Mengen (durch „nur abhaken" bleibt Ist-vs-Plan-Ausbeute-Tracking
Nicht-Ziel).

---

## 3. Der Kern-Konflikt: manuelle Eingriffe vs. Spec-18-P2

P2 lautet: „Volle Neu-Explosion bei jeder Änderung — Zeilen komplett löschen+neu anlegen (nie
additiv patchen)." Kein Schönheitsentscheid: die Ansatz-Rundung ist nicht additiv
(`ceil(a)+ceil(b) ≠ ceil(a+b)`). Manuelle Eingriffe scheinen dem direkt zu widersprechen.

Aufgelöst wird das, indem P2 **nicht aufgeweicht**, sondern sein Zuständigkeitsbereich begrenzt wird:

**a) Zwei disjunkte Populationen.** `origin` (`computed` | `manual`). Der Recompute löscht nur
`where('origin','computed')`. Freie Positionen liegen außerhalb.

**b) Ein Overlay, das den Recompute überlebt.** Rechen-Wahrheit gehört dem Recompute, Overlay
gehört dem Menschen (`ProductionOrderService::OVERLAY_FELDER`), gerettet vor dem Löschen und per
`recipe_id` wieder aufgesetzt. **`recipe_id` trägt als Schlüssel**, weil
`PlanungsblattService::explodiere()` genau eine Zeile je Rezept emittiert (Top- und Sub-Beitrag
summiert) — festgenagelt per Unique-Index und Test.

**c) Der Override zerstört die Rechnung nicht.** `ansaetze` behält den Explosionswert,
`manual_ansaetze` trägt den Override, `ansaetze_effektiv` ist ein Accessor. Nur so ist
„manuell 2 — berechnet wären 3 · zurücksetzen" darstellbar.

**d) Der Override propagiert NICHT nach unten.** 2 statt 3 Ansätze Sauce ändern weder GP-Bedarf
noch Eltern-Rechnung noch Einkaufs-Übergabe (die liest `targets`, nicht Zeilen). Es ist eine
**Küchen-Korrektur, kein Bedarfs-Eingriff**.

| Kollisionsfall | Auflösung |
|---|---|
| Zeile streichen, Explosion erzeugt sie weiter | `is_struck` statt Löschen. Bleibt sichtbar (durchgestrichen, „wiederherstellen"), fällt aus allen Summen und aus dem Druck. |
| Manuell überschriebenes Rezept fällt aus der Explosion | Overlay **verworfen**, Verwurf **gemeldet** (`warnungen`). Kein Auto-Promote, keine Parktabelle. Ehrlicher Verlust mit lauter Meldung schlägt stillen Zustand. |
| Freie Position kollidiert mit später auftauchendem Rezept | Kein Auto-Merge. Manuelle Zeilen haben `recipe_id = NULL`, die optische Dublette bleibt, der Nutzer löscht sie. Wer „ein Ansatz obendrauf" will, legt ein **Ziel** an — sonst umgeht er die Einkaufs-Übergabe. |

**Guard-Matrix** — die Regel dahinter: *was die Explosion erzeugt, ist ab `in_progress`
unantastbar; was der Mensch erzeugt oder was neben der Explosion liegt, bleibt pflegbar.*

| Vorgang | `planned` | `in_progress` | `done`/`cancelled` |
|---|:--:|:--:|:--:|
| Ansätze-Override, Zeile streichen | ✔ | ✘ | ✘ |
| Freie Position, Notiz, Zuteilung | ✔ | ✔ | ✘ |
| Abhaken / Zeilen-Status | ✘ | ✔ | ✘ |
| Auftrag löschen | ✔ | ✘ (stornieren) | nur `cancelled` |

Zuteilung ist im `in_progress` erlaubt, weil Posten/Person/Tag Disposition sind und die Realität
mitten im Service umbesetzt — der Recompute ist dort ohnehin ein No-op.

---

## 4. Kapazität

**Posten sind eine eigene Tabelle** (`foodalchemist_production_stations`), ausdrücklich **kein**
`foodalchemist_vocab_*`. Zwei harte Gründe aus dem Code:

1. Vokabular-Tabellen werden beim Import **geleert** (`ImportSliceCommand`) — Kapazitätsminuten
   wären beim nächsten Re-Import weg, und mit ihnen jede `station_id`.
2. Vokabular-`slug` ist global unique bei nullable `team_id` — zwei Betriebe könnten nicht beide
   eine „Patisserie" mit eigener Kapazität führen. Kapazität ist physisch und standortgebunden.

Kapazität ist per Definition **netto** (Rüsten/Reinigen/Pause abgezogen) — darum keine eigene
`ruestzeit_min`-Spalte, die eine zweite erfundene Zahl wäre. Zwei Kombidämpfer = 960 min, darum
auch keine `parallelitaet`.

⚠️ **Geerbte Posten sind Vorlagen, keine geteilte Ressource.** Die Auslastung wird **team-strikt**
gerechnet (`o.team_id = :team`, *nicht* `visibleToTeam`) — sonst blockiert die Produktion des
Eltern-Betriebs die Posten des Kind-Betriebs.

**Die Überlast-Warnung ist opt-in:** ein Posten ohne `kapazitaet_min_pro_tag` warnt nie.
≤85 % ok · ≤100 % eng · >100 % Überlast. Kein Modal, kein Blockieren.

⚠️ **Die Zahlen sind nur so gut wie `work_time_min` an den Rezepten** — und das Feld ist vielfach
leer. Jede Summe wird deshalb **mit ihrer Lücke** ausgegeben („340 min · 6 Zeilen ohne
Arbeitszeit"). Eine Ampel, die eine halbe Datenlage als Wahrheit verkauft, ist schlimmer als keine.

**Keine Auto-Verteilung.** Ein Verteil-Algorithmus bräuchte die Information, *welcher Posten
welches Rezept überhaupt kann* — die gibt es im Datenmodell nicht (Equipment↔Posten ist nicht
abgebildet). Ein Vorschlag, der Fähigkeiten ignoriert, produziert selbstbewussten Unsinn. Statt
dessen: Bulk-Zuteilung („alle unverplanten → X"), Sortierung nach Arbeitszeit, Rest-Kapazität je
Tag als Anzeige. Sobald Equipment↔Posten gemappt ist, wird Auto-Verteilung sinnvoll — das ist der
Folgeschritt, nicht dieser.

**Der Bucket „Nicht zugeteilt" ist Pflicht:** unverplante Arbeit zählt gegen keine Kapazität, wird
aber als Minutenzahl ausgewiesen. Sonst wäre sie unsichtbar, nur weil sie an keinem Posten hängt.

---

## 5. Ausführung: nur abhaken

`line_status` (`open|in_progress|done|skipped`) + `done_at` + `done_by` (kein FK — Protokoll-Notiz,
Konvention wie `created_by`). **Keine Ist-Mengen.**

**`is_struck` ≠ `skipped`:** `is_struck` ist ein *Planungs*-Entscheid im `planned` („produzieren wir
nicht", fliegt aus den Summen); `skipped` ist ein *Ausführungs*-Ergebnis im `in_progress` („hätten
wir sollen, haben wir nicht", bleibt als Soll drin).

**Der Auftragsstatus wird nie automatisch weitergeschaltet.** `→ in_progress` friert einen Snapshot
ein, `→ done` meldet nach außen — ein Haken in der Küche darf beides nicht auslösen. `setStatus(Done)`
mit offenen Zeilen wird **nicht blockiert** (das ließe die letzte Person der Schicht gegen die
Software kämpfen), sondern nachgefragt; offene Zeilen bleiben `open` — **kein** Auto-Skip, das
würde das Protokoll zerstören, dessentwegen es existiert.

**Abhaken ist im `planned` verboten**, weil dort ein Recompute die Zeile unter der Hand ersetzt.
Genau deshalb stehen `line_status`/`done_at`/`done_by` **nicht** in `OVERLAY_FELDER`: Recompute und
Abhaken schließen sich strukturell aus (Invariante ist getestet). Bräche sie, hätte man stumm
eingefrorene Häkchen an inzwischen geänderten Mengen.

---

## 6. Etappen und Stand

| Etappe | Inhalt | Stand |
|---|---|---|
| E1 | Zeilen-Eingriff: `origin`, Overlay, Override, Streichen, freie Positionen | ✅ |
| E2 | Zeilen-Tab im Editor | ✅ |
| E3 | Posten + Kapazität + Vorproduktion + Tagesplan | ✅ |
| E4 | Hauptpanel: serverseitige Pagination (Audit MVP-033), Filterbaum, KPI, Spalten-Ansichten | ✅ |
| E5 | Detail-Panel wiederbelebt (v3-Cockpit, war verwaist) | ✅ |
| E6 | Küchen-Sicht „Abarbeiten" | ✅ |
| E7 | Spec, Benutzerhandbuch, MCP-Lockstep, Matrizen | ✅ |
| E0 | Kleinkram: doppelter Recompute beim Anlegen, `basisEinheit`-Reset, Angebot-Ziel ohne Mengenfeld | ✅ |

**MCP im Lockstep:** `production_orders.LINE_OVERRIDE` · `LINE_ASSIGN` · `LINE_STATUS` · `DELETE`
neu; `buffer_pct` in `UPDATE` ergänzt; der Docblock von `UPDATE_LINE` behauptete wörtlich, Ansätze
seien „NICHT manuell überschreibbar" — korrigiert, das war eine Falle für den nächsten Agenten.

---

## 7. Offen / Folgeschritte

- **Equipment ↔ Posten mappen** — Voraussetzung für sinnvolle Auto-Verteilung.
- `ARCHITEKTUR.md` §10: die Zeile „Touren- und Personalplanung" um die Präzisierung aus §1
  ergänzen (Dokument von Dominique, Änderung liegt bei ihm).
- Bestand bleibt Nicht-Ziel — bei Bedarf über Spec 17 S4, nicht hier.
