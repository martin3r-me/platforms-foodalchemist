# Convenience-Highlights — kuratierte GP-Liste als opt-in KI-Baustein

> **Idee (Dominique 2026-07-17):** Eine kuratierte Liste unserer Convenience-„Lieblinge" auf **GP-Ebene**. Der KI eine Aufgabe geben (Gericht / Basisrezept / Konzept) und einen Output bekommen, der neu/kreativ ist, aber **bevorzugt aus dieser Liste** baut — auf Knopfdruck, nicht immer.
> **Abgrenzung:** Kein Ersatz für den semantischen Reuse-Layer (#507). Der zeigt der KI ALLE passenden GPs (Vielfalt); DIESE Liste **verengt** bewusst auf den kuratierten Haus-Convenience-Standard. Zwei gegenläufige Hebel — beide koexistieren.
> **Status:** Spec (Diskussion abgeschlossen). Gebaut wird erst nach Freigabe. Dev-Issue folgt (Board 53).

---

## 1. Zielbild in einem Satz

Eine **global kuratierte, flache Liste von ~200–300 Convenience-GPs** (Haus-Standard), die als **opt-in-Modus** an Rezept- und Konzept-Generator als *bevorzugte* Bausteine einfließt — Default aus (freie Kreativität), an nur wenn der User es für diesen Lauf will.

---

## 2. Festgezurrte Entscheidungen (Dominique 2026-07-16/17)

| # | Entscheidung | Wert |
|---|---|---|
| Inhalt | nur **Convenience**-GPs (`is_convenience=true`), Fertig-/Halbfabrikat | — |
| Struktur | **flach** (keine Pro-Warengruppe-Struktur in der Definition) | ~200–300 |
| Reichweite | **global** (BHG-Master, `team_id NULL`) — v1 keine Caterer-Overrides | — |
| Kuratierung | **hybrid** — Auto-Score schlägt vor, Mensch pinnt/excludet | — |
| Kern-Mechanik | **opt-in-Generierungs-Modus**, Default **AUS** | Toggle |
| Strenge | **bevorzugt**, nicht ausschließlich — KI darf frei ergänzen, wo die Liste nichts hergibt | — |
| Editor | nur ein **kleiner Filter** „Convenience-Highlights" im GP-Picker (kein Chips-Panel) | — |

**Leit-Invariante:** Im Default fließt die Liste **NICHT** in den Generierungs-Prompt → die KI versteift sich nicht. Erst der bewusste Toggle für einen Lauf spielt sie ein (dann ist die Verengung gewollt).

---

## 3. Was wir schon haben (kein Nullstart)

- **`is_convenience`** GP-Tag → das Grund-Set steht fest abgrenzbar.
- **`supplier_priorities`** (Umsatz-Ranking) + Lead-LA je GP → Lieferanten-Priorität für den Auto-Score.
- **Verwendungs-Zähler** über `recipe_ingredients` (D-5) → Nutzungshäufigkeit für den Auto-Score.
- **`GenerationContextService::forGeneration`** (Rezept) + **`ConceptGeneratorService`** (Konzept) → die zwei Andockpunkte für den opt-in-Block.
- GP-Picker im Zutaten-Editor → der Filter-Andockpunkt.

---

## 4. Datenmodell

Migration auf `foodalchemist_gps` (global/Master trägt die Werte):

- `is_convenience_highlight` — boolean, default false. Das Kuratierungs-Flag.
- `highlight_rank` — unsignedInteger nullable. Anzeige-/Vorschlags-Reihenfolge (flach global).

Soft-Regel: `is_convenience_highlight=true` nur sinnvoll bei `is_convenience=true` (im Kuratierungs-Screen erzwungen, kein DB-Constraint). Team-Vererbung/Sync/MCP greifen automatisch, da am GP.

---

## 5. Kuratierung (hybrid)

1. **Auto-Score** je Convenience-GP: `Verwendungshäufigkeit × Lieferanten-Priorität (supplier_priorities via Lead-LA) × Lead-LA-Vollständigkeit`. Liefert eine Rangliste.
2. **Mensch entscheidet:** pinnt aus der Rangliste die echten Highlights (`is_convenience_highlight=true` + `highlight_rank`), excludet Ungewolltes.
3. MVP-Form: Command `foodalchemist:convenience-highlights --suggest` (Report-Rangliste) + Setzen der Flags via Kuratierungs-Screen ODER MCP-Tool. (Voll-Screen kann v2 werden.)

---

## 6. Kern — opt-in-Generierungs-Modus

Neuer optionaler Input an **beiden** Generatoren: `use_convenience_list: bool` (Default false).

- **false (Default):** exakt heutiges Verhalten. Kein Highlight-Block im Prompt. Keine Versteifung.
- **true:** ein **eigener** Kontext-Block „BEVORZUGTE CONVENIENCE-BAUSTEINE (Haus-Standard)" mit den Highlight-GPs (bzw. der zur Aufgabe passenden Teilmenge) + Anweisung: *„Nutze wo möglich diese Produkte; ergänze frei, wo die Liste nichts hergibt (bevorzugt, nicht ausschließlich)."*
  - **Separat** vom semantischen Reuse-Block (#507) — nicht vermischen.
  - „bevorzugt": kein Hard-Stop auf Nicht-Listen-Zutaten; die KI darf Rohware/eigene Komponenten ergänzen (eine reine Fertigprodukt-Liste deckt selten ein ganzes Gericht).

Gilt für: `recipe.generate` (Gericht/Basisrezept) **und** `concepts.generate` (Konzept).

---

## 7. UI

- **Rezept-Generator-Modal + Konzept-Generator-Modal:** eine Checkbox „⭐ Auf Basis meiner Convenience-Liste bauen" (steuert `use_convenience_list`).
- **Zutaten-Editor GP-Picker:** ein kleiner Filter-Toggle „nur Convenience-Highlights" — verengt die Picker-Liste auf die kuratierten GPs. (Kein separates Panel.)

---

## 8. Etappen

| # | Etappe | Größe | Abhängig |
|---|---|---|---|
| **H1** | Datenmodell: Migration `is_convenience_highlight` + `highlight_rank` | S | — |
| **H2** | Kuratierung: `--suggest`-Score-Report + Flags setzen (MCP im Lockstep) | M | H1 |
| **H3** | Generierungs-Modus: `use_convenience_list` in GenerationContextService + ConceptGeneratorService (eigener Prompt-Block, bevorzugt) | M | H1 |
| **H4** | UI: Toggle an beiden Generator-Modals + Editor-Picker-Filter | S–M | H3 |

**Globale DoD:** Default-aus-Verhalten byte-identisch (Regression), Team-Scoping, MCP-Lockstep (Generator-Tools kennen den Flag), Pest, Push + Dev-Issue.

---

## 9. Bewusste Nicht-Ziele (v1)

- **Kein** Prompt-Inject im Default (Leit-Invariante).
- **Kein** „ausschließlich aus der Liste" (bevorzugt statt hart).
- **Keine** Caterer-Overrides (global-only; per-Caterer = v2-Kandidat).
- **Kein** Chips-Panel im Editor (nur Filter).
- **Kein** Swap-am-Ergebnis (generierte Eigen-Komponente → Convenience tauschen) — v2-Kandidat.
- Nicht LA-Ebene — bewusst **GP-Ebene** (Preis/Lead-LA hängt am GP).

---

## 10. Offene Fragen (vor/während H2)

- Auto-Score-Gewichtung (Nutzung vs. Umsatz-Priorität) — am realen Bestand kalibrieren.
- `highlight_rank`: eine flache globale Reihenfolge — reicht das, oder später doch leichte WG-Gruppierung nur für die Anzeige?
- Kuratierungs-Screen jetzt oder erst MCP-Put + später Screen?

*Erstellt 2026-07-17 (Diskussion Dominique). Begleitend zum #507-Retrieval-Layer — komplementärer Hebel.*
