---
typ: Arbeits-Roadmap (Feinschnitt)
stand: 2026-06-13
status: M11 Foodbook-MVP — Planung v2 (nach Modell-Klärung 2026-06-13); setzt auf M10 Concepter (gebaut) auf
domaene: D-8 (Foodbook-Teil; Chat bleibt out-of-scope)
---

# M11 — Foodbook (MVP): Arbeits-Roadmap

> **Zweck.** Das Foodbook ist der **Ort, an dem alles zusammenkommt**: Hier pflegt der User
> den **Kunden** (CRM), das **Briefing-Wissen** (Canvas-Modul), erstellt den **Kapitel-Baum**
> (Überschriften/Gliederung) und **fügt fertige Concepts ein** (referenziert aus der Concepter-
> Bibliothek, gefiltert nach Concept-Kategorie). Die **Live-Kalkulation** aggregiert nur den
> bestehenden Concept-Preis-Pfad (M10/HK1) — **kein eigener Rechner**.
>
> **Verbindlicher Rahmen:** Template-Anatomie (01 §2), Team-Hierarchie (D1, BelongsToTeamHierarchy),
> UI-Patterns P-1/P-3/P-5, Lineage-Trio (GL-07), Goldene Regel „nur im eigenen Modul" (→ D10).

## 0. Modell-Klärung (Gespräch 2026-06-13) — die tragenden Entscheide

| Frage | Entscheid | Konsequenz |
|---|---|---|
| Foodbook↔Concept: Pull oder Push? | **Pull** — Foodbook holt Concepts (kein „Freigabe"-Push) | Einfügen passiert im Foodbook-Editor |
| Referenz oder Kopie? | **Live-Referenz** | Concept-Änderung zieht durch; V-25 (Snapshot) NICHT im MVP, nur Schema-Hook |
| Ein Concept in mehrere Foodbooks? | **Ja, n:m** | Concept lebt einmal, zentral gepflegt; Block-Tabelle = die n:m-Verbindung |
| Concept-Kategorie (Lunchbuffet, Fingerfood, Eventcatering, Foodkonzepte …)? | **Eigenschaft des Concepts** (M10), GETRENNT vom Foodbook-Baum | dient als **Filter-Achse** im Concept-Picker, ist NICHT die Gliederung |
| Foodbook-Baum? | **Pro Foodbook frei erstellte Kapitel/Überschriften** | adjacency-Baum, unabhängig von Concept-Kategorie |
| Kunde? | **Verknüpfung CRM-Modul** via DimensionLinks (→ D10) | keine harte Cross-Modul-FK |
| Wissen/Briefing? | **Verknüpfung Canvas-Modul** via DimensionLinks (→ D10) | speist später KI-Texte (M11-08) |
| KI-Texte (Einleitung/Kapitel)? | **Lineage-Felder jetzt, KI-Befüllung extern/später** | Andock-Punkt aus Canvas-Briefing + Concepts |

**Es gibt KEIN separates „Zuordnungs-Modul".** Die Zuordnung Concept→Kapitel ist schlicht der
Akt des Einfügens im Foodbook-Editor; technisch trägt sie die Block-Tabelle (§1).

**Zwei getrennte Bäume — nie vermischen:**
```
CONCEPTER (Bibliothek)                    FOODBOOK (Dokument)
Concept-Kategorie                         Foodbook-Baum
(Lunchbuffet, Dinner-Buffet,              (Kapitel/Überschriften,
 Fingerfood, Eventcatering,                pro Foodbook frei)
 Foodkonzepte)                                │
   │ = Filter-/Suchachse                      │ = Gliederung des Dokuments
   ▼                                          ▼
 Concept 47 ───────────► Block ◄──────── kapitel_id + sort_order
   (lebt EINMAL)   (n:m, Live-Referenz)   Concept 47 → FB 3 / Ast „Vorspeisen" / Pos 2
                                          Concept 47 → FB 9 / Ast „Klassiker"  / Pos 1
```

**Explizit NICHT im MVP:** Snapshot/Versionierung (V-25), PDF-/Canva-Design-Ausgabe (V-26),
KI-Text-Generierung (extern), Chat (D-8-Chat-Teil), polymorphe Block-Typen außer concept_ref,
Foodbook-Vorlagen.

---

## 1. Datenmodell (Ziel, MVP-Teilmenge von 02-DATENMODELL §B)

```
foodalchemist_foodbooks
  id · uuid · team_id(NOT NULL, D1) · titel
  einleitung(text, nullable) + _quelle/_ai_confidence/_ai_begruendung   (KI-Lineage-Hook)
  status: enum(draft|released) DEFAULT draft        (V-25-Hook; MVP nur draft)
  snapshot_jsonb: jsonb nullable                    (V-25-Hook; MVP immer NULL)
  ── Cross-Modul-Verknüpfung NICHT als Spalte, sondern via DimensionLinks (D10):
     Kunde   → CRM-Objekt
     Wissen  → Canvas-Objekt
  timestamps · softDeletes · LogsActivity

foodalchemist_foodbook_kapitel
  id · uuid · team_id · foodbook_id(FK, cascade)
  parent_id(FK self, nullable)        ← Baum (adjacency list)
  titel · kapitel_text(text, nullable) + _quelle/_ai_confidence/_ai_begruendung
  sort_order(int)
  timestamps · softDeletes

foodalchemist_foodbook_blocks   ← die n:m-Verbindung Concept↔Foodbook-Kapitel
  id · uuid · team_id · kapitel_id(FK, cascade)
  typ: enum(concept_ref|…)  MVP: nur concept_ref
  concept_id(FK foodalchemist_concepts)  ← LIVE-Referenz, n:m-fähig, ON DELETE restrict
  sort_order(int) · notiz(text, nullable)
  timestamps · softDeletes
```

**Begründungen / Fallen:**
- concept_id ist **n:m-fähig**: dasselbe Concept darf in mehreren Foodbooks als Block
  auftauchen (Mehrfachverwendung). Keine Kopie — Live-Referenz.
- ON DELETE restrict auf concept_id: ein referenziertes Concept darf nicht still
  verschwinden → Service-Guard mit typisierter Exception (V-06, GT-FB-4).
- **Kunde/Wissen sind KEINE foodalchemist-Spalten.** Cross-Modul-FK würde die Goldene Regel
  („nur im eigenen Modul") verletzen. Stattdessen organization.dimension_links (D10).
- status/snapshot_jsonb MVP-seitig inert — nur Hooks, damit V-25 später nur Logik ergänzt.
- Concept-**Kategorie** lebt am Concept (M10), erscheint hier NICHT — nur als Picker-Filter.

---

## 1a. UI-Gestalt (Entscheide 2026-06-13) + Abweichungen von 11_UI_PATTERNS

| Aspekt | Entscheid | Verhältnis zu P-x |
|---|---|---|
| Grundaufbau | Foodbook-Liste **links**, großes **Editor-Panel rechts** | P-1, rechte Page-Sidebar als Arbeitsfläche |
| Foodbook-Kopf | Kunde (CRM) + Wissen (Canvas) + Einleitung oben im Editor | neu (Briefing-Bereich) |
| **FB-1** Concept-Quelle | ausklappbare **Concept-Palette** am rechten Editor-Rand, **gefiltert nach Kategorie** | Abweichung P-1: Palette statt Tabelle-Mitte |
| **FB-2** Kalkulation | **Sticky** Summen-Leiste, mitscrollend, immer sichtbar | neu (P-1 kennt nur Header-KPI) |
| **FB-3** Einfügen | **Drag** Palette→Kapitel-Knoten (Komfort) + „+ Concept"-Picker (Fallback) | erweitert P-8-Drag auf Zonen-übergreifend |
| Texte | **Inline** am Foodbook-Kopf / Kapitel-Knoten, kein Modal | bewusst NICHT P-2-Modal |
| Vorschau | **keine** im MVP | Post-MVP (V-26, ggf. Canva-Design-Ausgabe) |

> ⚠ **Offen mit Martin (in D10/UI):** (1) Zonen-übergreifendes Drag (Palette→Baum) — Picker-
> Fallback macht es MVP-unkritisch. (2) Wie CRM-/Canvas-Picker im Foodbook-Kopf eingebunden
> werden (Cross-Modul-Select via DimensionLinks-API).

---

## 2. Arbeitspakete

> Reihenfolge strikt M11-00 → M11-12. Jedes Paket endet mit grüner Teilsuite + Beleg.
> M11-09…12 sind Politur/optional für MVP-Abschluss.

### M11-00 — Schema & Migrations
- 3 Migrations (foodbooks, foodbook_kapitel, foodbook_blocks) nach §1; Enums
  FoodbookStatus, FoodbookBlockTyp.
- **Gate:** migrate + Rollback grün; FKs/Indizes (team_id, foodbook_id, kapitel_id,
  parent_id, concept_id) gesetzt.

### M11-01 — Models & Relations
- 3 Models (UuidV7 / SoftDeletes / LogsActivity / BelongsToTeamHierarchy).
- Relations + Eager-Pfad foodbook.kapitel.blocks.concept.
- **Gate:** Factory-Seed (1 FB · 2 Kapitel verschachtelt · 3 Concept-Blocks);
  Pest-Test „Geschwister-Team sieht nichts" (D1-Pflicht).

### M11-01b — Cross-Modul-Verknüpfung Kunde + Wissen (⚠D10)
- Foodbook-Kopf verknüpft **CRM-Kunde** + **Canvas-Wissen** via organization.dimension_links.
- FoodbookService::linkCustomer()/linkCanvas() kapseln die DimensionLinks-API; Lese-Pfad
  löst Anzeigenamen auf (Kunde-Name, Canvas-Titel).
- **Blockiert auf D10** (Anbindungs-Form mit Martin). Bis dahin: Feld als loser Ref-String
  (Anzeigename) baubar, echte Verlinkung nachziehen.
- **Gate:** Kunde + Canvas verknüpfbar + im Kopf angezeigt; Entkopplung möglich.

### M11-02 — FoodbookService (CRUD + Guards)
- create/update/delete, Kapitel-CRUD, Block-CRUD — alles in DB-Transaktionen (V-07).
- Guards: Block-Anlage prüft Concept-Sichtbarkeit (D1-Hierarchie); Concept-Löschung mit
  aktivem Block-Verweis → typisierte Exception (V-06).
- **Gate:** Service-Tests inkl. Guard-Pfade grün.

### M11-03 — FoodbookService::calculate() (Aggregation, KEIN neuer Rechner)
- Summiert je Block den **bestehenden Concept-Preis** (M10-Pfad), rollt Kapitel→Foodbook auf;
  erbt HK1/HK2 automatisch. Leere Kapitel / Block ohne Concept → 0 €, nie Exception.
- **Gate:** **GT-FB-1** (Summe = Σ Concept-Live-Preise); **GT-FB-2** (Lead-LA-Wechsel an
  einem Concept → Foodbook-Summe zieht live mit — beweist Referenz-Entscheid).

### M11-04 — Livewire Foodbooks/Index (P-1)
- Liste links (Titel · Kunde · #Concepts · Gesamtpreis), großes Editor-Panel rechts.
- URL-Sync (?foodbook=), Kontext-Erhalt (P-1), keine eigenen Detail-Routen.
- **Gate:** Klick selektiert ohne Seitenwechsel; Reload hält Auswahl.

### M11-05 — Kapitel-Baum-Editor (P-5 + wire:sortable + Inline-Texte)
- Baum-Render (x-foodalchemist::tree), Kapitel hinzufügen/umbenennen/löschen/verschachteln,
  wire:sortable. Inline-Text am Kapitel-Knoten + Einleitung am Kopf (kein Modal).
- **Gate:** Verschachtelung 2 Ebenen, Reorder persistiert, Lösch-Kaskade greift; Inline-Text
  speichert bei Blur ohne Baum-Rerender.

### M11-06 — Concept einfügen: Drag-Palette + Picker, gefiltert nach Kategorie (FB-1/FB-3)
- **Concept-Palette** am rechten Editor-Rand: sichtbare Concepts (D1), **Filter nach Concept-
  Kategorie** (Lunchbuffet, Fingerfood …) + Suche.
- **Drag** Palette→Kapitel-Knoten legt concept_ref-Block an; **„+ Concept"-Picker** am Kapitel
  als robuster Fallback (gleicher Service-Pfad). Block-Reorder via wire:sortable.
- **Gate:** Concept aus M10 landet per Drag UND Picker als Block; Kategorie-Filter wirkt;
  n:m bewiesen (gleiches Concept in zweitem Foodbook); Summe aktualisiert in place.

### M11-07 — Sticky Live-Kalkulation (FB-2)
- Fix mitscrollende Summen-Leiste (Gesamt immer sichtbar), optional Kapitel-Zwischensummen.
  Hört auf dasselbe Update-Event wie der Baum → nie veraltet. lazy je teurer Sektion.
- **Gate:** Anzeige = calculate(); bleibt beim Scrollen sichtbar; Baum-Änderung aktualisiert
  Summe sofort (kein Lag — Anti-Accept-Bug).

### M11-08 — KI-Text-Andock (Lineage-Header P-3, Befüllung extern/später)
- Inline-Textfelder (Einleitung/Kapitel): _quelle-Default manual; P-3-Header **mit
  disabled Autopilot** + Tooltip „KI-Befüllung folgt". Der spätere KI-Pfad speist sich aus
  **Canvas-Briefing (M11-01b) + zugeordneten Concepts**.
- **Gate:** Text speichert, Lineage manual; KI-Button disabled, kein toter Klick;
  Andock-Kontext (Canvas-Ref + Concept-Liste) für späteren Prompt abrufbar.

### M11-09 — Block-Notiz + Leerzustände (Politur)
- Notiz pro Block; Empty-States (FB ohne Kapitel, Kapitel ohne Concept, kein Kunde).
- **Gate:** Empty-States gerendert, kein Layout-Bruch.

### M11-10 — Rechte/Policies (V-12)
- FoodbookPolicy (view/create/update/delete) + check.module.permission.
- **Gate:** Viewer liest, editiert nicht; Cross-Team verweigert.

### M11-11 — MCP Read-Tools (MVP: nur GET)
- foodalchemist.foodbook.GET (+ kapitel/blocks), rufen Service (nie Models, 01 §6).
- **Gate:** tools__GET(module=foodalchemist) listet sie; Schema-Validierung grün.

### M11-12 — Abnahme & Korpus-Nachträge
- Golden-Suite grün; Register-/Decision-Nachträge (V-25-Notiz, D-8-Präzisierung, D10 neu,
  11_UI_PATTERNS-Abweichung FB-1/2/3) eingetragen.
- **Gate:** Gesamtsuite grün; Korpus konsistent.

---

## 3. Golden-Tests

| ID | Prüft | Erwartung |
|---|---|---|
| GT-FB-1 | Aggregation | Foodbook-Summe = Σ Concept-Live-Preise über Kapitel-Baum |
| GT-FB-2 | Live-Referenz | Lead-LA-Wechsel an Concept → Foodbook-Summe zieht live mit |
| GT-FB-3 | n:m | Gleiches Concept in 2 Foodbooks; Änderung wirkt in beiden; keine Kopie |
| GT-FB-4 | Guard | Löschen referenzierten Concepts wirft typisierte Exception (V-06) |
| GT-FB-5 | Baum | Kapitel-Verschachtelung + Reorder persistieren; Block-Lösch-Kaskade |
| GT-FB-6 | D1-Isolation | Geschwister-Team sieht fremdes Foodbook nicht; Eltern-Concepts referenzierbar |
| GT-FB-7 | Kategorie-Filter | Palette zeigt nur Concepts der gewählten Kategorie |

---

## 4. Neuer Entscheid (für 08_ENTSCHEIDUNGEN.md)

### D10 — Cross-Modul-Verknüpfung Foodbook ↔ CRM + Canvas
- **Frage:** Wie hängt das Foodbook an Kunde (CRM-Modul) und Wissen (Canvas-Modul), ohne die
  Goldene Regel „nur im eigenen Modul / keine Cross-Modul-FK" zu verletzen?
- **Arbeits-Annahme:** Über organization.dimension_links (Plattform-nativer Mechanismus —
  „alle Modul-Objekte hängen via DimensionLinks an Entities"). Foodbook-Objekt als
  context_type+context_id, Kunde/Canvas als verlinkte Dimensionen.
- **Restfragen (Martin):** (1) Eigene Foodbook-Entity nötig oder Link direkt am Objekt?
  (2) Cross-Modul-Picker-API für CRM-Company + Canvas-Entry im Foodbook-UI. (3) Sichtbarkeit/
  Rechte über Modulgrenzen.
- **Blockiert:** nur M11-01b (echte Verlinkung); Rest baubar mit losem Anzeigenamen.

## 5. Register-Nachträge (für 10_VERBESSERUNGS_REGISTER.md / 08)
- **V-25:** MVP = Live-Referenz; Schema-Hooks status+snapshot_jsonb vorhanden, Logik offen.
- **V-26:** PDF/Design-Ausgabe (ggf. Canva) Post-MVP.
- **D-8 (Foodbook-Teil):** hiermit MVP-spezifiziert (Pull/Referenz/n:m); Chat bleibt Phase 2.

## 6. Bewusst offen (nicht im MVP)
1. Re-Edit eines released Foodbooks → entfällt (kein released im MVP); bei V-25: Copy-on-Write.
2. Foodbook-Vorlagen → Post-MVP, Mechanik = Fork wie M10.
3. Concept-Verschachtelung (Concept-in-Concept) → Concepter-offen, fürs Foodbook irrelevant.
4. Canva-Design-Ausgabe (echtes Canva, nicht Canvas-Modul) → V-26-Nachbarschaft, Post-MVP.
