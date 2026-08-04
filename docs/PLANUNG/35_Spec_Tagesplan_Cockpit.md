# Spec 35 — Tagesplan zum Küchen-Cockpit: Anleitung, Kapazität, Wandmodus

> **Tracking:** Office Dev-Package 23, Features-Board. **Kein DB-Schema-Umbau** — alle Felder
> existieren bereits. Reine Code-/View-Spec.

**Status:** produktreif zu Ende geplant · Kern K1–K3 entschieden mit Dominique am 2026-08-04 ·
**Vorgänger:** Spec 30 (E8 Tagesplan-Ausgabe), Spec 30 Stufe 3 (Auto-Produktionsplaner, Rollen/Besetzung),
Spec 27 (Step-by-Step-Zubereitung)

**Umsetzungsstand 2026-08-04:** K1–K5 plus Produktreife-Nachlauf umgesetzt: Readiness-Gates,
Override-/Abschlussgründe, Entblocken, optimistische Versionierung, echtes Gericht-Blatt,
eigener Wandmonitor mit Tagesfenster/Posten-Lanes/Touch-Karten, Feature-Kill-Switch und MCP-
Schreibtools für Start/Finish/Block/Unblock. Zusätzlich getrennt: Die Produktions-Hauptseite ist
das **Küchenchef-Dashboard** für Steuerung/Auslastung im Modul-Hauptpanel (3/7/14/30 Tage), die
Tagesplanung bleibt als **Tagesordnung Editor** die separate Koch-Detailseite für Speisen, Anleitung,
Equipment, Posten/Gesamtproduktion — im gleichen Editor-Duktus wie der Produktionsauftrag-Editor.
Der Leitstand hat zusätzlich einen freien Von/Bis-Kalenderzeitraum
für gezielte Forecast-Fragen wie „Wie sieht der nächste Monat aus?", unabhängig vom Listenfilter.
Inspiriert vom Lækkerai-Küchenleiter-
Dashboard zeigt er zusätzlich Planung in Manntagen, Change Log, Produktion-Ampeln und
Performance-/Engpassbalken je Posten.
Automatisiert abgesichert im Produktionskranz (inkl. separater Route `/produktion/wandmonitor`,
150 Tests grün). Offen vor finaler Produktionsfreigabe: MySQL-Performance-Messung, Browser-/
Touch-/Druckabnahme gegen eine laufende lokale/prod-nahe MySQL-App und Pilotbetrieb.

Der Tagesplan aus Spec 30 E8 kann heute eine Sache: *Tag × Posten, eine Zeile je Rezept, als Liste.*
Im Betrieb reißt das an drei Stellen: das Posten-Blatt und der Wandblick zeigen nur den **Gerichtsnamen**
— die eingefrorene Zubereitung (Basisrezepte + Schritte) bleibt unsichtbar; die Posten-Kapazität rechnet
eine Besetzung von zwei Köpfen **nicht hoch**; und der Wandmodus ist nur die Liste in groß, kein Arbeitsgerät.
Diese Spec macht aus der Ausgabe ein **Cockpit**: die schon vorhandene Zubereitung wird sichtbar, die
Kapazität ehrlich, die Wand anfassbar.

| Frage | Entscheidung |
|---|---|
| Kapazität, wenn Posten **Besetzung** *und* manuellen `Min./Tag` hat | **Besetzung gewinnt** (Köpfe × Schicht überschreibt den manuellen Wert) |
| Anleitung im Tagesplan/Druck/Wand | **Basisrezepte + Step-by-Step**, per **Umschalter Posten ↔ Gericht** |
| Wandmodus | **volles Cockpit** — Ampeln, abarbeiten, „Als nächstes", Planen als Link |
| Datenmodell | **keine Migration** — `steps_snapshot`, `zutaten`, `tiefe`, `is_basisrezept`, `besetzung`, `schicht_minuten` sind da |
| Abhaken | bleibt an Auftrag `in_progress` gebunden (Invariante Spec 30 §5, **nicht** aufgeweicht) |

---

## 1. Kapazität: Besetzung gewinnt

Spec 30 §4/§1a hat die Kapazität eingeführt und in Stufe 3 um **Rollen-Besetzung** ergänzt: ein Posten wird
mit einer Anzahl je Rolle besetzt, daraus leiten sich Kapazität (`Köpfe × Schicht`) und Kosten ab. Der Bug,
den Dominique meldet („2 Personen → zählt nur eine"), ist **kein Rechenfehler**: `abgeleiteteKapazitaet()`
rechnet `2 × 480 = 960` korrekt. Aber `kapazitaetAm()` gibt einem manuell gesetzten `kapazitaet_min_pro_tag`
(480) **Vorrang** vor dem abgeleiteten Wert — die 960 werden verworfen. Sichtbar wird das doppelt schief,
weil die Kosten-Seite (`ProductionPlanService::rateProMin`) beide Köpfe zählt: der Posten kostet nach zwei
Köpfen, kann aber nur nach einem.

> **Neue Regel:** Ist ein Posten **besetzt**, folgt die Kapazität der Kopfzahl. Der manuelle `Min./Tag`
> greift nur noch bei Posten **ohne** Besetzung. Die Wochentag-Abweichung bleibt oberster Vorrang
> (sie ist ein bewusster Tages-Sonderfall, den jemand explizit tippt).

Vorrang in `kapazitaetAm()` neu: **Wochentag-Override → Besetzung (abgeleitet) → manueller `Min./Tag` → keine Kapazität.**

**Schicht-Default (der Punkt, an dem der Fix real wird):** `abgeleiteteKapazitaet()` gibt `null` zurück,
wenn `schicht_minuten` leer ist — und das ist bei den Bestands-Posten der Fall (das Feld zeigt Platzhalter
480, keinen gespeicherten Wert). Ohne Default liefe „Besetzung gewinnt" ins Leere. Deshalb: fehlt die
Schicht, gilt eine dokumentierte **Standardschicht `STANDARD_SCHICHT_MIN = 480`** (8 h netto). „2 Köpfe"
rechnet dann auch ohne gepflegte Schicht auf 960 hoch.

**Die Grenze aus Spec 30 §1/§1a bleibt unangetastet:** weiterhin nur Rollen-/Posten-Ebene, keine Personen,
kein Schichtplan. Es ändert sich nur der Vorrang zwischen zwei bereits existierenden Zahlen.

**Bewusste Verhaltensänderung:** Bestands-Posten mit gespeichertem `Min./Tag=480` **und** Besetzung springen
auf ihren abgeleiteten Wert (z. B. 960). Genau das ist gewollt. In den Einstellungen wird der aktive Wert
klar ausgewiesen („wird als Kapazität genutzt — überschreibt Min./Tag").

---

## 2. Die Zubereitung liegt schon da — keine Neu-Explosion

Der Reflex wäre, für „Gericht + Basisrezepte mit Anleitung" die Rezept-Explosion in die Ausgabe zu ziehen.
Das ist **nicht nötig**. Spec 30/Spec 27 haben die Arbeit schon getan: Jede
`foodalchemist_production_order_lines`-Zeile **ist** ein Rezept — das Gericht (tiefe 0) **und** jedes
Basisrezept jeder Tiefe, eine Zeile je Rezept (Unique-Index, Spec 30 §3b). Jede Zeile trägt ihre `zutaten`
(JSON) und ihre **`steps_snapshot`** (die beim Rechnen eingefrorene Schrittfolge inkl. Foto-Referenzen,
Spec 27) sowie `zubereitung` als Freitext-Fallback.

Der Tagesplan zeigt nur die Hülle, weil `ProductionCapacityService::tagesplanZeilen()` diese Spalten schlicht
**nicht selektiert** und die Blades sie nicht rendern. Es fehlt Laden + Anzeigen, nicht Rechnen.

> **Ein Punkt speist alle Ausgaben:** `tagesplanZeilen()` versorgt Screen-Liste **und** den Posten-Blatt-Druck
> (die Druck-Route ruft denselben Service). Eine Erweiterung deckt beide.

Damit die Screen-Liste leicht bleibt (kein Massen-Decode hunderter Schritt-Blobs übers Fenster), lädt
`tagesplanZeilen()` die Anleitung nur auf Verlangen (`mitAnleitung=true`, vom Druck gesetzt). Auf dem Screen
zeigt das **Detail-Panel** die Schritte (die Daten liegen dort über `ProductionOrderService::detail()` schon
bereit), im Cockpit ein **Overlay** je angeklickter Karte (Eloquent-Load, dekodiert automatisch).

**Wiederverwendet, nicht neu gebaut:** das Render-Partial `dokumente/partials/schritt-karten.blade.php`
(+ `-css`). Es rendert Phasen-Überschriften, Schritt-Nummern, Fotos, fällt auf `zubereitung`-Freitext zurück
und zeigt nichts, wenn beides leer ist — genau die Semantik, die der Produktionsschein schon nutzt.

**Die Pflege-Lücke wird sichtbar gemacht:** wo Rezept *und* Basisrezepte noch keine Schritte/Zubereitung
haben, steht **„keine Anleitung hinterlegt"** statt Leere. Das ist die Lücke, die Dominique erkennt — sie
wird handlungsfähig, nicht kaschiert.

---

## 3. Der Umschalter Posten ↔ Gericht (die „Idee")

Zwei legitime Sichten auf denselben Tag, ein Umschalter (`ansicht` ∈ `posten` | `gericht`, URL-Prop):

| Ansicht | Gruppierung | Für wen / wann |
|---|---|---|
| **Posten** (Default) | Tag → Posten → dessen Zeilen, jede zur Anleitung aufklappbar | „Was mache **ich** an meinem Posten heute" — Postenzettel, Wand |
| **Gericht** | Tag → **Auftrag** → Gericht (tiefe 0) + darunter seine Basisrezepte (tiefe ≥1) eingerückt, jede mit Anleitung | „Was gehört zu **diesem Gericht**" — der alte Produktionsschein-Blick |

**Warum je Auftrag statt striktem Gericht-Baum:** Ein Basisrezept, das zwei Gerichte desselben Events nutzt,
existiert als **eine** Zeile (Dedup je Auftrag, Spec 30 §3b). Ein strikter Baum müsste es unter beiden Eltern
duplizieren. Die Gruppierung je Auftrag mit `tiefe`-Einrückung ist die ehrliche, dublettenfreie Darstellung
— dasselbe Muster wie `dokumente/produktionsauftrag.blade.php`.

**Basisrezepte queren Posten:** Der Fond läuft am Saucier, das Gericht am Gardemanger. Deshalb sind beide
Sichten nötig — die Posten-Ansicht zeigt jedem Posten seine Zeilen (inkl. der Basisrezepte, die dort
produziert werden), die Gericht-Ansicht zeigt den Zusammenhang postenübergreifend. Der Umschalter gilt für
Screen-Liste **und** Posten-Blatt-Druck (die Druck-URL trägt `ansicht` mit).

---

## 4. Wandmodus als Cockpit (Läkkerai-Vorbild)

Der Wandmodus (`?display=wall`) bleibt dieselbe Komponente `Tagesplan.php` — State, Aktionen, Filter,
Auto-Planer werden wiederverwendet — bekommt aber im `$istWall`-Zweig ein eigenes Informations-Layout
(Partial `_cockpit.blade.php`). Läkkerai zeigt Manntage, Change-Log, Produktions-Ampeln und
Performance; wir bauen die Teile, die aus **vorhandenen** Daten ehrlich ableitbar sind:

| Sektion | Quelle | Neu? |
|---|---|---|
| **(a) Status-Leiste heute** — Posten-Ampeln (`stufe`: ok/eng/ueberlast), Zähler offen/erledigt/Überlast | `auslastung()` + Filter über Tageszeilen | nur Zähler ableiten |
| **(b) Manntage-Übersicht** — Balken je Tag ∝ `Σ geplant_min / 480` | `auslastung()` je Tag | kleine Ableitung in `render()` |
| **(c) Posten-Spalten heute** — anklickbare Gericht-Karten (Name, Ansätze, Zeit, Auftrag/Liefertag) | Tageszeilen | Layout |
| **(d) „Als nächstes"** — Top-N offener Zeilen, sortiert nach läuft/Ampel-Schwere/Posten | reine Sortierung über geladene Daten | Helfer |
| **(e) Change-Log** — letzte Zeilen-Aktivität im Fenster | neue in-Modul read-only Methode | s. u. |
| **(f) Planen-Link** — „Tagesplan neu vorschlagen" (`vorschlagen`), Review-Panel rendert schon im Wall-Modus | bestehende Aktion | Verlinkung |

**Abarbeiten — Karten-Klick + abhaken:**
- Karte anklicken → **Anleitung-Overlay** (`<x-foodalchemist::modal>` + `schritt-karten`), gefüllt aus der
  Zeile (Eloquent-Load, team-sicher gegen die Fenster-Collection geprüft).
- Häkchen direkt auf der Karte — **aber nur, wenn der Auftrag `in_progress` ist**. Diese Invariante aus
  Spec 30 §5 bleibt: im `planned` könnte ein Recompute die Zeile ersetzen, ein Häkchen hinge dann an
  geänderten Mengen. Das Cockpit macht den Zustand sichtbar und bietet **„Produktion starten"** als
  Ein-Klick (`ProductionOrderService::setStatus(…, InProgress)` — bestehende, owner-geprüfte Transition,
  rechnet beim Start recompute). Kein Aufweichen, sondern der fehlende Knopf.

**Change-Log bleibt im Modul** (Golden Rule 3 — kein Cross-Modul-Zugriff auf den Activity-Log): neue
read-only Methode `ProductionCapacityService::letzteAenderungen()` liest die zuletzt geänderten Zeilen im
Fenster (`orderByDesc(updated_at)`, dieselben team-strikten Joins wie `tagesplanZeilen`). `updated_at` wird
von `assignLine`/`vorlaufSetzen`/`setLineStatus` gestoßen → ein wahrer, modul-eigener Feed.

---

## 5. Etappen

| Etappe | Inhalt | Abhängigkeit |
|---|---|---|
| **K1 — Kapazität** | `kapazitaetAm()`-Vorrang (Besetzung gewinnt) + `STANDARD_SCHICHT_MIN`-Default; Einstellungen-Hinweis umdrehen | — |
| **K2 — Anleitung + Umschalter** | `tagesplanZeilen()` um `recipe_id/tiefe/position/is_basisrezept` (+ `mitAnleitung`→`zutaten/steps_snapshot/zubereitung`, json_decode); URL-Prop `ansicht`; Screen-Liste Posten/Gericht-Gruppierung; Detail-Panel rendert Schritte+Zutaten; Posten-Blatt-Druck rendert Anleitung inline; „keine Anleitung hinterlegt"-Hinweis | — |
| **K3 — Cockpit** | `_cockpit.blade.php` (Sektionen a–f); `oeffneAnleitung/anleitungSchliessen/produktionStarten/naechsteSchritte`; `letzteAenderungen()`; Anleitung-Overlay | K1 (Ampeln), K2 (Anleitungs-Daten) |

Branch `feat/tagesplan-cockpit`, drei committbare Schritte (jeder für sich testbar & wertvoll). Nach jedem
Schritt ROADMAP + Office-Package 23 mitziehen. **Keine Migration.**

---

## 6. Verifikation / DoD

**Tests** (`tests/Feature/ProduktionTagesplanTest.php`, Livewire-Ebene):
- Kapazität: Besetzung {koch:2}+Schicht → 960; ohne Besetzung + manuell 480 → 480; Wochentag-Override gewinnt über beide; Besetzung ohne Schicht → 2×480 (Default).
- Umschalter: `ansicht='gericht'` gruppiert nach Auftrag mit eingerückten Basisrezepten; **Reflection-Test** (`ProduktionTagesplanTest` prüft die exakte URL-Prop-Menge, ~Zeile 126) um `ansicht` ergänzen — sonst rot.
- Anleitung: `tagesplanZeilen(mitAnleitung:true)` trägt dekodierte `schritte`/`zutaten`; Blatt-View rendert einen Schritt-Text; Leer-Fall zeigt „keine Anleitung hinterlegt"; Detail-Panel zeigt Schritt + Zutaten.
- Cockpit: Ampeln + offen/erledigt-Zähler; `produktionStarten` → Auftrag `in_progress` (`->fresh()->status`); Abhaken vor Start weiter gesperrt; `oeffneAnleitung` setzt State + zeigt Schritt-Text; Change-Log zeigt Gericht+Auftrag; Planen-Link vorhanden + `vorschlagen` füllt `$vorschlag`.
- Volle Suite grün (`./fa_test.sh`, parallel).

**MySQL-/Browser-Smoke** (Test ist layout-blind — Wandmodus ist eine volle Seite; `->layout('platform::layouts.app')`-Falle beachten): Wandmodus als volle Seite, Alpine-Modal öffnet/schließt real, `whereDate`-Fenster + Manntage gegen echtes MySQL, Klickstrecke Auftrag starten → Karte abhaken → Overlay → Umschalter → Posten-Blatt (beide Ansichten).

---

## 7. Offen / Nicht-Ziele

- **Läkkerai-Performance-Panel** (Einsparung €/Kategorie über 30 Tage): braucht Ist-vs-Plan-Tracking — bleibt Nicht-Ziel (Spec 30 §5: „nur abhaken", keine Ist-Mengen). Bewusst nicht in dieser Spec.
- **Vollautomatische Tagesverteilung** nach Fähigkeit: braucht Equipment↔Posten-Mapping (Spec 30 §7). Der Auto-Planer bleibt Vorschlag zum Review.
- **Personen-/Schichtplanung:** unverändert Nicht-Ziel (Spec 30 §1/§1a). Kapazität nur Posten/Rollen.
- **Foto-Backfill** für Schritte (Bestand hat 0 Schritt-Fotos): Anleitung zeigt Text, Fotos wenn vorhanden.

---

## 8. MCP-Lockstep

Reine UI-/Read-Erweiterung ohne neues Datenmodell → **keine neuen MCP-Tools**. Die geänderte
Kapazitäts-Vorrangregel wirkt automatisch in allen Tools, die `kapazitaetAm()`/`auslastung()` lesen. Sollte
sich beim Bau ein neues schreibendes Verhalten ergeben (unwahrscheinlich), im selben Schritt mitziehen.

---

## 9. Was „produktreif" hier bedeutet

K1–K3 machen den Tagesplan zu einem guten Cockpit. Für einen verlässlichen Produktionsbetrieb reicht eine
schöne Tagessicht allein aber nicht. Produktreif ist die Lösung erst, wenn ein Team den ganzen Zyklus ohne
Nebenlisten, stillen Datenverlust oder unklare Zuständigkeit führen kann:

```
Bedarf → rechnen → disponieren → prüfen → freigeben → produzieren → Abweichungen klären → abschließen
```

Die folgenden Produktversprechen sind die Abnahmegrenze:

1. **Keine Arbeit verschwindet.** Ungeplante, verspätete, übersprungene und durch Änderungen veraltete
   Arbeit hat immer einen sichtbaren Zustand und einen nächsten Handgriff.
2. **Die Küche arbeitet aus einer Wahrheit.** Wand, Bildschirm, Druck und Auftrag lesen denselben Snapshot;
   Änderungen sind nach der Freigabe kontrolliert und nachvollziehbar.
3. **Ein Schichtleiter kann entscheiden.** Kapazität, fehlende Zeiten, Materialbereitschaft, Anleitungslücken
   und Terminrisiken sind vor Produktionsbeginn sichtbar.
4. **Die Bedienung hält Küchenbetrieb aus.** Touch-tauglich, schnell, fehlertolerant, mit klarer Rückmeldung
   und ohne doppelte Aktionen bei Reload oder Doppelklick.
5. **Ein Auftrag ist sauber abschließbar.** Offene und übersprungene Zeilen müssen bewusst geklärt werden;
   „fertig" ist ein verantworteter Abschluss, kein zufälliger Haken.

### 9.1 Rollen und Hauptaufgaben

| Rolle | Hauptaufgabe | Benötigte Sicht |
|---|---|---|
| Produktionsleitung | Aufträge prüfen, Kapazität ausgleichen, Plan freigeben, Störungen entscheiden | Planungsmodus, Warnzentrale, Freigabe |
| Postenleitung | Arbeit des Postens priorisieren und verteilen | Postenansicht, „Als nächstes", Blocker |
| Ausführung | Anleitung lesen, Arbeit starten/erledigen/überspringen | große Touch-Karten, wenige Aktionen |
| Einkauf/Disposition | erkennen, ob der freigegebene Bedarf übergeben und noch aktuell ist | Materialstatus je Auftrag, Drift-Warnung |
| Betriebsleitung | Durchsatz, Termintreue und Datenlücken sehen | Wochenkennzahlen, keine personenbezogene Leistungsmessung |

Das sind **Berechtigungsrollen**, keine Personen- oder Schichtplanung. Die Architekturgrenze aus Spec 30 §1
bleibt bestehen.

---

## 10. Verbindlicher End-to-End-Ablauf

### Phase A — Plan erzeugen

1. Aufträge und Ziele werden wie heute berechnet; jede Zeile erhält Liefertag, Vorlauf, Posten und Sollzeit.
2. Der Auto-Planer erzeugt ausschließlich einen **Vorschlag**. Die Übernahme ist eine zusammenhängende,
   team-gesicherte Transaktion und zeigt vorher Verschiebungen, Überlast und ungeplante Zeilen.
3. Der Planungsmodus hat eine zentrale **Klärfälle-Leiste**:
   - nicht zugeteilt,
   - ohne Arbeitszeit,
   - ohne Anleitung,
   - Posten über Kapazität,
   - Plantermin liegt in der Vergangenheit,
   - Einkaufsübergabe fehlt oder ist veraltet.
4. Jeder Klärfall verlinkt auf die betroffenen Zeilen; reine Hinweise und freigabeblockierende Fehler werden
   optisch und semantisch getrennt.

### Phase B — Schicht freigeben

Die Produktionsleitung friert einen **Tagesausschnitt** nicht als neues Aggregat ein; sie startet die
betroffenen bestehenden Aufträge. Vorher erscheint eine Freigabe-Zusammenfassung mit Anzahl Aufträge,
Zeilen, Sollminuten, fehlenden Zeiten/Anleitungen, Überlast und Einkaufsstatus.

**Harte Sperren:** kein Auftrag, keine aktive Zeile oder Teamkonflikt. **Bewusst übersteuerbare Warnungen:**
fehlende Zeit, fehlende Anleitung, Überlast, veraltete Einkaufsübergabe. Eine Übersteuerung verlangt einen
kurzen Grund und wird protokolliert. So bleibt der Betrieb handlungsfähig, ohne Warnungen bedeutungslos zu
machen.

### Phase C — Produzieren

1. Karte **starten** → Zeile `in_progress`; optional bleibt direktes „erledigt" für kurze Tätigkeiten möglich.
2. Karte **erledigen** → Zeile `done`, mit `done_at`/`done_by` wie heute.
3. Karte **überspringen** → `skipped` nur mit Grund aus kurzer Liste plus optionaler Notiz.
4. Eine blockierte Tätigkeit bleibt `open`/`in_progress` und erhält einen Blocker. Sie verschwindet nicht aus
   „Als nächstes", sondern wird dort separat eskaliert.
5. Gleichzeitige Klicks sind idempotent: der Server prüft erwarteten Zustand und `updated_at`; bei Konflikt
   lädt die Karte neu und erklärt, was inzwischen passiert ist.

### Phase D — Schichtübergabe und Abschluss

Zum Tagesende zeigt das Cockpit: erledigt, läuft, offen, übersprungen, blockiert und überfällig. Offene Arbeit
kann einzeln auf einen zulässigen früheren Produktionstag **nicht** verschoben werden; für den Folgetag wird
der `vorlauf_tage` passend neu gesetzt. Dabei zeigt die UI immer den resultierenden Tag und verhindert einen
Plantermin nach dem Liefertag.

Ein Auftrag darf auf `done`, wenn alle aktiven Zeilen `done|skipped` sind. Soll der bestehende weiche Abschluss
mit offenen Zeilen erhalten bleiben, braucht er eine explizite Ausnahme mit Grund; offene Zeilen bleiben im
Abschlussprotokoll sichtbar. Das ist die produktreife Auslegung der Spec-30-Regel „nicht blockieren": kein
stiller Abschluss, aber auch keine Sackgasse.

---

## 11. Bedienkonzept: Planen und Ausführen klar trennen

### 11.1 Planungsmodus

- Zeitraum 1/7/14 Tage, Posten- und Auftragsfilter, Ansicht Posten/Gericht.
- Drag-and-drop ist optionaler Komfort, **jede** Verschiebung braucht zusätzlich eine tastatur- und
  touchfähige Aktion „Verschieben" mit Datum/Posten und Vorschau der Kapazitätswirkung.
- Bulk-Aktionen nur mit Review: Posten zuweisen, Tag verschieben, Produktion starten.
- Filter und Ansicht liegen in der URL; mutierende Auswahlzustände nicht.
- Leere Zustände erklären die nächste Aktion statt nur „keine Daten" zu zeigen.

### 11.2 Ausführungs-/Wandmodus

- Default ist **heute**, Posten-Spalten statt 14-Tage-Raster; Uhrzeit „zuletzt aktualisiert" sichtbar.
- Mindest-Zielgröße 44 × 44 px, keine Kernaktion nur über Hover oder Farbe, Kontrast mindestens WCAG AA.
- Karten zeigen in fester Reihenfolge: Status, Name, Menge/Ansätze, Auftrag/Liefertag, Sollzeit,
  Verantwortlich, Anleitung/Blocker.
- Primäraktion hängt vom Zustand ab: `open → Starten`, `in_progress → Erledigen`, `done → ansehen`.
- Überspringen, rückgängig und Auftrag starten sind Sekundäraktionen mit Bestätigung, wenn sie mehrere
  Zeilen betreffen.
- Polling aktualisiert nur Lesedaten; offene Modale und laufende Eingaben werden nie überschrieben.
- Druck hat Erzeugungszeit, Zeitraum, Posten, Ansichtsmodus und Seitenzählung. Der Ausdruck ist ein Snapshot;
  ein Hinweis nennt die digitale Quelle für den aktuellen Stand.

### 11.3 Detailtiefe der Anleitung

Anleitung und Zutaten erscheinen pro Rezeptzeile, nicht als riesiger Gesamttext. Schritte zeigen Phase,
Nummer, Text und Foto; Basisrezepte sind nach `tiefe` eingerückt. Allergene dürfen nur angezeigt werden,
wenn sie aus dem eingefrorenen Snapshot stammen — nie live aus einem inzwischen geänderten Rezept.

---

## 12. Klärfälle und Geschäftsregeln

| Fall | Verhalten | Blockiert Freigabe? |
|---|---|:--:|
| Zeile ohne Posten | eigener Bucket, direkt zuweisbar | nein, aber Warnung |
| Zeile ohne Arbeitszeit | zählt als Datenlücke, nie als 0-Minuten-Wahrheit | nein, aber Warnung |
| Zeile ohne Anleitung | klarer Hinweis + Link zum Rezept im Planungsmodus | nein, aber Warnung |
| Posten >100 % | rot, Alternativtage/Posten zeigen, kein automatisches Umsortieren | nein, übersteuerbar |
| Plantermin in Vergangenheit | „überfällig", oben in „Als nächstes" | nein |
| Auftrag nach Planfreigabe geändert | bestehender Snapshot bleibt; Drift wird angezeigt | ja, bis neu geprüft |
| Einkaufsübergabe veraltet | Materialstatus „Prüfen", Link zur Übergabe | nein, übersteuerbar |
| Rezept/Posten wurde deaktiviert | Snapshot-Name erhalten, „nicht mehr aktiv" markieren | nein |
| Gleichzeitige Bearbeitung | optimistischer Konflikt, neu laden statt überschreiben | — |
| Netzwerk-/Serverfehler | Aktion bleibt unbestätigt, Retry möglich, kein optimistischer Erfolgshaken | — |
| `skipped` | Grund Pflicht, zählt abgearbeitet, aber separat im Abschluss | nein |
| Auftrag storniert | verschwindet aus aktivem Cockpit, bleibt im Protokoll | — |

**Keine Lagerlüge:** Materialbereitschaft bedeutet in dieser Ausbaustufe ausschließlich Status der
Einkaufsübergabe (`nicht übergeben|aktuell|veraltet|bewusst übersteuert`). „Ware vorhanden" wird erst mit
einem echten Wareneingangs-/Bestandsmodul behauptet.

---

## 13. Daten- und Service-Design für die Produktreife

K1–K3 bleiben wie entschieden **ohne Migration**. Die betriebssicheren Erweiterungen K4–K6 dürfen nicht in
`updated_at`-Interpretationen versteckt werden und erhalten ein kleines, explizites Schema.

### 13.1 Neue Felder an Produktionszeilen (K4)

| Feld | Typ | Zweck |
|---|---|---|
| `blocked_reason` | nullable string(80) | maschinenlesbarer Blocker-Code |
| `blocked_note` | nullable text | Küchenkontext |
| `skipped_reason` | nullable string(80) | Pflichtgrund bei `skipped` |
| `started_at` / `started_by` | nullable timestamp / bigint | Start nachvollziehen, keine Ist-Zeitberechnung |

`started_at` und `done_at` sind Protokollpunkte. Aus ihrer Differenz wird **keine Mitarbeiterleistung** und
keine belastbare Arbeitsdauer abgeleitet, weil Pausen, Parallelität und Personen fehlen.

### 13.2 Ereignisprotokoll (K4)

Neue Tabelle `foodalchemist_production_events`:

`id, team_id, order_id, line_id nullable, event_type, from_state nullable, to_state nullable,
reason_code nullable, note nullable, actor_id nullable, payload json nullable, created_at`.

Erfasst werden mindestens: Plan übernommen, Auftrag gestartet/abgeschlossen/storniert, Zeile gestartet,
erledigt, übersprungen, rückgängig, verschoben, umgeteilt, blockiert/entblockt und Warnung übersteuert.
Append-only, team-strikt, keine fachliche Rückrechnung aus Events. Der Cockpit-Feed liest diese Tabelle;
`updated_at` ist danach **nicht mehr** der vermeintliche Change-Log.

### 13.3 Service-Grenzen

- `ProductionCapacityService`: ausschließlich Read-Model, Auslastung, Klärfälle und Cockpit-Feed.
- `ProductionOrderService`: einzelne autorisierte Zustandsübergänge, Assignment und Guards.
- `ProductionPlanService`: Vorschlag und atomare Übernahme mit Vorher/Nachher-Diff.
- Neuer `ProductionReadinessService`: Freigabeprüfung und klassifizierte Findings
  (`error|warning|info`) aus Produktion plus existierendem Einkaufs-Handover-Vertrag.
- Neuer `ProductionEventService`: Events nur innerhalb derselben DB-Transaktion wie die Fachänderung.

Alle schreibenden Methoden nehmen Team und erwartete Version/`updated_at` entgegen. Controller/Livewire
schreiben nie direkt auf Modelle. Ereignisse, Status und fachliche Änderung committen gemeinsam.

### 13.4 Indizes und Aufbewahrung

- Events: `(team_id, created_at)`, `(order_id, created_at)`, `(line_id, created_at)`.
- Tagesplan bleibt auf `(plan_date, station_id)` beziehungsweise vorhandenen äquivalenten Indizes messbar;
  vor Umsetzung Query-Plan auf MySQL prüfen.
- Events werden nicht in normalen Löschpfaden kaskadiert; sie verlieren bei gelöschter Zeile nur die
  optionale Referenz, behalten Snapshot-Namen im Payload. Aufbewahrungs-/DSGVO-Regel wird zentral mit dem
  Plattformkonzept festgelegt, nicht lokal erfunden.

---

## 14. Rechte, Sicherheit und Mandantentrennung

| Fähigkeit | Produktion ansehen | ausführen | planen | freigeben/abschließen |
|---|:--:|:--:|:--:|:--:|
| Tages-/Wandplan lesen | ✔ | ✔ | ✔ | ✔ |
| Zeile starten/erledigen/blockieren | ✘ | ✔ | ✔ | ✔ |
| Tag/Posten/Verantwortlich ändern | ✘ | ✘ | ✔ | ✔ |
| Vorschlag übernehmen | ✘ | ✘ | ✔ | ✔ |
| Auftrag starten, Warnung übersteuern, abschließen | ✘ | ✘ | ✘ | ✔ |

Die konkreten Permission-Namen folgen der vorhandenen Plattformkonvention. Jede Query bleibt team-strikt;
IDs aus Livewire-Aktionen werden serverseitig erneut gegen Team, Auftragsstatus und Sichtfenster geprüft.
Freitext wird escaped ausgegeben, Foto-URLs laufen über bestehende autorisierte Medienpfade.

---

## 15. Kennzahlen ohne falsche Präzision

Produktreife Kennzahlen aus vorhandenen bzw. in K4 sauber erfassten Fakten:

- Aufträge/Zeilen heute: offen, läuft, erledigt, übersprungen, blockiert, überfällig.
- Planerfüllung: `(done + skipped) / aktive Sollzeilen`, `skipped` immer separat ausweisen.
- Kapazitätsabdeckung: Anteil aktiver Zeilen **mit** Arbeitszeit und **mit** Postenzuordnung.
- Anleitungsvollständigkeit: Anteil Rezeptzeilen mit eingefrorener Anleitung.
- Termintreue: Anteil bis zum Liefertag abgearbeiteter aktiver Zeilen.
- Warnungsübersteuerungen und veraltete Einkaufsübergaben je Woche.

Nicht zulässig ohne weiteres Datenmodell: echte Produktivität, eingesparte Stunden, Ist-Ausbeute,
Personenleistung oder belastbare Plan-Ist-Arbeitszeit.

---

## 16. Umsetzung bis Release

| Etappe | Ergebnis | Migration | Abnahme-Gate |
|---|---|:--:|---|
| **K1** | ehrliche Kapazität bei Besetzung | nein | Kapazitätstests + Bestandsfall |
| **K2** | Anleitung, Zutaten, Posten/Gericht, Druck | nein | Featuretests + Druck-Render |
| **K3** | Wandcockpit, Starten/Abarbeiten, erste Übersicht | nein | Touch-/Wall-Smoke + volle Suite |
| **K4** | Ereignisprotokoll, Blocker-/Skip-Gründe, Konfliktschutz | ja | Transaktions-, Rechte- und Concurrency-Tests |
| **K5** | Readiness-/Klärfälle-Leiste, Freigabe- und Abschlussdialog | nein | kompletter End-to-End-Referenzfall |
| **K6** | Kennzahlen, Barrierefreiheit, Performance und Betriebsreife | nein | Qualitätsbudgets + Pilotabnahme |

### 16.1 Vertikale Referenzfälle

1. **Normalfall:** Foodbook 120 Personen → Auftrag → Vorschlag → Übernahme → Einkauf aktuell → Start →
   alle Posten arbeiten ab → Auftrag fertig.
2. **Vorproduktion:** Basisrezept zwei Tage vorher, Gericht am Liefertag; Umschalter und Druck zeigen den
   Zusammenhang ohne Dublette.
3. **Überlast:** zwei Köpfe × 480, 1.050 Minuten geplant; Warnung, Verschiebung, neue Kapazität korrekt.
4. **Datenlücke:** eine Zeile ohne Zeit und Anleitung; Freigabe mit begründeter Übersteuerung, später im
   Protokoll sichtbar.
5. **Störung:** Zeile blockiert, auf anderen Posten umgeteilt, übersprungen mit Grund, Abschluss zeigt sie.
6. **Parallelbedienung:** zwei Geräte ändern dieselbe Zeile; genau eine Änderung gewinnt, die andere erhält
   einen verständlichen Konflikt und überschreibt nichts.
7. **Drift:** Einkauf übergeben, Ziel geändert; Übergabe und Freigabe werden sichtbar veraltet.

### 16.2 Qualitätsbudgets

- Tagesansicht mit 500 aktiven Zeilen: Serverantwort p95 < 800 ms im produktionsnahen MySQL-Test.
- Statusaktion p95 < 500 ms ohne Fotoabruf; keine N+1-Queries je Karte.
- Cockpit-Read-Model maximal fünf fachliche Queries pro Render plus definierte Detail-Loads.
- Alle Kernaktionen per Tastatur und Touch; WCAG-AA-Kontrast; Status zusätzlich zu Farbe als Text/Icon.
- Keine ungefangene Exception bei leerem Team, gelöschtem Posten/Rezept oder abgelaufenem Modalzustand.
- Druck über 100 Zeilen ohne abgeschnittene Karten oder alleinstehende Überschriften.

### 16.3 Rollout und Rückweg

1. Feature-Flag `production_cockpit_v2` pro Team; K1 separat aktivierbar, weil es Bestandskapazitäten bewusst
   verändert.
2. Vor Aktivierung Readiness-Report: Posten mit Besetzung/manueller Kapazität, Zeilen ohne Zeit/Anleitung,
   offene Alt-Aufträge, aktuelle Einkaufsübergaben.
3. Pilot mit einem Betrieb und zwei echten Produktionstagen; Befunde nach Schweregrad dokumentieren.
4. Danach stufenweise aktivieren. Das alte Tagesplan-Layout bleibt für eine Release-Periode als Rückfall;
   Schreibpfade und Statussemantik bleiben identisch, damit kein Daten-Rollback nötig ist.
5. Beobachten: Fehlerquote der Aktionen, Konflikte, Renderzeit, offene/überfällige Zeilen und Zahl der
   Warnungsübersteuerungen. Keine personenbezogene Telemetrie.

---

## 17. Vollständige Definition of Done

Die Funktion ist **nicht** fertig, wenn nur K1–K3 gerendert werden. Release-fertig ist sie, wenn:

- K1–K6 implementiert, dokumentiert und per Permission geschützt sind;
- alle sieben Referenzfälle als Feature-/Browsertests oder dokumentierte MySQL-Smokes bestanden sind;
- Screen, Wand und Druck denselben fachlichen Stand zeigen;
- Freigabe, Übersteuerung, Blocker, Skip und Abschluss lückenlos im Ereignisprotokoll stehen;
- keine aktive Zeile durch Filter, fehlenden Posten, fehlende Zeit oder Fehlerzustand unsichtbar wird;
- Query- und Interaktionsbudgets aus §16.2 eingehalten werden;
- Migration auf produktionsnaher Kopie sowie Rückfall über Feature-Flag geprobt wurde;
- Benutzerhandbuch `docs/produktion.md`, Business-Case-Matrix, MCP-Werkzeuge und Changelog im selben
  Release aktualisiert wurden;
- Produktionsleitung und mindestens ein ausführender Posten den Pilot-End-to-End-Fall abgenommen haben.

**Release-Schnitt:** K1–K3 dürfen als „Cockpit Beta" ausgeliefert werden. Das Label „produktreif" gilt erst
nach K4–K6. So bleibt der schnelle Nutzwert erhalten, ohne eine gute Oberfläche mit einem abgeschlossenen
Produkt zu verwechseln.

---

## 18. Weiterhin bewusste Nicht-Ziele

- Lagerbestand, Wareneingang, Chargen-/MHD-/HACCP-Dokumentation und automatische Materialverfügbarkeit.
- Personen-, Schicht-, Abwesenheits- oder Stundenplanung.
- Ist-Mengen, Ausschussmengen, Ist-Ausbeute und automatische Nachproduktion.
- Maschinen-/Equipment-Disposition und fähigkeitsbasierte Auto-Zuteilung, bis ein belastbares
  Equipment↔Posten-Modell existiert.
- Offline-first-Synchronisation. Bei Netzausfall bleibt der versionierte Ausdruck der Rückfall; digitale
  Aktionen behaupten ohne Serverbestätigung keinen Erfolg.
- Push-Benachrichtigungen und personenbezogene Performance-Rankings.

Diese Grenzen sind keine Produktlücken, sondern verhindern, dass das Cockpit still zum halben Lager-,
Personal- oder Qualitätsmanagement wird. Jede spätere Erweiterung braucht eine eigene Spec und einen klaren
Domänenvertrag.
