# Spec 41 — Foodbook wird reine Ausgabe-Form; Planung zieht zentral in die Leitstelle

> **Tracking:** Office Dev-Package 23, Features-Board (Board 53). Architektur-/Vertrags-Spec +
> gestufte Bau-Runden. **Revidiert bewusst [Spec 40 §1](40_Leitstelle_Planungs_Spine.md) Phase A**
> („der Rahmen wird im Ausgabe-Modul geplant") und setzt die Zielarchitektur aus
> [Spec 38](38_Roadmap_Planung_Leitstelle.md) („Ausgabe-Module = Quelle und Ziel") konsequent um.

**Status:** Konzept 2026-08-22 (mit Dominique, Doc-first). Umsetzung noch nicht begonnen.
Statuswerte (aus [README](../README.md)): `gebaut` · `getestet` (Sandbox) · `demo-geprüft` · `abgenommen`.

Alle Codepfade relativ zu `platforms-foodalchemist/` (canonical Clone). Zeilennummern sind Wegweiser —
vor dem Edit gegen den aktuellen Stand verifizieren.

---

## Anlass

Dominiques Leitfrage: *Die Planungs-Leitstelle nimmt eine zentrale Rolle ein — über Kaskaden läuft
dort alles. Foodbook, Speisekarte und Speiseplan laufen aber noch nicht sauber darüber. Das Foodbook
ist heute mit Planung vollgeknallt, die eigentlich in die Leitstelle gehört. Die drei Ausgabeformen
sollen die handliche Ausgabe verkörpern — so wie Basisrezepte oder Gerichte: man bearbeitet die fertige
Ausgabe, man plant sie nicht dort.*

**Code-Befund (2026-08-22):**
- **Speisekarte** (`Speisekarte/Index`) und **Speiseplan** (`Speiseplan/Editor`) sind bereits
  Ausgabe-Formen. Ihre einzige Planungs-Kopplung ist **ein** `vollKaskadeStarten`-Knopf in die
  Leitstelle (+ Speisekarte-KI-*Wording*).
- **Foodbook** (`Foodbooks/Index`, ~1450 Zeilen) ist ein volles Planungs-Cockpit mit 7–8 Tabs
  (Briefing · Planung · Speisen · Kreativ · DNA · Trend · Branding · Preise) + `LeitstelleRail`
  (Ausbau Spec 19/29, Juli 2026). **Das ist das „vollgeknallt".**
- Der **Round-Trip-Seam existiert schon** (Spec 40): `vollKaskadeStarten` →
  `starteKaskade('vollkaskade', owner_type=…)` → `GenerateConceptJob::attachToOutput` schreibt das
  Ergebnis automatisch zurück (`FoodbookService::addBlock('concept_ref')`).

Spec 40 hat den Round-Trip-Vertrag festgeschrieben, aber die Planungs-Tabs nie aus dem Foodbook
ausgeräumt. Diese Spec holt das nach — und geht einen Schritt weiter als Spec 40.

---

## Der revidierte Vertrag

**Spec 40 §1 sagte:** Rahmen (Phase A) im Modul · Erstellung (Phase B) in der Leitstelle ·
Zusammenbau (Phase C) im Modul.

**Spec 41 revidiert Phase A:** Auch die **Rahmen-Planung zieht in die Leitstelle** (Entscheidung
Dominique 2026-08-22, „radikal"). Die Ausgabe-Module planen nichts mehr — sie **kuratieren und geben aus**.

| Phase | Zuständig (Spec 41) | Was passiert | Artefakt |
|---|---|---|---|
| **A · Rahmen** | **Leitstelle** | Brief → Gerüst/Kapitel-Struktur + Leitplanken planen | `FoodAlchemistPlanningFrame` + Struktur |
| **B · Erstellung** | **Leitstelle** | je Slot Konzept/Gericht generieren, gestuft freigeben, Kaskade bis GP/LA | `CascadeRun`, `Concept`, `Recipe` |
| **C · Zusammenbau/Ausgabe** | **Ausgabe-Modul** | Kuratieren + Deliverable bauen: Foodbook-PDF, Karte, Aushang | Modul-Dokument |

**Der Vertragssatz (Spec 41):** *Geplant und erzeugt wird immer in der Leitstelle. Die Ausgabe-Module
(Foodbook/Speisekarte/Speiseplan) kuratieren das Ergebnis und bauen das Deliverable — sie sind die
handliche Ausgabe, kein Planungsort.*

### Die kritische Invariante — Erzeugen vs. Kuratieren

Damit nicht „zwei Planer" zurückkommen (genau das Problem, das Spec 40 vermeiden wollte), gilt eine
harte Grenze:

- **Leitstelle *erzeugt*:** Brief, Gerüst/Struktur-Planung, KI-Inhalte (Concepts/Gerichte/Basisrezepte),
  Skizzen/Divergenz, Trend-Analyse, Leitplanken-Regler. Ergebnis dockt automatisch ins Ausgabe-Modul.
- **Ausgabe-Modul *kuratiert*:** das bereits Erzeugte anordnen (Kapitel/Blöcke umsortieren, für den
  Kunden umbenennen, ein-/ausblenden, A/B-Wahlgruppen), ein Bestands-Gericht/-Konzept rein/raus,
  Branding/CI, Live-Vorschau, PDF/Präsentation-Export. **Kein Brief, kein KI-Erfinden, keine
  Gerüst-Planung „from scratch" im Modul.**

„Struktur bearbeiten" (Reihenfolge/Benennung eines existierenden Kapitels) ist Kuratieren und bleibt im
Modul. „Struktur aus einem Brief planen" ist Erzeugen und lebt in der Leitstelle.

**Zwei Wege ein Foodbook zu füllen bleiben** (wie in Spec 40): KI-Vollkaskade (Power-Weg, Leitstelle)
oder Bestands-/Manuell-Pfad (Alltags-Weg, Bestands-Picker im Modul). Beide nutzen dieselbe Ausgabe.

---

## ⚠️ Vorbedingung (Sequencing)

`origin/main` (Stand 2026-08-22 `5c35763`) trägt **weder `feat/spec40-umsetzung` noch
`feat/format-modul`** — beide Branches editieren `Foodbooks/Index.php`. Ein radikaler Strip *vor* deren
Merge kollidiert an derselben ~1450-Zeilen-Datei mit zwei fremden Branches.

**Reihenfolge (Dominiques Entscheidung, vor dem Code-Strip):**
1. **Spec 40 mergen** → main + demo. Liefert Owner-Banner + Zurück-Link (E1b) + Attach-Härtung (E-P0) —
   die Bausteine, die der Round-Trip nach dem Strip braucht.
2. **Format-Modul mergen** → main + demo (oder bewusst zurückstellen).
3. **Erst dann** diesen Umbau auf frischem `origin/main` (Branch `feat/foodbook-ausgabe`, Worktree).

---

## Etappen

### F0 — Diese Spec (doc-first) · Status: gebaut (dieses Dokument)
Vertrag revidieren, Invariante festschreiben, Sequencing dokumentieren.

### F1 — Leitstelle: Foodbook-Lifecycle von vorn (Phase A zieht rein)
Die Leitstelle bekommt den Einstieg, ein **ganzes Foodbook aus einem Brief** zu planen — Surfacing
bestehender Logik, die heute im Foodbook hängt.
- Einstieg „Foodbook aus Brief" in `Planung/Index`: Brief + Leitplanken → `FoodbookService::create`
  (Hülle) → `ConceptGeneratorService::geruestAusBriefFuerOwner` (owner_type=`foodbook`) →
  `FoodbookService::strukturAusGeruest` → `starteKaskade('vollkaskade', owner_type=foodbook)`.
- `ManagesPlanningFrame` (owner-neutral) in `Planung/Index` einhängen (Gerüst/Slots/Ziele dort planen).
- Owner-Banner + Zurück-Link (Spec-40 E1b, `PlanningCascadeService::ownerKontext`) für den sichtbaren
  Round-Trip.
- `starteVollkaskade` + `attachToOutput` (foodbook → `addBlock('concept_ref')`) unverändert — nur der
  *Auslöser* wandert.

### F2 — `Foodbooks/Index` auf Ausgabe/Kuratieren abspecken
Aus dem 7–8-Tab-Cockpit eine schlanke Ausgabe-Form machen (Muster: Speisekarte/Speiseplan-Editor).

**Bleibt (Ausgabe/Kuratieren):** Kapitelbaum kuratieren (`kapitel*`, `reorderKapitel`, `moveKapitel`,
`consumer_title`/`description`) · Speisen-Bestands-Picker (`conceptHinzu`/`gerichtHinzu`, Header/Text/
Spacer/Image, Block-Layout, A/B-Gruppen) · Branding/CI · Preise-Sicht (`preiseBaum`) · Live-Vorschau
(`vorschauSnapshot`) · Export (`foodbooks.dokument` PDF + `Praesentation`) · schlanke Kopf-Defaults
(`kundentyp`/`default_niveau`/`default_convenience`/`writing_style_id`/`gueltig_von/bis`/`outlet_id`/
`personen`) · KI-Kundentext als Ausgabe-Politur (`kiEinleitung`/`kiKapitelText`/`kiKundentext`,
operiert auf existierenden Kapiteln — Grenzfall, s.u.) · **1 Knopf „In der Leitstelle planen"**.

**Raus (→ Leitstelle):** Planung-Tab (Frame/Gerüst-Planung, `frameAusBriefVorschlagen`,
`strukturAnwenden`, Slot-KI-Fill `slotFuellen 'neu'`) · Kreativ-Tab (Ideen/Skizzen, 3-Modus,
Pairing-Inspiration) · Trend-Tab · DNA-Board (→ F3) · `LeitstelleRail`-Planungs-Aktionen
(`kapitelAnlegen`/`anlageZuruckziehen`, M3-Ziele-Planung; read-only Checkliste/Coverage darf bleiben).
Zugehörige Props/Blade-Tabs entfernen; `ManagesCanvas`/`ManagesPlanningFrame`/`ManagesPhase` lösen.

**Blade:** Tab-Leiste auf **Kontext(schlank) · Struktur · Speisen · Branding · Preise**. Vorschau/Export
bleiben auf Seiten-Ebene (Spec 29 S1). `wire:key`/Alpine-Fallen beachten, Kompilat linten.

### F3 — Kunde-DNA-Board → Kunden-/Einstellungen-Ebene
`Foodbooks/KundeDnaPanel` aus dem Foodbook lösen und auf die Kunden-Kontext-/Einstellungen-Ebene heben
(Marken-DNA ist kunden-, nicht foodbook-scoped; Team-DNA liegt dort schon). Am Foodbook bleibt der
schlanke Selektor (`writing_style_id`/`kundentyp`/`default_niveau`) als Kopf-Default.
`CanvasService::cascadeKontext` bleibt intakt (die Leitstelle liest die DNA-Kaskade beim Erzeugen weiter).

### F4 — Speisekarte/Speiseplan harmonisieren
Beide sind schon Ausgabe-Formen. Minimal: „Voll-Kaskade"-Knopf-Wording + Owner-Banner/Zurück-Link-
Parität. Speisekarte-KI-Wording bleibt (Ausgabe-Politur, dieselbe Grenzfall-Regel).

### F5 — MCP + Tests + Docs
MCP-Lockstep (Leitstellen-Einstieg „Foodbook aus Brief" spiegeln; Foodbook-Tools auf Kuratier-/Ausgabe-
Writes reduzieren) · Tests (`FoodbookUiTest` auf reduziertes Tab-Set; neuer `LeitstelleFoodbookLifecycleTest`;
Cross-Tenant je neuer Action; Baseline-Regressionen per `git stash` gegenprüfen) · Docs (`foodbook.md`
auf „Ausgabe/Kuratieren"; ROADMAP 38 + Office-Package 23).

---

## Offene Punkte / Risiken

- **Vertrags-Revision:** revidiert Spec-40 §1 Phase A bewusst. Die Erzeugen-vs-Kuratieren-Invariante muss
  diszipliniert gehalten werden.
- **Drei-Branch-Kollision** an `Foodbooks/Index.php` → Sequencing (oben) ist Pflicht.
- **Grenzfall KI-Kundentext/-Wording:** bleibt als Ausgabe-Politur (Parität Speisekarte). Falls „wirklich
  alles KI in die Leitstelle" gewünscht — auch diese Helfer entfernen (1-Zeilen-Entscheidung).
- **Foodbook-Bootstrap:** nach dem Strip entsteht ein Foodbook primär aus der Leitstelle (Brief); der
  leere „manuell anlegen + Bestand kuratieren"-Weg bleibt als Alltags-Pfad erhalten.

## Referenzen
- [Spec 40 — Leitstelle Planungs-Spine](40_Leitstelle_Planungs_Spine.md) (Round-Trip; §1 Phase A hier revidiert)
- [Spec 38 — Roadmap Planung-Leitstelle](38_Roadmap_Planung_Leitstelle.md) (Zielarchitektur „Quelle und Ziel")
- Code: `Planung/Index` · `Foodbooks/Index` + `LeitstelleRail` + `KundeDnaPanel` · `Speisekarte/Index` ·
  `Speiseplan/Editor` · `PlanningCascadeService` (`starteVollkaskade`/`ownerKontext`/`attachToOutput`) ·
  `ConceptGeneratorService::geruestAusBriefFuerOwner` · `FoodbookService` (`create`/`strukturAusGeruest`/
  `addBlock`/`vorschauSnapshot`/`dokumentDaten`)
