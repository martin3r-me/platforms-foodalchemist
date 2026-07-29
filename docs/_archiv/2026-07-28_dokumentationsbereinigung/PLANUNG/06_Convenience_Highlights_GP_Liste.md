# Favoriten (Lieblings-GPs) — kuratierte GP-Liste als opt-in KI-Baustein

> **Idee (Dominique 2026-07-17):** Eine kuratierte Liste unserer Lieblings-GPs auf **GP-Ebene**. Der KI eine Aufgabe geben (Gericht / Basisrezept / Konzept) und einen Output bekommen, der neu/kreativ ist, aber **bevorzugt aus dieser Liste** baut — auf Knopfdruck, nicht immer.
> **Verallgemeinerung 2026-07-20 (Dominique):** Der Pool war zuerst auf Convenience-getaggte GPs beschränkt (§4). Jetzt ist es eine **allgemeine Favoriten-Liste** — jeder approved GP ist pinbar (der Use-Case bleibt Convenience, aber man hat auch andere Lieblings-Grundprodukte). Convenience bleibt der Property-Tag `tag_is_convenience`; der Generator kann optional darauf verengen (Favoriten ∩ Convenience).
> **Abgrenzung:** Kein Ersatz für den semantischen Reuse-Layer (#507). Der zeigt der KI ALLE passenden GPs (Vielfalt); DIESE Liste **verengt** bewusst auf den kuratierten Haus-Standard. Zwei gegenläufige Hebel — beide koexistieren.
> **Status: ✅ KOMPLETT GEBAUT 2026-07-19 (H1–H4), verallgemeinert 2026-07-20 (H4b).** `FavoriteGpService` + Migration (`is_favorite`+`favorite_rank`) + Kuratierungs-Screen `/favoriten` (Alt-Pfad `/convenience-highlights` → Redirect) + Command `foodalchemist:favorites` + 2 MCP-Tools (`favorites.GET/PUT`) + opt-in `use_favorites_list` (+ `favorites_convenience_only`) an Rezept-/VK-/Konzept-Generator + GP-Picker-Filter + Pin direkt im GP-Editor. Pest grün, Voll-Suite grün. Default-aus-Leit-Invariante regressions-getestet. ROADMAP R8.2. (Etappen-Häkchen unten in §8.)

---

## 1. Zielbild in einem Satz

Eine **global kuratierte, flache Liste von ~200–300 Convenience-GPs** (Haus-Standard), die als **opt-in-Modus** an Rezept- und Konzept-Generator als *bevorzugte* Bausteine einfließt — Default aus (freie Kreativität), an nur wenn der User es für diesen Lauf will.

---

## 2. Festgezurrte Entscheidungen (Dominique 2026-07-16/17)

| # | Entscheidung | Wert |
|---|---|---|
| Inhalt | ~~nur **Convenience**-GPs~~ → **jeder approved GP** (Favoriten/Lieblings-GPs); Convenience = optionaler Tag-Filter (rev. 2026-07-20) | — |
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

- `is_favorite` — boolean, default false. Das Kuratierungs-Flag.
- `favorite_rank` — unsignedInteger nullable. Anzeige-/Vorschlags-Reihenfolge (flach global).

~~Soft-Regel §4: `is_favorite=true` nur bei `is_convenience=true`.~~ **Fallengelassen 2026-07-20:** jeder approved GP ist favoritisierbar. Der Auto-Score-Pool ist jetzt der ganze approved-Bestand (nicht mehr `tag_is_convenience`-gefiltert); gepinnte Favoriten bleiben trotz Score-Cap immer in der Liste. Team-Vererbung/Sync/MCP greifen automatisch, da am GP.

---

## 5. Kuratierung (hybrid)

1. **Auto-Score** je Convenience-GP: `Verwendungshäufigkeit × Lieferanten-Priorität (supplier_priorities via Lead-LA) × Lead-LA-Vollständigkeit`. Liefert eine Rangliste.
2. **Mensch entscheidet:** pinnt aus der Rangliste die echten Highlights (`is_favorite=true` + `favorite_rank`), excludet Ungewolltes.
3. **✅ ENTSCHIEDEN (Dominique 2026-07-18): Kuratierungs-Screen gleich in v1** — FA-Screen mit Score-Rangliste + Pin/Exclude je GP (+ `favorite_rank`-Reihenfolge), dazu Command `foodalchemist:convenience-highlights --suggest` (Report) und MCP-Tool als zweiter Zugang (MCP-Pflicht-Invariante).

---

## 6. Kern — opt-in-Generierungs-Modus

Neuer optionaler Input an **beiden** Generatoren: `use_favorites_list: bool` (Default false).

- **false (Default):** exakt heutiges Verhalten. Kein Highlight-Block im Prompt. Keine Versteifung.
- **true:** ein **eigener** Kontext-Block „BEVORZUGTE CONVENIENCE-BAUSTEINE (Haus-Standard)" mit den Highlight-GPs (bzw. der zur Aufgabe passenden Teilmenge) + Anweisung: *„Nutze wo möglich diese Produkte; ergänze frei, wo die Liste nichts hergibt (bevorzugt, nicht ausschließlich)."*
  - **Separat** vom semantischen Reuse-Block (#507) — nicht vermischen.
  - „bevorzugt": kein Hard-Stop auf Nicht-Listen-Zutaten; die KI darf Rohware/eigene Komponenten ergänzen (eine reine Fertigprodukt-Liste deckt selten ein ganzes Gericht).

Gilt für: `recipe.generate` (Gericht/Basisrezept) **und** `concepts.generate` (Konzept).

---

## 7. UI

- **Rezept-Generator-Modal + Konzept-Generator-Modal:** eine Checkbox „⭐ Auf Basis meiner Convenience-Liste bauen" (steuert `use_favorites_list`).
- **Zutaten-Editor GP-Picker:** ein kleiner Filter-Toggle „nur Convenience-Highlights" — verengt die Picker-Liste auf die kuratierten GPs. (Kein separates Panel.)
- **H4b (2026-07-20, Dominique) — zweiter Pin-Andockpunkt + Rück-Navigation:**
  - **GP-Editor (GpModal), Tab „Eigenschaften":** unter den Eigenschafts-Tags ein ⭐-Toggle „in Liste pinnen / aus Liste nehmen". `GpModal::favoriteToggle` → derselbe `FavoriteGpService` wie der Screen (gleiches Feld, gleiche Soft-Regel §4 + D1-Gate). Disabled + Hinweis, wenn der GP nicht als Convenience getaggt ist; read-only-★ für geerbte Katalog-GPs.
  - **Convenience-Screen:** GP-Name ist jetzt ein Link (`?gp=<id>&edit=1`, `wire:navigate`). Browser-Deeplink `editOeffnen` öffnet beim Ankommen den GP-Editor und putzt `edit=1` aus der URL. → „aus der Liste in den Artikel klicken".

---

## 8. Etappen

| # | Etappe | Größe | Abhängig | Status |
|---|---|---|---|---|
| **H1** | Datenmodell: Migration `is_favorite` + `favorite_rank` | S | — | ✅ 2026-07-19 |
| **H2** | Kuratierung: `--suggest`-Score-Report + **Kuratierungs-Screen (v1)** + MCP-Tools (Lockstep) | M–L | H1 | ✅ 2026-07-19 |
| **H3** | Generierungs-Modus: `use_favorites_list` in GenerationContextService + ConceptGeneratorService (eigener Prompt-Block, bevorzugt) | M | H1 | ✅ 2026-07-19 |
| **H4** | UI: Toggle an Rezept-/VK-/Konzept-Generator + Editor-Picker-Filter | S–M | H3 | ✅ 2026-07-19 |
| **H4b** | Pin direkt im GP-Editor (`favoriteToggle`) + GP-Name im Screen als Editor-Deeplink (`?gp=&edit=1`) | S | H1 | ✅ 2026-07-20 |
| **H4b·2** | Verallgemeinerung Convenience→Favoriten: §4 fällt (jeder GP pinbar), Rename (Feld/Screen/Route/Command/MCP/Service), Convenience-nur-Filter im Generator (`favorites_convenience_only`) | M | H4 | ✅ 2026-07-20 |

**Globale DoD:** Default-aus-Verhalten byte-identisch (Regression), Team-Scoping, MCP-Lockstep (Generator-Tools kennen den Flag), Pest, Push + Dev-Issue.

---

## 9. Bewusste Nicht-Ziele (v1)

- **Kein** Prompt-Inject im Default (Leit-Invariante).
- **Kein** „ausschließlich aus der Liste" (bevorzugt statt hart).
- **Keine** Caterer-Overrides (global-only; per-Caterer = v2-Kandidat).
- **Kein** Chips-Panel im Editor (nur Filter).
- **Kein** Swap-am-Ergebnis (generierte Eigen-Komponente → Favorit tauschen) — v2-Kandidat.
- Nicht LA-Ebene — bewusst **GP-Ebene** (Preis/Lead-LA hängt am GP).
- **Keine** Pin-Kategorien/Gründe (Convenience/Signature/Standard) — v2-Kandidat; v1 ist eine flache Favoriten-Liste, Convenience nur via vorhandenen Tag ableitbar.

---

## 10. Offene Fragen (vor/während H2)

- Auto-Score-Gewichtung (Nutzung vs. Umsatz-Priorität) — am realen Bestand kalibrieren.
- `favorite_rank`: eine flache globale Reihenfolge — reicht das, oder später doch leichte WG-Gruppierung nur für die Anzeige?
- ~~Kuratierungs-Screen jetzt oder erst MCP-Put?~~ ✅ entschieden 2026-07-18: Screen gleich in v1 (s. §5).

*Erstellt 2026-07-17 (Diskussion Dominique). Begleitend zum #507-Retrieval-Layer — komplementärer Hebel.*
