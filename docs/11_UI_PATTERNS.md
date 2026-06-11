---
typ: UI-Pattern-Spec
stand: 2026-06-11
status: verbindlich — abgeleitet aus Ist-App-Screens (Dominique, 2026-06-11) + DESIGN.md
quellen: 5 Referenz-Screenshots der Tauri-App (GP-Browser, Lieferanten, LA-Modal, Basisrezepte, Rezept-Modal)
---

# 11 — UI-Patterns (Soll, abgeleitet aus der Ist-App)

**Anspruch (User-Vorgabe):** Die Informationsdichte und die Interaktions-Patterns der
Desktop-App sind die Messlatte. Umsetzung in Livewire/Blade nach DESIGN.md
(Linear/Raycast-Personality, Frosted-Glass, Violet/Indigo) — Dichte bleibt, Optik wird Plattform.

> Verhältnis zu den D-Specs: Die §4-UI-Sektionen der Domänen-Specs beschreiben *was* auf den
> Screen gehört. Dieses Dokument beschreibt *wie* — die wiederverwendbaren Patterns P-1…P-8.
> Bei Widerspruch gewinnt dieses Dokument (neuer).

---

## P-1 Master-Detail-Dreiklang (Browser-Screens)

Referenz: GP-Browser + Basisrezept-Browser der Ist-App.

```
┌──────────┬──────────────────────────────┬───────────────────┐
│ Filter-  │ Dichte Tabelle               │ Detail-Panel      │
│ Baum     │ (Name, Pills, Kennzahlen)    │ (Sektionen,       │
│ links    │ Zeilen-Klick = Panel-Update  │  lazy geladen)    │
│ +Counts  │ KEIN Seitenwechsel           │                   │
└──────────┴──────────────────────────────┴───────────────────┘
```

- **Links:** Warengruppen-/Hauptgruppen-Baum mit Live-Counts pro Knoten (eine Query,
  `GROUP BY`), darüber Suche + Status-Filter. Shell: `x-ui-page-sidebar`.
- **Mitte:** Tabelle wie im Slice bewiesen (Status-Pills, LA-Zähler, Warn-Pills).
  Spalten GP-Browser-Soll: Name · Warengruppe · Status · LAs · **Lead-Preis (€/kg)** ·
  **Rezepte-Verwendungen** · **Allergen-Badges**. Zeilen-Klick selektiert
  (`$dispatch('gp-selected', id)`), Doppelklick/Edit-Button öffnet Modal (P-2).
- **Rechts:** Detail-Panel als **eigene Livewire-Komponente** (`Gps\DetailPanel`),
  hört auf das Select-Event. Sektionen (GP): Pairing-Status, Kern-Anker, Domain,
  Einheit & Gewicht, Tags, Allergene (aggregiert, „n/m LAs"), Zusatzstoffe (LMIV),
  Nährwerte (Ø aus LAs je 100 g), verknüpfte LAs mit Lead-Stern.
- **Performance-Gebot:** Sektionen mit teuren Queries (Nährwert-Aggregation, Pairing)
  als Kind-Komponenten mit `lazy` / `wire:init` — eine Interaktion in Sektion A darf
  Sektion B nicht re-rendern.
- **Header-KPI-Leiste** (Ist-App oben rechts): `120 Lieferanten · 6.930 GPs · 9.803 LAs ·
  1.407 Rezepte` → ein gecachter KPI-Service-Call, Anzeige in der Actionbar.
- **Platzierungs-Entscheid (Dominique, 2026-06-11, M0-07-Review):** Standard für Browser-Screens =
  Baum/Filter in der **linken Page-Sidebar** (bestätigt Spec oben) und Detail-Panel in der
  **rechten Page-Sidebar** (`x-ui-page-sidebar side="right"`, `storeKey`-Persistenz
  gratis — belegt dort den Aktivitäten-Slot). Der Baustein `<x-foodalchemist::master-detail>`
  (M0-07) bleibt für Tabellen-Zone + Fälle, in denen die Shell-Sidebars nicht verfügbar sind;
  tree-/panel-Slots sind dafür optional. Gilt ab M3-01/M3-03.
- **Panel = Arbeitsfläche (Dominique, 2026-06-11):** Das Detail-Panel **so groß wie möglich**
  dimensionieren — darin wird gearbeitet, nicht nur gelesen. Breite je Screen großzügig wählen
  (finale Breite beim ersten echten Screen festlegen, M3-03).
- **Kontext-Erhalt-Gebot (Dominique, 2026-06-11 — VERBINDLICH für alle Browser/Editoren):**
  Speichern + Schließen (Panel ODER Modal) bringt den User **exakt dorthin zurück, wo er war** —
  Auswahl, Filter, Scroll-Position und Pagination bleiben erhalten, nie „von vorne klicken".
  Konkret: Zeilen-Klick = Event ohne Seitenwechsel (s. oben), Modals overlayen ohne Navigation,
  Save aktualisiert Liste/Panel in place (kein Full-Reload, kein `redirect()`), Auswahl im
  URL-Sync (`?gp=` M3-01) — überlebt damit auch Browser-Reload. Eigene Detail-ROUTEN
  (`/gps/{id}`-Muster des Slice) sind das Anti-Muster dazu → Abbau in M3-12.

## P-2 Modal-Editor („Fenster auf und zu")

Referenz: „LA bearbeiten"- und „Rezept bearbeiten"-Modals der Ist-App.

- Große, scrollbare Modals mit **Sektions-Überschriften** (Stammdaten / Verpackung &
  Mengen / Eigenschaften / Preise / Allergene). Kein Wizard, alles auf einer Fläche —
  das ist die Ist-App-Stärke, beibehalten.
- Aktionen oben links fix: Speichern (primary) · Löschen · KI-Aktionen (P-3).
- Livewire: ein Modal = eine Komponente, geöffnet via Event (`modal.open`,
  Planner-Konvention). Schließen ohne Speichern = kein State-Leak (`resetExcept`).
- **Baustein (M0-08):** `<x-foodalchemist::modal name="…">` + `<x-foodalchemist::modal-section>` —
  Fassade, Innenleben bei Martin-Entscheid austauschbar. Schließen feuert IMMER `modal.closed`
  `{ name }` → Besitzer-Komponente resettet darauf ihren Form-State.
  ⚠ **Alpine-Falle:** Event-Namen mit Punkt (`modal.open`) beim LAUSCHEN als
  `@modal-open.dot.window` schreiben — Punkte gelten sonst als Modifier und der Listener
  bindet still aufs falsche Event (gilt für alle kommenden Bausteine, z. B. P-3-Events).
- ⚠️ **Offen mit Martin:** DESIGN.md erlaubt im Content keine x-ui-Komponenten;
  `x-ui-modal` ist aber Shell-nah und im Planner Standard. Klären: x-ui-modal nutzen
  (empfohlen, Konsistenz) oder DESIGN.md-konformes Frosted-Modal als Modul-Partial.

## P-3 KI-Feld-Pattern (GL-07 sichtbar gemacht)

Referenz: überall in der Ist-App — „✨ Autopilot", „✨ KI-Vorschlag · 100%", „Reset",
„✨ Alles anreichern", „Name putzen", „Copilot".

Jedes KI-befüllbare Feld/Cluster rendert einheitlich:

```
LABEL — Quelle (ki|manual) · Konfidenz %        [Reset] [Manuell] [✨ Autopilot]
[Wert / Chips]
```

- Mapping auf GL-07-Quadrupel: Autopilot = `ai_*`, Übernehmen = `accept_*`,
  Reset = `clear_*`; manuelle Edits setzen Quelle auf `manual` (Lineage!).
- Konfidenz % aus dem KI-Response persistieren und anzeigen (Ist-App zeigt „100%").
- Bulk-Variante: „✨ Alles anreichern" (Rezept-Detail) bzw. „Bulk anreichern"
  (Listen-Ebene) → Queue-Job, Fortschritt als Toast/Badge (Plattform-Notifications).
- Blade-Partial `_ki_field_header.blade.php` einmal bauen, überall einsetzen.

## P-4 Tri-State-Allergen-Kontrolle

Referenz: LA-Modal, „Allergene (14 EU-Pflichtangaben)" mit Button-Triple `− / ≈ / ✓`.

- 3 Buttons je Allergen: `−` nicht_enthalten · `≈` spuren · `✓` enthalten;
  ungesetzt = unbekannt (4. Zustand, GL-01). Farbcode: grau/amber/rot.
- Als Alpine-Komponente (rein clientseitig togglen, ein `wire:model` aufs Array).
- Identisch in: LA-Editor, GP-Override-Editor (V-08), Rezept-Snapshot-Ansicht (read-only).

## P-5 Chip-/Pill-Editoren (Anker, Tags, Pairing, Eignungen)

Referenz: Kern-Anker `★ apfel ×`, Tags `Vegan ✓`, Pairing-Chips, Sektor-/Niveau-Eignung.

- Chips mit ×-Remove + „+ manuell…"-Add (Datalist/Combobox gegen Vokabular).
- Kern-Anker-Chips mit ★-Prefix; Pairing-Chips öffnen bei Klick die Pairing-Doku (D-5).
- Immer kombiniert mit P-3-Header (KI-Quelle + Autopilot), da alle Chip-Cluster
  KI-befüllbar sind.

## P-6 Preis-Historie

Referenz: LA-Modal „Preise" — EK aktuell prominent + Historie.

- Kopfzeile: `EK aktuell: 77,70 € pro STK · 77,70 €/Stk` + „+ Neuer Preis".
- Tabelle: Gültig von · Gültig bis · Kategorie-Pill (`standard_ek`/`aktion`…) ·
  Preis/Einheit (+ normalisierter Vergleichspreis) · Notiz · Edit/Löschen.
- Neuer Preis schließt offene Gültigkeit des Vorgängers (Service-Logik, GL-03-Umfeld).

## P-7 Lieferanten-Browser + Mapping-Spalte

Referenz: Lieferanten-Screen.

- Linke Liste: Lieferant + `n Artikel · m gemapped` (gemapped grün) — der
  LA-First-Fortschritt auf einen Blick. Suche lieferantenübergreifend.
- Artikel-Tabelle: ArtNr · Bezeichnung · Gebinde · Status · EK · **Vergleichspreis
  (normalisiert €/Einheit)** · Grundprodukt (Mapping-Link oder „— nicht gemappt —").
- Kopf-Aktionen: **Bulk-Match** (GL-04-Lauf je Lieferant) + **Preis-Anomalien**
  (V-Register) + „Nur aktive"-Toggle + „+ Neuer Artikel".

## P-8 Zutaten-Editor (kritischster Editor)

Referenz: Rezept-Modal, 15-Zeilen-Zutaten-Tabelle.

- Spalten: Drag-Handle · # · Menge · Einheit (Select aus Vokabular) ·
  **Beschreibung/Verknüpfung** (GP-Link, klickbar) · Hinweis (+ Lineage-Quelle wie
  `via per_instance_proposed` / `override_gp_direct` dezent kursiv) · Garv. % · EK € ·
  Edit/Remove.
- Add-Zeile unten: Menge · bis (optional) · Einheit · Beschreibung mit
  **Auto-Fill aus GP-Picker** · optional-Flag · Hinweis.
- **Architektur-Gebot:** Tippen (Mengen, Beschreibung) bleibt clientseitig (Alpine),
  EK-Zeilen-Summen live im Client rechnen; Server-Sync erst bei Blur/Speichern.
  Drag-Sort via `@wotz/livewire-sortablejs` (in Plattform vorhanden).
- KI-Aktionen am Tabellen-Kopf: „🧑‍🍳 Copilot" (Chat-gestützt, D-8/Phase je MVP-Schnitt)
  + „✨ KI-Überarbeiten" (Mengen/Struktur-Pass, 06_KI).
- Syntax-Hilfen aus Regelwerk als Mikro-Hints unter Feldern (Ist-App: „Syntax §1.2 …,
  recipe_key automatisch") — Regelwerk-Snippets aus der Wissens-DB (D4 Klasse A).

---

## Abgrenzung / bewusste Abweichungen von der Ist-App

| Ist-App | Soll (Modul) | Grund |
|---|---|---|
| Eigene Fenster-Chrome (Tauri) | Plattform-Shell + Modals | Plattform-Muster |
| Orange Akzentfarbe | Violet/Indigo-Gradient | DESIGN.md |
| Cmd+F GP-Suche im Fenster | Suche im Filter-Panel; globale Command-Palette = Phase 2 (Plattform-Feature, nicht Modul) | Scope |
| Sofortige Desktop-Reaktivität | Alpine-first in Editoren, Livewire-Sync bei Blur/Save | Web-Realität, ehrlich benannt |
| Status ALL-CAPS-Badges (`APPROVED`) | deutsche Pills (Freigegeben/Vorläufig) wie im Slice | DESIGN.md-Ton |

## Offene Punkte

1. **x-ui-modal vs. Custom-Frosted-Modal** → Martin (s. P-2).
2. Command-Palette (Cmd+K) plattformweit — Wunsch deponieren, nicht Modul-Scope.
3. KPI-Leiste: Platzierung Actionbar vs. Navbar — beim ersten echten Screen mit Martin klären.
