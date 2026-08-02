# Spec 32 — Controlling-Zentrum

- **Status:** C0–C4 gebaut, Abnahme offen
- **Stand:** 02.08.2026
- **Bezug:** Business-Case CT-01…CT-12 · Zielbild-Plan Phase B (Preiswahrheit) und Phase D (KPI/Erlös)
- **Historischer Vorlauf:** [Spec 12 — Wirtschaftlichkeits-Intelligenz](../_archiv/2026-07-28_dokumentationsbereinigung/PLANUNG/12_Wirtschaftlichkeits_Intelligenz_R2-Rest.md) (R2.3/R2.5)

## 1. Auslöser

Die Controlling-Bausteine waren gebaut, aber über fünf Seiten verstreut: Preisvergleich und
Wareneinsatz-Optimierung unter „Einkauf", die Preissimulation hinter `/kalkulation`, der
Portfolio-Benchmark auf dem Dashboard, die geldrelevanten Befunde in den Signalen. Kein Ort
beantwortete „wie steht der Betrieb wirtschaftlich da".

Schwerer wog: **Befund und Hebel lagen nie nebeneinander.** Man sah den teuren Lieferanten im
Preisvergleich und musste die Fläche verlassen, um die Bezugsquelle zu ändern. Die
Wareneinsatz-Optimierung endete in einer Liste. Die Simulation war eine Sackgasse.

Und es fehlte die halbe Wahrheit: Die Kostenseite hatte ein Ist-Journal
(`foodalchemist_purchase_transactions`), die **Erlösseite nichts**. Ohne Verkaufs-Ist gibt es
keine Ist-Marge, kein Menu-Engineering und keine Soll-Ist-Abweichung — was „Controlling" hieß,
war Kalkulation.

## 2. Entscheidungen (Dominique, 02.08.2026)

| # | Entscheidung | Konsequenz |
|---|---|---|
| 1 | Die Einkaufs-Auswertungen **wandern** ins Controlling | „Einkauf" behält nur das Handeln (Bestellungen); Alt-Routen leiten mit Query-String weiter |
| 2 | **Kein eigenes Controlling-Objekt**, aber **keine Leseschicht** | Gearbeitet wird auf bestehenden Objekten (Lead-LA, Bestellschiene, VK-Snapshot, Signal, TeamSetting) — die haben schon Lifecycle und Audit |
| 3 | Verkaufs-Ist wird **jetzt** gebaut | `sales_facts` + CSV-Import mit Spalten-Zuordnung + Menu-Engineering |

## 3. Leitplanken

- Ein FA-Bereich, kein zweites Composer-Modul (Präzedenz Spec 17).
- **Kein zweiter Rechen-Ort.** Auch Schreibaktionen laufen über bestehende Services — dort hängen
  Tenant-Guard, Recompute-Kaskade und Protokoll.
- **Eine DB-Lesart:** `ek_portion` gegen `sales_net` an der Standard-Darreichung (wie
  `MenuCandidatePoolService` und die W%-Ampel), **nicht** `KalkulationService::recipeHk`.
- Jede Maßnahme ausdrücklich: kein Auto-Umschienen, kein Auto-Publish, keine Auto-Korrektur.
- MCP-Lockstep ist Pflicht-DoD.

## 4. Aufbau

`/controlling` → Lagebild (6 klickbare Kacheln) + Voll-Editor, der beim Aufruf sofort öffnet
(`?editor=0` unterdrückt das). Sieben Tabs im **Server-Modus** — der Alpine-Modus hielte alle
Panels im DOM und ließe die Journal-Optimierung bei jedem Roundtrip mitlaufen.

| Tab | Zeigt | Kann |
|---|---|---|
| Lage | KPI-Kopf, Portfolio-Benchmark, Signal-Verlauf | Zielwerte, Sprung in jeden Tab |
| Preise | Cross-Lieferanten-Vergleich, Rückvergütung, Preis-Ausreißer | in Bestellschiene · **Bezugsquelle umstellen** |
| Wareneinsatz | **Ist gegen Rezeptur (C4)**, Ist gegen optimalen Bezug | **Batch-Umstellung** mit Vorschau |
| Simulation | Was-wäre-wenn ± X %, Scope u. a. **Lieferant** | Szenario rechnen |
| Erfolg | **Verkaufs-Ist-Import**, offene Zuordnungen, **Menu-Engineering** | importieren · zuordnen · **VK-Batch-Freigabe** |
| Geld-Signale | die 6 wirtschaftlichen Signaltypen | Sprung in die Signale-Seite |
| Kennzahlen | Zielmarge, HK2-Zuschlag, Fixkosten, Break-even, MwSt | Zielwerte inline setzen |

Der KPI-Kopf steht in jedem Tab und ist deshalb bewusst billig gehalten (Portfolio-KPIs, ein
Journal-SUM, ein gruppierter Signal-Count, Fixkosten-Summe). Das Einsparpotenzial steht NICHT
im Kopf — es kostet den Optimizer-Lauf und lebt im Wareneinsatz-Tab.

## 5. Etappen und Stand

| Etappe | Inhalt | Stand |
|---|---|---|
| C0 | Sidebar-Gruppe, Route, Editor-Gerüst, Redirects | gebaut |
| C1 | Preisvergleich/Optimierung/Kennzahlen als Panels, Benchmark umgezogen, Signal-Verlauf erstmals sichtbar | gebaut |
| C2 | Lead-LA-Umstellung, Batch mit Vorschau, VK-Batch-Freigabe, Scope `lieferant`, Preis-Ausreißer, Zielwerte inline | gebaut |
| C3 | `sales_facts`, `SalesImportService`, `MenuEngineeringService`, Erfolg-Tab, 3 MCP-Tools | gebaut |
| C4 | `WareneinsatzAbweichungService`, `SignalTyp::WareneinsatzIstAbweichung`, Detektor, Schwelle | gebaut |
| C5 | Doku, Matrizen, ROADMAP/Office | läuft |

## 6. Neue Datenstruktur

`foodalchemist_sales_facts` — Spiegelbild des Einkaufsjournals auf der Erlösseite.

| Eigenschaft | Entscheidung |
|---|---|
| `recipe_id` nullbar | Nicht zuordenbare Zeilen bleiben mit Roh-Text liegen. Verwerfen hieße, Umsatz still aus der Auswertung fallen zu lassen. |
| `source_hash` unique je Team | Team + Tag + Bezeichnung + Bereich. Derselbe Export zweimal ergibt keine Dubletten. |
| kein `customer_id` | Dieselbe Grenze wie beim Einkaufsjournal; `source_scope_label` hält die rohe Bezeichnung für einen späteren Backfill. |
| Sichtbarkeit strikt `team_id` | Ein Umsatz gehört dem Betrieb, der ihn gemacht hat — nicht der Team-Kette. |

**Import:** CSV/TSV, Spalten-Zuordnung durch den Menschen (es gibt kein einheitliches
Kassenformat — ein Import, der auf eine feste Kopfzeile wartet, wäre auf einen Kunden
festgelegt). Kein xlsx-Reader, weil das eine neue Composer-Abhängigkeit hieße (Präzedenz
`FileArticleImportService`). Trockenlauf ist Default. Dateiname statt Pfad, fester Ablage-Ordner
`foodalchemist/import`. Handzuordnungen (`match_method = manual`) überleben jeden Re-Import.

## 7. Was C4 sagt — und was nicht

Ist-Wareneinsatzquote = Einkauf ÷ Umsatz. Abweichung = Einkauf − (Σ verkaufte Menge × `ek_portion`).

Zwei Grenzen, die die Fläche mitliefert:

1. **Kein Bestand.** Ohne Inventur ist der Wert eine Perioden-Rechnung: wer am Monatsende das
   Lager füllt, sieht Schwund, der keiner ist. Deshalb heißt der Wert „Abweichung", nicht
   „Schwund", und der Detektor betrachtet den vollen Vormonat statt eines gleitenden Fensters.
2. **Zuordnungs-Abdeckung.** Unter 80 % zugeordnetem Umsatz wird die Abweichung **nicht**
   ausgewiesen — sie wäre ein Artefakt der eigenen Datenlücke. Die Ist-Quote bleibt gültig,
   sie braucht keine Zuordnung.

Das Signal ist bewusst knopflos: die Differenz kann Verschnitt, Verderb, Überproduktion,
Lageraufbau oder eine falsche Rezeptmenge sein. Das entscheidet die Küche, nicht das System.

## 8. Nicht-Ziele

- Kein Lager/Bestand/Wareneingang (bleibt Spec 17 S4).
- Kein persistenter Controlling-Fall/Periodenabschluss in v1 — nachrüstbar, wenn sich zeigt,
  dass Befunde über Wochen verfolgt werden müssen.
- Kein Auto-Publish von Preisen, kein Auto-Umschienen, keine Auto-Korrektur von Ausreißern.
- Kein Rückweg nach draußen: der Verkaufs-Import ist Eingang, FA bleibt Master.

## 9. Offen

- [ ] Abnahme durch Dominique im echten Browser (Headless rendert die Hauptspalte nicht zuverlässig).
- [ ] Eine echte Verkaufs-Exportdatei eines BHG-Caterers durch den Import fahren und das
      Matching plausibilisieren — der Mapping-Wizard macht uns formatunabhängig, ersetzt aber
      keinen Realdaten-Lauf.
- [ ] demo-Deploy inkl. Migrationen `2026_08_02_000005` (sales_facts) und `000006`
      (Abweichungs-Schwelle).
- [ ] Tenant-Adversarial-Lauf über die neuen Schreibaktionen in die A-01-Matrix aufnehmen
      (Phase A des Zielbild-Plans — C2/C3 haben die öffentliche Angriffsfläche vergrößert).
- [ ] Perf am Zielvolumen: Optimizer, Batch-Vorschau und Matrix mit Querybudget messen.

## 10. Verzahnung

- **Spec 12/R2.5:** die VK-Batch-Freigabe schließt den dort offenen Slice — `VkSnapshotService`
  konnte das seit Juli, hatte aber keine UI und war damit faktisch tot.
- **Spec 12/R2.3:** Menu-Engineering war auf die Q2-Format-Spec gegated. Der Mapping-Wizard löst
  das Gate auf: das Format ist jetzt eine Eingabe, keine Annahme.
- **Spec 17:** die Bestellschiene bleibt der Handlungsort des Einkäufers; Controlling liefert ihr
  nur Bedarf zu.
- **Spec 21:** der neue Signaltyp reiht sich in die bestehende Detektor-Kette ein.
