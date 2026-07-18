# LA-First-GP-Mint als geteilte Fähigkeit — überall verdrahten (+ Proposal-Reframe)

> **Anlass (Dominique 2026-07-18):** Beim Rezept-Anlegen fehlte ein GP (Ruby-Schokolade). Der Office-/MCP-Assistent landete in einer Sackgasse („Proposal #76 hängt im Staging, kein Tool zum Committen") und verwies auf manuelle Kuration. Falsche Ausfahrt.
> **Doktrin (Dominique, verbindlich):** Ein GP **darf nicht ohne Lieferantenartikel (LA) entstehen** — hart. Der **GP-Name kommt aus der LA**. Ein GP aus einer realen LA zu minten ist deshalb **kein** „autonomer Commit aus dem Nichts", sondern die **sanktionierte LA-First-Entstehung** — die darf (und soll) automatisch laufen.
> **Ersetzt:** die frühere (verworfene) Idee „`gp_proposals.APPROVE` / Staging-Proposals zu GPs promoten" und „tentative GP ohne LA". Beides war falsch.

---

## 1. Der korrekte Flow (Soll)

```
Rezept braucht Zutat → GP existiert?
  ├─ ja → nutzen
  └─ nein → passende LA suchen
        ├─ LA gefunden → GP AUS DER LA minten (Name aus LA, tentative, requires_la erfüllt,
        │                LA-verknüpft → Allergene/Nährwerte/EK LA-abgeleitet) → nutzen
        │                → landet in der ReviewQueue (Mensch bestätigt später „approved")
        └─ keine LA → KEIN GP. Stattdessen Sourcing-Wunsch erfassen
                       („Ruby-Schokolade als Artikel beschaffen/anlegen") — Auftrag an Einkauf/WaWi
```

Kernpunkt: **Der einzige echte Dead-End ist „keine LA" — und der ist richtig.** Dann fehlt schlicht Stammdaten, kein Kurations-Klick. Die ehrliche Antwort ist „Artikel beschaffen", nicht „Proposal kuratieren".

---

## 2. Ist-Stand (code-verifiziert 2026-07-18)

Die LA-First-Mint-Logik **existiert bereits** — als `versucheLaZuGp()` (#505 Slice 2), aber:

- **`private` in `RecipeGeneratorService`** (einzige Aufrufstelle Z. 195). Erzeugt einen `tentative` GP aus einer gematchten LA, LA-verknüpft.
- **Sie fehlt überall sonst:**
  - `RecipeService::syncIngredients` (Editor/Revise/E3-Grounding) — matcht nur **bestehende** GPs, mintet nicht.
  - `IngredientMatchService` / `gps.MATCH` — kein Mint.
  - **Alle MCP-Tools** — es gibt `ArtikelSearchTool` (LA-Suche) + `GpProposalsPostTool` (Staging), aber **keinen** LA→GP-Mint dazwischen.
- `GpProposalService` kann nur `propose()` (Staging anlegen) + `open()` (listen) — **keine** Promotion, an keinem Screen verdrahtet. → Sackgasse.

**Deshalb Ruby:** der Office-/MCP-Pfad hat nur Artikel-Suche + Staging-Proposal, aber nicht den Mint, den der Generator intern kann. Nicht eine Leitplanke hat blockiert — die richtige Logik war in dem Pfad nicht verfügbar.

---

## 3. Der Gap + Fix

**Fix:** `versucheLaZuGp` aus dem Generator **in einen geteilten Service befreien** (z.B. `LaFirstGpService` oder als öffentliche Methode an `GpService`) und **überall verdrahten:**

1. **`syncIngredients`** — Zutat ohne GP, aber mit passender LA → LA-First-Mint (schließt die Lücke in der E3-Revise-Strecke: die matcht heute nur, mintet nicht).
2. **`gps.MATCH` / IngredientMatchService** — „mint-if-missing"-Option (LA-belegt).
3. **Neues MCP-Tool** (z.B. `gps.MINT_FROM_LA` oder Mint-Flag an einem bestehenden Tool) — damit der Office-Assistent den Ruby-Fall selbst lösen kann, statt in Staging zu laufen.

**Proposal-Reframe:** ein Staging-Proposal heißt künftig **„keine passende LA gefunden → Beschaffungs-Wunsch"** (Sourcing-Backlog), nicht „GP wartet auf Freigabe". GPs entstehen NUR über den LA-First-Mint, nie durch Promotion eines unbelegten Proposals.

**Doktrin bleibt gewahrt:**
- Kein GP ohne LA (der Mint braucht immer eine reale LA).
- Kein „KI committet einen freien GP" — der Mint ist LA-abgeleitet + `tentative` + ReviewQueue-pflichtig (Mensch gibt final frei).
- „propose, never autonomously commit" gilt weiter für unbelegte GPs; ein LA-belegter Mint fällt NICHT darunter.

---

## 4. Etappen

| # | Etappe | Größe |
|---|---|---|
| **M1** | `versucheLaZuGp` extrahieren → geteilter Service (aus Generator; Generator ruft künftig den Service) | S–M |
| **M2** | In `syncIngredients` verdrahten (Editor/Revise/One-Shot minten LA-First) + Pest | M |
| **M3** | MCP: LA→GP-Mint-Tool (menschlich getriggert / KI-vorbereitet) + `gps.MATCH` mint-if-missing; MCP-Lockstep | M |
| **M4** | Proposal-Reframe: `GpProposalService`/Staging = Sourcing-Wunsch-Liste (Semantik + ggf. Screen/Signal „LA beschaffen") | S–M |

**Globale DoD:** GP nie ohne LA; jeder Mint `tentative` + ReviewQueue; Name aus LA (Necta-/§6-Naming); Tenancy; Pest inkl. „keine LA → kein GP, sondern Sourcing-Wunsch".

---

## 5. Bewusste Nicht-Ziele

- **Kein** GP ohne LA (Doktrin).
- **Kein** Committen/Promoten unbelegter Staging-Proposals zu GPs.
- **Kein** Auto-„approved" — LA-First-Mint bleibt `tentative` bis zur menschlichen Freigabe.
- Kein Dupl-Mint — Dedup wie in #505 Slice 2.

---

## 6. Verzahnung

- **#505** (Generator-Grounding, Geburtsort von `versucheLaZuGp`) — dies hebt die Fähigkeit auf System-Ebene.
- **#508 / E3** (Revise-Grounding) — meine E3-Strecke matcht nur; M2 ergänzt den Mint dort.
- **#507** (semantischer Layer) — bessere LA-/GP-Kandidaten fürs Matching, orthogonal.
- **[03_KI_Erstell_Flaechen_512.md](03_KI_Erstell_Flaechen_512.md)** L7 (One-Shot) — braucht den überall-verfügbaren Mint, damit die Kaskade nicht auf GP-Lücken dead-endet.
- Regelwerk: `LA-First_Workflow.md` + `Regelwerk_Grundprodukte` (§6 Naming aus LA) + `Regelwerk_Lieferantenartikel`.

*Erstellt 2026-07-18. Motivierender Fall: Ruby-Schokolade (Proposal #76, kein LA → korrekt kein GP → Sourcing-Wunsch, nicht Kuration). Dev-Issue-Kandidat (Board 53).*
