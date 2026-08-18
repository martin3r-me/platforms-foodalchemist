# Spec — Concepter in der Leitstelle sauber: Semantik + Speisen inline, ein Ort, ein Kontext

> **Tracking:** Office Dev-Package 23, Features-Board (Board 53). Detail-Ausarbeitung der **Phase B** aus [Spec 40 — Leitstelle als Planungs-Spine](40_Leitstelle_Planungs_Spine.md) für den **Concept-Scope**: wie der KI-Kopf-Plan und die vorgeschlagenen Speisen **in der Leitstelle sichtbar, editierbar und kontext-kohärent** werden — statt in den separaten Conceptor wegzuspringen.

**Status:** Konzept 2026-08-18 (mit Dominique erarbeitet, Doc-first). **Kein Code in dieser Runde** — Bau in fokussierter Session. Statuswerte (aus [README](../README.md)): `gebaut` · `getestet` (Sandbox) · `demo-geprüft` · `abgenommen`.

Alle Codepfade relativ zu `platforms-foodalchemist/` (canonical Clone). Zeilennummern = Stand 2026-08-18, als Wegweiser (vor dem Edit verifizieren).

---

## Anlass (Ist-Beschwerden)

Dominique testet den Concept-Pfad in der Planungs-Leitstelle und stößt auf drei Brüche:

1. **Der KI-Kopf springt weg und geht verloren.** „Ausarbeiten" (`kiKopf`) arbeitet den Plan aus, **dispatcht dann `concepter-editor.oeffnen`** und öffnet den separaten Conceptor (`src/Livewire/Planung/Index.php:1429`). Zurück in der Leitstelle ist beim Klick auf „Konzept erzeugen" der Kopf nicht mehr sichtbar — es gibt gar keine Leitstellen-Fläche für den Plan (`kiKopf` startet **keine** Kaskade, also auch keine `ergebnis`-Ausgabe).
2. **Die Concept-Ausgabe ist nicht sichtbar.** *„Ich sehe nicht, welche Speisen er vorschlägt."* Der Worker-Concept-Step bietet nur **„öffnen"** → wieder in den Conceptor (`resources/views/livewire/planung/partials/step-zeile.blade.php:22–24`). Kein Inline-Blick auf Leitidee/Speisen.
3. **Keine Feedback-Schleife.** Dominique will die Vorschläge *„per Klick und Kommentar ändern"* und gezielt neu erzeugen — statt alles-oder-nichts.

Dazu Dominiques Präzisierung des **Datenflusses** (2026-08-18): die Leitplanken **und** die Semantik-Felder (Leitidee & co.) sollen in der Leitstelle einsehbar sein, *„dann wandern sie auch in die LLM … wenn das Konzept erstellt wird, wandert mit rüber ins Concept."*

**Wichtig — das Muster existiert schon:** Für **Rezept/Gericht**-Drafts gibt es das Inline-Review bereits (`toggleZutaten` → eingebetteter `IngredientEditor`, `step-zeile.blade.php:181–196`). Für das **Concept** fehlt es. Diese Spec überträgt das Muster + schließt die Datenfluss-Lücke.

---

## Leitprinzip: „Ein Ort, ein Kontext"

> **Leitplanken + Semantik-Felder sind in der Leitstelle sichtbar + editierbar; beide sind der LLM-Kontext für die Concept-/Gericht-Erzeugung; beide leben auf dem Concept und vererben sich in den Fan-out. Die Leitstelle zeigt und steuert — der Conceptor ist nur noch optionaler Tiefen-Editor.**

Das ist kein Spiegel, sondern die **Live-Quelle**: die inline gezeigten/editierten Felder *sind* die Felder, die (a) in den LLM-Prompt fließen und (b) im `FoodAlchemistConcept` landen. Leitidee in der Leitstelle ändern → das Gerüst/die Gerichte werden mit dem neuen Kontext erzeugt.

---

## Datenfluss — Ist (grounded), keine Erfindung

Der Fluss existiert bereits im Code; er ist nur **unsichtbar** (Semantik nur im Conceptor, Leitplanken teils unsauber). Beide Bündel sind reale Felder:

### Leitplanken (Regler → Prompt + Fan-out)
`reglerParams($scope)` (`Index.php:1063`) sammelt die Regler; für Concept u.a. die Menü-Achsen `menue_preis_{min,ziel,max}_pp`, `menue_gaenge`, `menue_quote_{vegan,vegetarisch}_pct` (belegt in `ConceptGeneratorService` :185). Der Weg:

```
reglerParams(scope)  →  goKaskade: setGenerationParams (Session, Fan-out-Vererbung)  +  Lauf-params
                     →  RecipeGenerationContextService::promptParameter (PROMPT_KEYS-Whitelist)  →  LLM
```

`PROMPT_KEYS` (`src/Services/RecipeGenerationContextService.php:20–25`) ist die bewusste Whitelist: nur semantisch bedeutsame Achsen landen im Prompt (Steuer-Keys sind Rauschen). **Additiv gehalten** — hier werden neue Achsen eingetragen.

### Semantik-Felder (Concept-Canvas → LLM-Kontext + persistiert auf dem Concept)
Der KI-Kopf füllt die kreative Concept-Canvas (`ConceptGeneratorService::fuelleCanvasAusPlan`, :399–423). Reale Felder (`concept.plan`-Canvas, via `CanvasService::TEMPLATES['concept']`):

| Feld | Typ | Rolle |
|---|---|---|
| `name_claim` | Skalar | Name + Claim des Konzepts (bei Concept-Go = Namensquelle, `Index.php:1507`) |
| `leitidee` | Langtext | die kreative Leitidee |
| `usp_eignung` | Langtext | USP / Eignung |
| `inszenierung` | Langtext | Inszenierung / Anrichtung |
| `geschmackswelten[]` | repeatable | je Welt: `claim` (Überschrift) + `description` |

Diese Felder liegen auf dem `FoodAlchemistConcept` und **fließen als Kontext in alle KI-Texte dieses Konzepts** (bestätigt durch den Conceptor-Hinweistext + `fuelleCanvasAusPlan`). Beim Concept-Go referenziert `goKaskade` den geprüften Plan (`existing_concept_id`, `Index.php:1521–1527`) — es wird also **nicht** neu geraten, sondern der inline geprüfte Plan gebaut.

### Die Lücke
- Semantik: nur im **separaten Conceptor** sichtbar → Wegsprung, „Kopf verloren".
- Leitplanken: teils **scope-fremd/redundant** in der Menü-Leitplanken-Fläche (Teil B).
- Kein **In-Context-Edit** des Plans in der Leitstelle → keine Feedback-Schleife.

---

## Teil A — Concept-Ausgabe inline in der Leitstelle

Ziel: KI-Kopf-Plan **und** vorgeschlagene Speisen in der Leitstelle rendern, editierbar, mit gezieltem Regenerate. Der Wegsprung in den Conceptor wird optional.

### A0 · Inline-Plan-Panel (statt Wegsprung)
Wenn ein KI-Kopf-Plan vorliegt (`planConceptId` gesetzt), rendert der Concept-Tab ein **Plan-Vorschau-Panel** direkt in der Leitstelle:
- Semantik-Felder aus der `concept.plan`-Canvas: `name_claim`, `leitidee`, `usp_eignung`, `inszenierung`, `geschmackswelten[]` — gerendert (nicht nur verlinkt).
- **`kiKopf` springt nicht mehr weg:** das `dispatch('concepter-editor.oeffnen', …)` (`Index.php:1429`) entfällt/wird zu einem opt-in-Knopf „Im Conceptor tief bearbeiten".
- **Berührt:** `kiKopf` (Dispatch raus), neues Partial `partials/concept-plan.blade.php`, gerendert im Concept-Tab in der Nähe des Go-Knopfs. Lesequelle: `CanvasService` (concept.plan) + Frame-Slots.

### A1 · Vorgeschlagene Speisen sichtbar
Die Fan-out-Slots / das Gerüst des Plans werden als **Speisenliste** gezeigt (Slot-Name/Rolle/Gang, ggf. Preis-Korridor je Gang) — im Plan-Panel (A0) **und** im Worker-Concept-Step nach dem Go.
- **Berührt:** `concept-plan.blade.php` (Slot-Liste aus `materialisiereLeereSlots`/Frame) + `step-zeile.blade.php:22–24` — der Concept-Zweig bekommt eine Inline-Aufklappung (Muster wie `toggleZutaten`, :181–196), statt nur „öffnen".

### A2 · Editieren + Kommentieren → gezieltes Regenerate
Je Semantik-Feld und je Speise ein leichter Eingriff:
- **Feld editieren:** Leitidee/USP/Inszenierung/Geschmackswelt inline ändern → schreibt die Canvas (= LLM-Kontext) → nächste Erzeugung nutzt den neuen Kontext.
- **Speise ändern per Klick + Kommentar:** freier Kommentar an einer Speise → **gezieltes Regenerate nur dieser Speise** mit dem Kommentar als Zusatz-Direktive (nicht das ganze Gerüst).
- **Ganzes Gerüst neu:** ein Knopf „Gerüst neu (mit geänderter Leitidee)".
- **Berührt:** neue Livewire-Actions auf `Index` (z.B. `konzeptFeldSpeichern`, `speiseKommentar`, `regeneriereSpeise`, `regeneriereGeruest`); nutzt `ConceptGeneratorService` (Canvas schreiben + `geruestAusBriefFuerOwner` erneut). Spiegelt die vorhandene Per-Step-`neuGenerieren`-Mechanik (`step-zeile.blade.php:69–71`).

### A3 · Go/Freigabe aus dem inline geprüften Stand
Der Concept-Go referenziert den inline geprüften Plan (`existing_concept_id`, bereits vorhanden) — jetzt ist der Stand aber **sichtbar geprüft**, nicht blind. Der Conceptor bleibt optionaler Tiefen-Editor (KPIs/Score/Kalkulation/Geschirr).

---

## Teil B — Leitplanken-Hygiene (sauber + sichtbar in der Leitstelle)

Dominique: *„die leitplanken sind einfach noch nicht sauber."* Ziel: scope-saubere, gruppierte, redundanzfreie Regler — und in der Leitstelle einsehbar (sie sind Teil des LLM-Kontexts).

- **B1 · Preis-Achsen entwirren.** Heute konkurrieren mehrere Preis-Eingaben. Für Concept ist der **Menü-Preis-Korridor P.P.** (`menue_preis_{min,ziel,max}_pp`) die kanonische Quelle; die rezept-/gericht-Achse `ziel_vk` gehört **nicht** in den Concept-Kontext. → **offene Entscheidung #2 unten.**
- **B2 · Scope-fremde Felder raus.** Was für ein Menü keinen Sinn ergibt, wird im Concept-Tab ausgeblendet (analog zum Basisrezept-Fix: Pax/Ziel-Portion(g) gehört ans Rezept, nicht ans Concept). Grundlage: die scope-aware Blade-Verzweigung existiert schon (`leitplanken.blade.php`, `@if($scope === …)`).
- **B3 · Gruppieren + benennen.** Menü-Achsen (Gänge/Preis-Korridor/Quoten/Balance) als eigener, klar betitelter Block; kein doppeltes Erscheinen derselben Achse.
- **Berührt:** `resources/views/livewire/planung/partials/leitplanken.blade.php` + Abgleich `reglerParams` (`Index.php:1063`) ↔ `PROMPT_KEYS` (`RecipeGenerationContextService.php:20`), damit Sichtbares == Prompt-Wirksames.

---

## Bau-Reihenfolge

**B → A0/A1 → A2 → A3.** Erst die Leitplanken sauber + sichtbar (kleinster Eingriff, sofort spürbar), dann die Semantik/Speisen inline zeigen, dann die Feedback-Schleife, zuletzt der geprüfte Go. Jede Stufe ist eigenständig lauffähig.

---

## Offene Entscheidungen (Sparring)

1. **Regenerate-Granularität** — *entschieden (Dominique, 2026-08-18): **gestuft**.* Zuerst Feld-Kontext-Edit + „ganzes Gerüst neu"; die Per-Speise-Regeneration (A2, Kommentar an einer Speise) kommt als **zweite Stufe**.
2. **Preisquelle Concept** — *entschieden (Dominique, 2026-08-18): **nur Menü-Preis-Korridor P.P.**.* Die rezept-/gericht-Achse `ziel_vk` wird im Concept-Tab hart ausgeblendet — eine Preisachse je Scope, keine konkurrierenden Zahlen im Prompt.
3. **Conceptor-Rolle** — *entschieden (Dominique, 2026-08-18): optionaler Tiefen-Editor, kein Pflicht-Sprung.* Hier nur als festgehaltener Beschluss.

---

## Guardrails

- **Fail-soft (Nordstern):** Fehler (leerer Brief, KI aus, Persistenz kippt) werden **gesagt, nicht geschluckt** — der Absender ist ein Mensch, der korrigiert (wie `kiKopf`/`goKaskade` es schon halten).
- **Multi-Tenancy:** alle Concept-/Canvas-Reads über `visibleToTeam`/`whereKey`+`team_id`; Writes `isOwnedBy` (bestehende Muster in `goKaskade` :1522).
- **MCP-Lockstep:** falls A2 neue Concept-Canvas-Writes bekommt, die MCP-Concept-Tools mitziehen (Reads `visibleToTeam`, Writes `isOwnedBy`).
- **Kein neuer Planer:** die Leitstelle bleibt Motor (Spec 40) — sie zeigt/steuert den Plan, plant aber nicht den Rahmen (der bleibt im Ausgabe-Modul).

## Test-Skizze

- `kiKopf` setzt `planConceptId` **ohne** Wegsprung-Dispatch (assert kein `concepter-editor.oeffnen` mehr im Standardpfad; Plan-Panel gerendert).
- Inline-Feld-Edit schreibt die `concept.plan`-Canvas und ist im nächsten `build()`-Prompt-Kontext sichtbar (assert Feldwert im Prompt-Bündel).
- Per-Speise-Kommentar → nur der eine Slot wird neu erzeugt (assert übrige Slots byte-stabil).
- B: eine scope-fremde Achse (rezept-`ziel_vk`) taucht im Concept-`reglerParams` **nicht** auf.

---

## Changelog

- **2026-08-18** — Spec erstellt (Doc-first). Datenfluss-Prinzip „Ein Ort, ein Kontext" verankert; Teil A (A0–A3) inline-Concept-Ausgabe + Feedback-Schleife; Teil B Leitplanken-Hygiene; 3 offene Entscheidungen (davon #3 entschieden).
