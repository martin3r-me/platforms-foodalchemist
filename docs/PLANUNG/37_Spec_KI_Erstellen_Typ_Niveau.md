# Spec 37 — KI-Erstellen schärfen: Typ-Signal, Guardrails, typ-relatives Niveau, Erstellungs-Dossier

> **Tracking:** Office Dev-Package 23, Features-Board (Board 53). Prompt-/Service-Änderung + Vault-Wissensdocs; **kein DB-Schema-Umbau**. Komplementär zu Spec 36 (Matching/Inline-Korrektur/Warte-Zone) — diese Spec = die **Grounding-/Prompt-Seite** des Erstellens.

**Status:** gebaut + getestet 2026-08-07 (mit Dominique geplant, Roadmap zuerst). **Vorgänger:** Kontext-Inspektor (#13), Kohärenz-Gate (#10), `recipe.bauart` (Bauart-Vokabular).

## Anlass

Eingabe „Tomatensuppe" als **Basisrezept**, Niveau-Leitplanke bewusst auf **Haute Cuisine** → dekonstruierter Feindining-Teller („…mit Tomatenwasser, Basilikumöl und Tomaten-Gel", 16 Zutaten), blumige Zutatennamen („Reife aromatische Tomaten"), mehrere davon beim Matching offen (Score 0,50) trotz vorhandener GPs. Der **Kontext-Inspektor** zeigte die Ursache sofort: geladen war `niveau.niveau_1_haute_cuisine` — Marker „**7–10 Komponenten auf dem Teller**".

## Sparring-Kern

„Qualität" hat zwei Wurzeln (Spec 36): **A) Grounding** (welches Wissen die KI liest) vs. **B) Matching**. Dieser Fall ist **Grounding + ein Kreuzeffekt**: dem Generator wird nirgends erklärt, was *Basisrezept* (Baustein) vs. *Gericht* (Teller) bedeutet; die Anti-Über-Elaborations-Leitplanke fehlt; das Niveau wirkt nicht typ-abhängig; und die vom Niveau getriebenen **floriden Namen senken zusätzlich den Match-Score** (A verschlimmert B).

| Frage | Entscheidung |
|---|---|
| Typ-Signal an die KI | im Prompt-Text (aus `recipe.bauart`-Sprache) **und** als eigenes Kontext-Feld `rezept_typ` |
| Anti-Über-Elaboration | Leitplanke in beide Prompts: „Suppe bleibt Suppe; nicht dekonstruieren außer explizit gefordert" |
| Zutatennamen | „nüchtern & matchbar" (reine Ware) — hebt den Match-Score, senkt offene Zeilen |
| Niveau | **typ-relativ**: Basisrezept → 3 neue Komponenten-Niveau-Docs; Gericht → bestehende Teller-Docs; deterministische typ-abhängige Auswahl (kein fuzzy) |
| „Immer geladenes Dossier" | **Beides**: Prompt = knappe invariante Regel; bind_layer = detaillierte, ohne-Deploy-editierbare Basisrezept-Version |

## Bausteine (gebaut)

**A — Typ + Guardrails in beide Generator-Prompts.** `config/foodalchemist.php`, `recipe.generator` + `vk.generator`: Typ-Rahmung (Baustein vs. Teller, Sprache aus `recipe.bauart`), Identitäts-/Anti-Dekonstruktions-Leitplanke, nüchterne matchbare Namen, Niveau typ-relativ. role/fit-Parität im VK-Schema.

**B — `rezept_typ`-Kontextfeld.** `RecipeGenerationContextService::build`: `$prompt['rezept_typ']` aus `$vkModus` (Klartext an die KI) + in die contextFor-Params gereicht (Eingang für den Niveau-Selektor).

**C — 3 Basis-Niveau-Docs + typ-abhängiger Selektor.**
- Vault (Import nach demo): `07_WISSEN/…/Niveau_System/niveau-basis-{1,2,3}-*.md` — komponenten-orientiert (Niveau = Technik/Qualität an EINER Komponente, kein 7–10-Teller). Teller-Docs `niveau-1/2/3-*.md` bleiben für Gerichte.
- `KnowledgeContextService::niveauBlock` (neu): Niveau aus dem generischen discovery-Loop herausgelöst (`niveau` steht jetzt in `$spezial`). Deterministisch: `params['rezept_typ']` wählt die Slug-Familie (Basis → `%basis%`, Gericht → `not %basis%`), der Level die Stufe; top_k=1; `used_by_category['niveau']` bleibt für den Inspektor erhalten. Fehlt der typ-spezifische Doc → null statt falschem Teller-Doc.

**D — Basisrezept-Erstellungs-Dossier (bind_layer).**
- Vault (Import nach demo): `07_WISSEN/…/Workflows/basisrezept-erstellungs-dossier.md` (Kategorie `workflow` — bewusst NICHT discovery-geroutet, lädt nur via Bindung). Detailliert je Komponententyp (Sauce/Fond/Creme/Teig/Beilage/Würzbasis), abgestimmt auf den Prompt (Prompt = invariant, Dossier = vertiefte, editierbare Version).
- Bindung (kein neuer Code): via Wissens-Browser / `KnowledgeBindTool` — `binding_type='layer'`, `target_key='recipe.generator'`, `knowledge_document_id = workflow.basisrezept-erstellungs-dossier`. Cap 2000 Zeichen/Layer → mehrere fokussierte „Versionen" möglich, per `weight` priorisiert.

## Was ist Code (PR) vs. Vault vs. demo-Aktion

- **PR (deploy):** `config/foodalchemist.php` (A), `RecipeGenerationContextService.php` (B), `KnowledgeContextService.php` (C-Selektor), Tests, diese Spec.
- **Vault (importieren):** die 3 Niveau-Basis-Docs (C) + das Dossier (D) — nicht Teil des Repos.
- **demo-Aktion (no-deploy):** `foodalchemist:knowledge-import` (zieht die Vault-Docs) → Dossier an `recipe.generator` binden (Wissens-Browser/`KnowledgeBindTool`).

## Tests

- `KnowledgeContextTest`: Spec-37-Niveau-Selektor (Basis vs. Gericht zieht den richtigen Doc bei gleichem Level-Token; ohne Niveau kein Doc). Zwei bestehende Niveau-Tests auf `rezept_typ=gericht` angepasst (sie seeden Teller-Docs). 18/19 grün — die 1 rote `GT-13-11` (koriander/pairing-grounding) ist **vorbestehend**, fremder Bereich.
- `Spec37TypSignalTest`: `build()` setzt `rezept_typ` typ-abhängig.
- Regression 46/46 (`RecipeGeneratorTest`, `VkGeneratorTest`, `GeneratorKohaerenzGateTest`, `GeneratorOneShotToggleTest`, `PromptRegistryTest`, `KnowledgeRoutingTest`) — kein Test pinnt Prompt-Text, keine neue Regression.

## Verifikation demo (Live-Eval, echte KI)

Nach Deploy + Import + Bind: „Tomatensuppe" als **Basisrezept** + `haute_cuisine` → EINE kohärente Tomaten-Komponente, nüchterne Namen, wenige Zutaten, **kein** Gel/Öl/Wasser; Gegenprobe als **Gericht** → volle Komposition weiter erlaubt. Metrik: Rate offener Zeilen (`statistik['offen']`) vorher/nachher; Kontext-Inspektor muss den **Basis**-Niveau-Doc zeigen.

## Nicht in Scope

Kohärenz-Gate für VK (eigene Runde); Grounding-Kanäle im Inspektor erweitern (GP-Kandidaten/Inventar/bind_layers als eigene Sektion); Matching-Schärfung (Spec 36 P1).
