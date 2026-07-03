---
code: foodalchemist.foodbook_anlegen
name: Foodbook komplett anlegen
intent: Führt einen LLM-Client durch die komplette Foodbook-Erstellung — von Food DNA über Rezept-Erdung bis zu Kapiteln, Blöcken und Kalkulation. Alles entsteht als Draft.
trigger_phrases:
  - Foodbook anlegen
  - Foodbook erstellen
  - Speisenangebot zusammenstellen
  - Menükonzept bauen
required_tools:
  - foodalchemist.canvas.GET
  - foodalchemist.settings.GET
  - foodalchemist.knowledge.SEARCH
  - foodalchemist.pairings.GET
  - foodalchemist.verkaufsrezepte.SEARCH
  - foodalchemist.concepts.SEARCH
  - foodalchemist.gps.MATCH
  - foodalchemist.gp_proposals.POST
  - foodalchemist.recipes.POST
  - foodalchemist.recipe_ingredients.PUT
  - foodalchemist.recipes.PUT
  - foodalchemist.concepts.POST
  - foodalchemist.concept_slots.POST
  - foodalchemist.foodbooks.POST
  - foodalchemist.foodbook_kapitel.POST
  - foodalchemist.foodbook_blocks.POST
  - foodalchemist.kalkulation.GET
tier: common
status: active
tags: [foodalchemist, foodbook, rezept, konzept, kalkulation, workflow]
---

# Foodbook komplett anlegen

Du baust ein komplettes Foodbook im Food Alchemist. **Eiserne Regel: Alles, was du anlegst, ist ein ENTWURF (draft)** — die Freigabe (approved/final) macht immer ein Mensch. Du erfindest nie Grundprodukte und rätst nie Aromen-Kombinationen — dafür gibt es Werkzeuge.

## Schritt 1 — Rahmen laden (PFLICHT, vor allem anderen)

1. `foodalchemist.canvas.GET` mit `type=food_dna` → Leitbild, Signature-Stil, Aromatik, **No-Gos** des Mandanten. Jede spätere Entscheidung muss dazu passen. Ist der Canvas leer, frage den User nach den Eckpunkten (oder fülle ihn nach Rücksprache via `canvas.PUT`).
2. `foodalchemist.settings.GET` → Küchen-Typ und Kalkulations-Rahmen.
3. Kläre mit dem User: Anlass, Pax, Zielpreis pro Person, Saison, Diät-Vorgaben.

## Schritt 2 — Bestand prüfen (immer VOR Neuanlage)

1. `foodalchemist.verkaufsrezepte.SEARCH` pro gewünschtem Gericht — vorhandene Verkaufsrezepte haben Vorrang vor Neuanlagen.
2. `foodalchemist.concepts.SEARCH` — vielleicht existiert schon ein passendes Konzept (dann direkt in Schritt 5 als `concept_ref` einhängen).

## Schritt 3 — Fehlende Rezepte anlegen (Draft-Kaskade)

Für jedes Gericht, das es noch nicht gibt:

1. **Wissen zuerst:** `foodalchemist.knowledge.SEARCH` (Mengen-Defaults, Techniken, Substitutionen) + `foodalchemist.pairings.GET` für die Hauptzutat — kombiniere nur belegte Pairings.
2. **Jede Zutat erden:** `foodalchemist.gps.MATCH` pro Zutat. Nur gematchte `gp_id`/`referenced_recipe_id` verwenden.
   - Kein brauchbarer Treffer → `foodalchemist.gp_proposals.POST` (Staging, mit Begründung) und die Zutat ungemappt lassen. NIE einen GP raten.
3. **Anlegen:** `foodalchemist.recipes.POST` mit Zutaten (name, menge, einheit-Slug wie `g`/`kg`/`ml`/`stk`, gp_id XOR referenced_recipe_id). Yield/Allergene/EK rechnet das System.
4. Nachschärfen via `foodalchemist.recipe_ingredients.PUT` (Voll-Sync) und `foodalchemist.recipes.PUT`.
5. Fertige Entwürfe mit `recipes.PUT status=review` zum menschlichen Review einreichen.

## Schritt 4 — Optional: Konzepte schnüren

Wenn Gerichte als Paket auftreten sollen (Menülinie, Flying Buffet):
`foodalchemist.concepts.POST` (mit Zielpreis) → pro Position `foodalchemist.concept_slots.POST` (vk_recipe_id XOR paket_id, rolle, kundenseitiges wording).

## Schritt 5 — Foodbook bauen

1. `foodalchemist.foodbooks.POST` mit `kapitel`-Gerüst (z. B. Empfang / Vorspeisen / Hauptgänge / Desserts).
2. Feinstruktur: `foodalchemist.foodbook_kapitel.POST` (Unterkapitel via parent_id, Fixpreis nur wenn gewünscht — sonst auto).
3. Inhalte: `foodalchemist.foodbook_blocks.POST` pro Position — `type=text` + `vk_recipe_id` für Einzelgerichte, `type=concept_ref` + `concept_id` für Pakete, Header/Spacer für Gliederung. Kundentext im Ton der Food DNA. Pax-abhängige Preise als `staffel`.

## Schritt 6 — Kalkulation gegenprüfen

`foodalchemist.kalkulation.GET` pro Konzept/Rezept → HK/DB gegen den Zielpreis aus Schritt 1. Bei Marge unter Ziel: günstigere Komponenten (Pairing-Alternativen aus Schritt 3.1) vorschlagen, nicht stillschweigend den Preis erhöhen.

## Schritt 7 — Übergabe

Fasse zusammen: Foodbook-ID, Kapitel, Anzahl Blöcke, neu angelegte Rezept-Drafts (mit Review-Status), offene GP-Proposals, Kalkulations-Ampel. Weise darauf hin, dass Review + Freigabe im Editor passieren.

## Anti-Patterns

- GP erfinden oder Zutat frei-texten, obwohl `gps.MATCH` einen Treffer hätte
- Aromen-Kombination ohne `pairings.GET`-Beleg
- Kundentexte gegen die No-Gos der Food DNA
- Status auf approved/aktiv/final setzen (geht via MCP bewusst nicht)
- Foodbook bauen, ohne vorher den Bestand zu durchsuchen (Dubletten)
