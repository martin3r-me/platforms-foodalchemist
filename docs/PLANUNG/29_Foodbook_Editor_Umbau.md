# Spec 29 — Foodbook-Seite → Editor-Page

**Status: KOMPLETT (S1–S9 + S6.1 + S7), auf `feat/foodbook-editor-umbau`. Offen: Push, Office-Issue, manuelle Browser-Abnahme, demo-Deploy (Martin).**

## Kontext & Ziel

Die Foodbook-Seite (`/foodbooks`) war der letzte große Screen im alten Cockpit-Look — eine Master-Detail-Vollseite mit selbstgebautem 7-Tab-Cockpit in der Mitte (Alpine-only), links Liste + Kapitelbaum, rechts genestete Leitstelle-Rail. Zwei Übersichtlichkeits-Probleme: (1) Doppel-Stack — bei gewähltem Kapitel rendert der Block-Editor **zusätzlich** unter dem Cockpit; (2) überladene Tabs (Briefing = Stammdaten + CRM + Leitidee + Kickoff + Bedarf).

**Ziel:** Logik bleibt, Fläche auf das Spec-28-Editor-Muster heben und sauber trennen: **Ansehen** (Seite) · **Steuern/Ausgeben** (Seite) · **Bearbeiten** (Fullscreen-Modal).

## Getroffene Entscheidungen (mit Dominique)

- Editor-Form: **Fullscreen-Modal** (wie Rezept/GP/Concepter).
- Canvas: **Dark** (`.fa-editor-panel`).
- Kapitel/Speisen: **eigener „Speisen"-Tab** (nur bei gewähltem Kapitel).
- Leitstelle: **rechte Rail im Editor** — als **Option A**: 3-Spalten-Cockpit (links Navi, Mitte Tabs, rechts Rail), damit Kapitel-Wechsel im Editor möglich ist (Vollbild-Modal verdeckt sonst die Seiten-Sidebar).
- Live-Vorschau: mittlerer Hauptteil der **Listen-Seite** (read-only Kundensicht).
- Export-Tools (Dokument/Präsentation): **auf Seiten-Ebene**, nicht im Editor. Vorschau-Tab entfällt.

## Etappen

| S | Inhalt | Stand |
|---|---|---|
| S1 | Live-Vorschau (Partial `menue-vorschau`) + Ausgabe-Leiste auf der Seite | ✅ |
| S2 | Cockpit in `x-foodalchemist::modal fullscreen` | ✅ |
| S3 | Bespoke Pill-Tabs → geteilte `editor-tabs` (Alpine, `marker=fb`); Event-Bus (fb-goto/fb-cockpit-tab) als headless-Kind rehomed; Vorschau-Tab raus | ✅ |
| S4 | KPI-Kopf (`kpi-tiles`, `marker=fb-kpis`): Kapitel · Speisen · VK/Person (accent) · Fortschritt | ✅ |
| S5 | ~~modal-section je Tab~~ — **entfällt**: generische `editor-dark`-Kaskade fängt `$card` (bg-white/60) | ✅ (obsolet) |
| S6 | Kapitel/Block-Editor → eigener `speisen`-Tab (bedingt); `kapitelWaehle`→speisen / `kopfAnzeigen`→briefing via fb-goto; Doppel-Stack weg. Koexistenz-Bugfix gewahrt | ✅ |
| S6.1 | Speisen-Einfügen **inline** (Concepter-Muster): fb-concept/fb-gericht-Modals → inline-Panels mit Concept/Gericht-Umschalter (`einf`), „+" fügt direkt ein und bleibt offen. `conceptHinzu`/`gerichtHinzu` + Suche/Facetten unverändert reused | ✅ |
| S9 | Dark-Canvas am Modal + genestete Picker (fb-concept/fb-gericht) selbst dark-canvas (lagen unter `.fa-editor-panel` → Schrift aufgehellt auf hellem Grund) | ✅ |
| — | Leitstellen-Leiste (Schritte + Phasen) entquetscht (Chip-Padding/Abstand) | ✅ |
| S8 | 3-Spalten-Cockpit im Modal (Option A): links Foodbook-Kopf + Kapitelbaum, Mitte Tabs, rechts Leitstelle-Rail. `-mx-6`/px-6-Trick, damit die sticky Tab-Leiste auf Spaltenbreite spannt. activity-Slot der Seite entfernt (sonst doppelter wire:key) | ✅ |
| S7 | Tab-Split: Bedarf + Kickoff Briefing→Planung + eigener „DNA & Ton"-Tab (Kunde-DNA + Leitplanken + Tonalität aus Kreativ). Checkliste bedarf→planung, Rail-tabMap um dna/speisen ergänzt | ✅ |

## Schlüssel-Fallen (dokumentiert)

- **editor-tabs Alpine-Modus, nicht Server** — sonst Remount des genesteten `kunde-dna-panel` + Bus-Bruch.
- **Event-Bus** liegt im headless-Slot-Kind, nicht am Wrapper (nested-scope-Shadowing).
- **Koexistenz-Bugfix** (`index.blade` §Kommentar): Cockpit NIE per PHP-`@if` auf Kapitel-/Tab-State klammern.
- **Dark**: generische Kaskade reicht für `$card`; genestete Modals unter `.fa-editor-panel` brauchen eigenes `dark-canvas`.
- **3-Spalten + sticky Leiste**: `-mx-6` der editor-tabs erwartet einspaltigen px-6-Body → Mittelspalte px-6 zurückgeben.
- **Shared Checkout**: parallele Session/Branch — vor jedem Commit Branch prüfen, gezielt stagen.

## Verifikation

Pest ~86 grün (BladeCompiles, FoodbookUi, Leitstelle, Kapitel/Ziele, Branding, Kreativ, Struktur, DnaCascade, Signale, EditorBausteine). Headless-Chrome-CDP-Klickstrecke auf Sandbox :8765 (Login → Foodbook → Bearbeiten → Tab-Switch → Kapitel → Speisen → Picker). **Offen: manuelle Browser-Abnahme durch Dominique** (Picker-Zentrierung, 3-Spalten live) + demo-Deploy (Martin).

## Offen / Nicht-Bau-Rest

1. **Push** `feat/foodbook-editor-umbau` (trägt auch Dominiques Einkauf-Commit `83a797d` — bewusst „so lassen", shared Checkout) + **Office-Dev-Issue** (Package 23).
2. **Manuelle Browser-Abnahme** durch Dominique (3-Spalten-Cockpit, Picker-Zentrierung, inline-Einfügen).
3. Kleinigkeit: Kapitelbaum liegt doppelt (Seiten-Sidebar + Modal) — Seiten-Sidebar-Baum könnte raus.
4. **demo-Deploy** (Martin).
