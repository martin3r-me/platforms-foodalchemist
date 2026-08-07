# Spec 36 — Rezeptqualität festigen: Diagnose, Matching-Schärfung, Inline-Korrektur, Warte-Zone

> **Tracking:** Office Dev-Package 23, Features-Board (Board 53). **Kein DB-Schema-Umbau geplant** —
> Matching + Korrektur nutzen bestehende Tabellen; die Warte-Zone ist ein UI-Zustand über `recipes.status`.

**Status:** geplant mit Dominique am 2026-08-07 · Roadmap zuerst festgeschrieben, dann phasenweise Bau.
**Vorgänger:** Kohärenz-Gate (#10, `9a403d3`), Kontext-Inspektor (#13, `a55a48b`), LA-Finder (Spec 16),
Regelwerk_Lieferantenartikel (§3 Match-Hierarchie, §5 Auto-Match-Schwellen, §6 Disambiguierung),
Skripte 216/217 (LA-Kandidaten-Matching + Lieferanten-Regel-Matrix).

## Anlass

Dominique zu zwei realen Generator-Läufen: „in Ordnung, aber nicht meinem Anspruch". Die Screenshots
(Tomaten-Espuma, Tomatensuppe) zeigen das Loch konkret: **„Reife Tomaten → Tomatenpaprika Streifen"**,
**„Olivenöl → Aioli aus geräuchertem Olivenöl"**, **„Gemüsefond hell → Fond: Heller Gemüsefond für
Risotto" (0,57)**. Das sind **Fehl-Matches**, keine Fehl-Rezepte.

## Sparring-Kern: „Qualität" hat ZWEI getrennte Wurzeln

| | Wurzel | Wo im Code | Werkzeug / Symptom |
|---|---|---|---|
| **A** | **Grounding** — welches *Wissen* die KI beim Schreiben liest | `KnowledgeContextService::contextFor` | **Kontext-Inspektor** (#13) macht es sichtbar |
| **B** | **Matching** — welche *GP/LA* an die KI-Zutat verdrahtet werden | `IngredientMatchService` (GL-04) | die Fehl-Matches oben |

Die Screenshots zeigen **B**. Der **Kohärenz-Gate (#10) fängt B NICHT** — er prüft süß×herzhaft +
thematische Fremdkörper unter verdrahteten Sub-Rezepten, nicht „falsche Rohware für eine GP-Zeile".
Reihenfolge der Arbeit muss dieser Trennung folgen: erst mit dem Inspektor **diagnostizieren** (A oder B?),
dann gezielt schärfen.

| Frage | Entscheidung |
|---|---|
| Wo korrigiert der Nutzer ein Fehl-Match? | **Inline in der Ergebnis-/Preview-Fläche**, nicht (nur) im Voll-Editor |
| Anreicherungs-Reihenfolge | **recipe-first bleibt**: Basis → Review/Korrektur (Warte-Zone) → *dann* Anreicherung/Freigabe |
| „Tomaten → Tomatenpaprika" reparieren durch | **Kandidaten-Rangfolge + Anti-Marker** im Matcher — NICHT den Kohärenz-Gate aufbohren |
| Warte-Zone | eigener Review-Zustand für generierte Entwürfe **vor** Freigabe; bestehende `review-queue`-Komponente prüfen/wiederverwenden |
| Datenmodell | keine Migration vorgesehen; Korrektur nutzt `HardstopResolveService::binde/entdrahte` (existieren) |

---

## Phase 0 — Diagnose mit dem Kontext-Inspektor *(Werkzeug steht, #13)*

Kein Code. Reale Fälle (Tomatensuppe, Espuma) generieren, „🧠 Verwendetes Wissen" aufklappen:
- **Zieht der Generator die richtige Domäne** (Gemüse/Suppe), Niveau, die passenden Pairing-Anker?
  Fehlt/falsch → **Grounding-Lücke (A)** → Routing/Docs/Alias nachziehen (runtime via
  `knowledge_routings.PUT`, kein Deploy).
- Ist das Grounding ok, aber das Match trotzdem falsch → **Matching (B)** → Phase 1.

**Ergebnis:** priorisiert P1 vs. eine Grounding-Nachbesserung. **DoD:** für ≥3 reale Fälle notiert, ob A oder B die Ursache ist.

## Phase 1 — Matching-Qualität schärfen (Wurzel B) *(größter Qualitäts-Hebel)*

`IngredientMatchService` (GL-04: Exact ≥0.85 / FuzzyHigh ≥0.70 verdrahtet, FuzzyLow → offen).
Hypothese aus den Screenshots: der **Kandidaten-Satz** ist schuld, nicht die Schwelle — „Tomaten"
findet „Tomatenpaprika Streifen" mit hohem Score, weil die Rohware-GP nicht bevorzugt wird.

1. **Kandidaten-Rangfolge:** Präfix-/Kurzbezeichnungs-Bias = Rohware bevorzugen (Logik aus Skript 216/217:
   kurze, präfix-gleiche Bezeichnung schlägt Verarbeitungs-/Mischprodukt). „Tomaten" → „Tomaten: frisch"
   vor „Tomatenpaprika …".
2. **Anti-Marker live nutzen:** die 11 (2026-08-07) angelegten Marker greifen im Matcher-Skip
   (`stripAntiMarkers` :279, Scan-Skip :457/:493). Prüfen, ob relevante Fehl-Matches durch neue Marker
   deterministisch fallen (z. B. `öl ↛ aioli`?) — Kandidaten für die Negativliste sammeln.
3. **Schwellen prüfen, aber zurückhaltend:** ein 0,55-„Meintest du Olivenoel: fluessig" ist korrekt FuzzyLow
   (bleibt offen). Das Problem ist der HOCH gescorte falsche Treffer → Ursache im Kandidaten-Set (1.), nicht
   in der Schwelle. Schwellen nur justieren, wenn Golden-Fälle es belegen.

**DoD:** Golden-Fälle (Tomate, Olivenöl, Gemüsefond) matchen korrekt **oder** bleiben sauber offen (kein
falsch-Verdrahten); Regressionstest gegen die drei Fälle; volle Suite grün.

## Phase 2 — Inline-Korrektur in der Preview (Wurzel B, UX) *(dein „korrigieren statt Editor")*

Heute können nur die **offenen** Hard-Stop-Zeilen im Ergebnis-Modal aktioniert werden
(`hardstop-zeilen.blade.php`: „Meintest du?" / „LA wählen" / „Doch Basisrezept"). Diese Spec gibt den
**schon verdrahteten** Zeilen dieselben Aktionen:
- Pro verdrahteter Zeile ein „ändern"-Einstieg → dieselbe Kandidaten-Liste wie beim Hard-Stop → Umhängen
  auf anderen GP/LA via `HardstopResolveService::binde()` (existiert), bzw. lösen via `entdrahte()`.
- Menge/Einheit direkt editierbar.
- Nach jeder Änderung **Recompute** (Yield/Allergene/EK) wie bei den Hard-Stops.
- Baut auf der bestehenden Mechanik auf (kein neuer Resolver, keine Migration).

**DoD:** „Tomaten → Tomatenpaprika" ist direkt im Ergebnis-Modal auf „Tomaten: frisch" umhängbar, ohne den
Voll-Editor zu öffnen; Recompute + Save-Toast laufen; Livewire-Render-Test.

## Phase 3 — Warte-Zone / Review-Queue (Workflow-Rahmen)

Recipe-first zu Ende gedacht (`HatGeneratorLauf::$vollAnreichern` default AUS): generierte Entwürfe
(`status=draft`, ohne Anreicherung) sammeln sich in einer **Review-Zone**. Von dort: Inline-Korrektur (P2),
Kontext-Inspektor (#13), dann bewusste **Freigabe → Anreicherung**.
- **Zuerst prüfen:** `resources/views/livewire/review-queue.blade.php` existiert bereits — erweitern statt neu.
- Filter „wartet auf Review" (generiert, noch nicht freigegeben/angereichert).

**DoD:** frisch generierte Rezepte erscheinen in der Warte-Zone; von dort korrigier- und freigebbar; die
Freigabe stößt die Anreicherung an.

---

## Abhängigkeiten & Reihenfolge

```
P0 Diagnose (Inspektor, steht)
   ├─→ P1 Matching-Schärfung (B)        ── parallel baubar
   └─→ P2 Inline-Korrektur (Preview) ──→ P3 Warte-Zone (P2 ist ihr Inhalt)
```

Empfehlung: **P0 sofort** (Inspektor auf Tomatensuppe) → **P2 zuerst bauen** (sofortiger Nutzen + genau
„korrigieren statt Editor"), **P1 parallel** (tiefster Hebel), **P3** als Rahmen zuletzt.

## Offene Fragen (vor Bau je Phase klären)

- **P1:** Fehlt schlicht der Rohware-GP mit LA (dann nimmt der Matcher den nächstbesten) **oder** ist es reine
  Rangfolge? Ein Matcher-Trace auf „Tomaten"/„Olivenöl" auf demo klärt das vor dem Code.
- **P2/P3:** Nutzt die Warte-Zone die bestehende `review-queue`-Komponente, oder braucht das Ergebnis-Modal
  einen eigenen Korrektur-Modus? (Design-Entscheid nach P0.)
- **Scope:** gilt alles für **Basisrezept und VK** (beide über `HatGeneratorLauf`), oder erst Basis?

## Nicht in Scope

- Kohärenz-Gate für VK (eigene Runde, unabhängig).
- Grounding-Kanäle im Inspektor erweitern (GP-Kandidaten / Bestands-Inventar / `bind_layers`) — Folgeschritt.
