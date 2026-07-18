# Hypothesen- & Widerspruchs-Modus (R&D) — R6.11

> **ROADMAP-Bezug:** R6.11 (Track „Alleinstellung"), Größe M, hängt an Q4 (Evidenz/Wissensbasis) + Pairing-Graph.
> **Idee:** Der Warum-Layer **offensiv** — nicht erklären, was ist, sondern **erforschen, was sein könnte.** R&D-Modus für die Food-Alchemist-DNA: gezielte Experimente statt Zufall; Widersprüche im Wissen als Forschungsfragen sichtbar machen statt still zu übertünchen.
> **Reifegrad: 🟡 entscheidungs-reif** (Code-Kartierung 2026-07-19; ein harter Daten-Blocker + ein feasibility-Cut, s.u. — deshalb noch nicht 🟢). Vorher ⚪ Dossier.

---

## 0. Code-Kartierung (verifiziert 2026-07-19)

**Hypothesen-Seite: die Daten sind stark vorhanden.** Migration `2026_07_11_000010_create_foodalchemist_chemistry_pairing_tables.php` (26 Tabellen):
- `foodalchemist_molecules` (`chem_kingdom/superclass/class/subclass` — ClassyFire-Taxonomie = die „Compound-Klasse"-Achse).
- `foodalchemist_key_components` (`key/family/aroma_type/character` = die **Aroma-Compound-Klassen** im Ahn-Sinn) + `foodalchemist_ingredient_key_component` + `foodalchemist_key_component_molecule`.
- `foodalchemist_ingredient_molecule` (Zutat↔Molekül, Konzentration), `foodalchemist_ingredient_aroma_vector` (14 Aroma-Achsen, **fertiger geteilte-Klasse-Vektor**), `foodalchemist_molecule_type_map` (`provenance`), `foodalchemist_molecule_descriptors` (`citation`), `foodalchemist_pairing_computed` (`confidence`, `evidence_auto`).
- Brücke zu Ankern/GPs: `foodalchemist_anchor_ingredient_map` (anchor↔ingredient) → GP via `gp_anchor_mappings`.
- **Vorhandene Mathematik:** `PairingService::allAnchorAromaVectors()` `:870` + `statePairingNeighbors()` `:913` ranken bereits per Aroma-Vektor-**Cosinus**. → **GAP:** diskretes „geteilte `key_component`/`chem_class`"-Zählen gibt es NICHT (Daten da, Code fehlt) — ein `sharedCompoundClasses(anchorA,anchorB)` ist neu.
- ⚠️ **HARTER BLOCKER:** die Chem-Tabellen haben **keine FKs** und werden nur per `foodalchemist:import-master` befüllt (Migration-Header). **Vor Baustart verifizieren, dass der Import lief** (sonst ranken die Queries auf Leer).

**Widerspruchs-Seite: strukturell dünn (ehrlicher Befund).**
- Docs in `foodalchemist_knowledge_documents` (`category ∈ cross_cutting|domain|pairing|regelwerk_snippet`, `content_md`). `KnowledgeContextService::extractPairingNames($md)` `:175` parst eine **`## Pairings`-Markdown-Sektion** (Wikilinks/`**bold**` → Partner-**Namen**).
- **Deterministisch vergleichbar nur für `pairing`-Docs** als **Präsenz/Absenz-Set-Diff** gegen `pairing_anchor_edges` („Doc listet Partner, Graph hat keine Kante" bzw. umgekehrt). **`domain`-Docs sind reine Prosa** → echte semantische Widersprüche („Doc sagt Clash, Graph sagt Paar") sind NICHT modelliert (weder Doc-Parse noch Edge-Modell tragen negative Assertions).

**Evidenz-Tier (Q4):** T0/T3 ist **nirgends modelliert**. Vorhanden: edge-`evidence` (Prosa), `confidence` (`hoch|mittel|niedrig`), `molecule_type_map.provenance`, `pairing_computed.evidence_auto`, `molecule_descriptors.citation`. → Tier per Konvention aus diesen ableiten, keine neue Spalte in v1.

**Ziel-Senken + Infra (bereit):** `RecipesPostTool`/`RecipeService::create` (Draft); `SignalService::erzeuge` + `SignalTyp`-Enum (neue Case + Detektor-Methode + `laufen()`-Wiring, Muster `veraltetePreise`); `AiGatewayService::propose` + Prompt-Registry (Vorlage `recipe.pairing` `:487`, `callWithTools` `:204` für Tool-Loop); read-only-Tool-Muster `PairingsSuggestTool`.

**Lab-Journal:** **kein FA-seitiges Modell/Tabelle** (grep leer) — „03.05 Lab Journal" ist eine **Vault**-Location. Headless-Vault-Write existiert nicht → der DoD-Zweig „Lab-Journal-Eintrag" ist FA-seitig nur mit **neuer Mini-Tabelle** erfüllbar (s. E4).

---

## 1. Festgezurrte Entscheidungen (2026-07-19)

| # | Frage | Entscheid | Begründung |
|---|---|---|---|
| E1 | Evidenz-Tier | **Konvention, keine Spalte:** `T0` = kuratiert/Buch/`citation`; `T1` = ki-abgeleitet (`ai_confidence`/`evidence_auto`); `T3` = Hypothese (Modus-Output). Tier lebt im Proposal-`werte`/Signal-`payload`. | Kein Tier-Schema vorhanden; Q4-unified-Tier ist eigener Track. Bestehende Provenienz reicht für Hypothese-vs-Fakt. |
| E2 | Hypothesen-Ranking | **Primär `sharedCompoundClasses` (neu, über `ingredient_key_component`/`molecule.chem_class`), Fallback Aroma-Vektor-Cosinus (vorhanden).** Mechanismus-Text = geteilte Klassen benennen. | Nutzt die stärkste vorhandene Datenbasis; graceful, wenn Chem-Tabellen (noch) dünn. |
| E3 | Widerspruchs-Detektor Scope | **v1 NUR `pairing`-Doc ⇄ Edge Präsenz/Absenz-Set-Diff.** Domain-Prosa-Widersprüche (semantisch) = **v2** (braucht LLM-Extraktions-Pass zu strukturierten Claims). | Ehrlicher feasibility-Cut: nur das ist deterministisch belastbar; alles andere wäre Raten. |
| E4 | Lab-Journal-Output | **v1: `recipes.POST`-Draft (vorhanden) + R&D-Signal (neu).** „Lab-Journal-Eintrag" = **neue Mini-Tabelle `foodalchemist_lab_notes`** (S3, klein) statt Vault-Write. | Vault-Write headless nicht verfügbar; eine schlanke FA-Notiz-Tabelle macht den Zweig ehrlich erfüllbar. |
| E5 | Vorbedingung | **Import-Master-Lauf verifizieren** (Chem-Tabellen befüllt) BEVOR S1 startet. | Ohne Daten ranken die Queries leer — kein Scheinbau. |

## 2. Etappen

| # | Etappe | Größe | Inhalt |
|---|---|---|---|
| **S1** | Hypothesen-Modus | M | `PairingService::sharedCompoundClasses(a,b)` (neu) + `hypothesizeFor(gp/anchor, limit)` → Kandidaten nach geteilten Volatil-Klassen, je mit Mechanismus + Evidenz-Tier (E1/E2); optional KI-Narrativ via Prompt `knowledge.hypothesize`; MCP `knowledge.HYPOTHESIZE` (read-only). |
| **S2** | Widerspruchs-Detektor | M | Neuer `WissensWiderspruchDetektor`: `pairing`-Doc-Namen (`extractPairingNames`) vs. `pairing_anchor_edges` Set-Diff → neue `SignalTyp::WiderspruchWissenGraph`, `payload` = {doc_slug, edge, beide Assertions, tier}, `ref_type='knowledge_document'`; in `SignalDetektorService::laufen()` verdrahtet (Research-Queue = Q4-Rückspeisung). |
| **S3** | Output-Senken | S | 1-Klick → `recipes.POST`-Draft; + neue Tabelle `foodalchemist_lab_notes` (team-scoped, `title/body/evidence_tier/source_ref`) als FA-Lab-Journal-Zweig. |

## 3. DoD

- [ ] Hypothesen-Modus: „paare X ungewöhnlich" → Kandidaten nach geteilten Volatil-Klassen gerankt, mit Mechanismus + Evidenz-Stufe (E1/E2).
- [ ] Widerspruchs-Detektor: `pairing`-Doc ⇄ Graph-Kante Präsenz/Absenz → R&D-Frage als Signal (nicht still auflösen) + Research-Queue (E3); Domain-Prosa explizit als v2 markiert.
- [ ] Ergebnis **immer mit Evidenz-Stufe**; T3/T0 klar als Hypothese, nie Fakt.
- [ ] Vorschlag → 1 Klick → Draft-Rezept (`recipes.POST`) oder Lab-Notiz (`foodalchemist_lab_notes`, E4).
- [ ] MCP `knowledge.HYPOTHESIZE`, read-only bis Draft.
- [ ] Test: bekannter strittiger `pairing`-Doc-Fall wird als offene Frage geflaggt, nicht willkürlich entschieden; Hypothesen-Ranking auf Fixture reproduzierbar; graceful bei leeren Chem-Tabellen.
- [ ] **Vorbedingung dokumentiert:** Chem-Tabellen befüllt (Import-Master, E5).

## 4. Reuse-vs-Neu

| Reuse (vorhanden) | Neu bauen |
|---|---|
| Chem-Tabellen + `ingredient_aroma_vector` + `allAnchorAromaVectors`/`statePairingNeighbors`; `extractPairingNames`; `SignalService`+Detektor-Muster; `AiGatewayService`+`recipe.pairing`-Prompt; `RecipesPostTool`; read-only-Tool-Muster | `sharedCompoundClasses`+`hypothesizeFor`, `knowledge.hypothesize`-Prompt, `KnowledgeHypothesizeTool`, `WissensWiderspruchDetektor`, `SignalTyp::WiderspruchWissenGraph`, `foodalchemist_lab_notes`-Tabelle |

## 5. Abhängigkeiten + Einordnung
- **Q4 (Evidenz-Abdeckung)** ist die harte Voraussetzung für ehrlichen Hypothese/Fakt-Unterschied; der Widerspruchs-Detektor **speist Q4 zurück**.
- **Import-Master-Lauf** = Vorbedingung für die Chem-Rankings (E5).
- Schwester zu [08](08_Planungs_und_Kreativ_Ebene.md): dort Erfindung im Planungs-Skizzenraum, hier im Wissens-/R&D-Raum. „Keine Erfindungen" gilt weiter für **Fakten** — der Modus erfindet **markierte Hypothesen**.

## 6. Bewusste Nicht-Ziele
- Keine als Fakt getarnte Spekulation — Evidenz-Stufe Pflicht.
- Kein stilles Auflösen von Widersprüchen.
- Kein Domain-Prosa-Semantik-Diff in v1 (E3, → v2).
- Kein Auto-Persist — Draft/Lab-Notiz bis zur menschlichen Übernahme.

*Verzahnt: Q4 (Warum-Layer, wechselseitig), Pairing-Graph/Station 0-2 (FooDB/Ahn), [08](08_Planungs_und_Kreativ_Ebene.md), Lab Journal (03.05 Vault → FA `lab_notes`). Dossier 2026-07-18, auf entscheidungs-reif gehoben 2026-07-19.*
