# Pairing-Offense-Trio — Aroma-treue Substitution · Dish-Reverse-Engineering · Überschuss-zu-Gericht

> **ROADMAP-Bezug:** R6.8 + R6.9 + R6.10 (Track „Alleinstellung"). Ein File, weil alle drei **denselben Hebel offensiv nutzen: den Anker-Pairing-Graph** — nicht beschreibend („was passt"), sondern erzeugend („bau mir was, das trägt").
> **Warum ein eigener Spec:** kein Wettbewerber hat das; hängt an derselben Graph-Infra; noch komplett undokumentiert außerhalb der ROADMAP-Zeilen.

---

## 1. Gemeinsame Voraussetzungen (Querschnitt)

- **Warum-Layer (Q4):** jeder Vorschlag trägt **Mechanismus + Quelle + Evidenz-Stufe**. Kein Beleg → als Hypothese (T3/T0) markiert, nie als Fakt.
- **Konnektivität (Q5) — halb entblockt (Stand 2026-07-12):** Graph-Dichte/Coverage ist gelöst (Station 2: 37→58 %, ~179k Kanten). **Offen:** (a) Kohärenz-Score-Batch-Lauf (KI-Judge, braucht echten Provider) + (b) Zutaten-Anker-Reichweite 60 %. → das Trio ist baubar, der volle Effekt braucht (a)+(b).
- **Tausch-Doktrin (aus R6.3):** Allergen-Neuberechnung sichtbar VOR dem Tausch; Übernahme nur explizit je Vorschlag; `swap_locked` respektiert; read-only bis expliziter Tausch.

---

## 2. R6.8 — Aroma-treue Substitution · Größe M · hängt an R6.3

Ersatz, der den **Geschmack erhält**, nicht nur den Preis senkt.

**DoD:**
- [ ] Ersatz-GP nach **Kanten-Überlappung im Anker-Graph** gerankt (nicht nur Äquivalenz/Preis).
- [ ] Ausgabe: **erhaltene vs. verlorene Aroma-Brücken** + Kohäsions-Delta fürs Gesamtgericht.
- [ ] Mit R6.3-Kosten kombiniert: „billiger UND aroma-treu" vs. Trade-off sichtbar.
- [ ] Allergen-Neuberechnung im Vorschlag VOR Tausch; `swap_locked` respektiert.
- [ ] MCP-Tool `substitution.SUGGEST` (Modus `flavor`), read-only bis Tausch.
- [ ] Test: Klassiker-Tausch (Estragon↔Kerbel) rankt vor aroma-fernem, gleich teurem Ersatz.

## 3. R6.9 — Dish-Reverse-Engineering · Größe L · hängt an R1

Fremdes Gericht → Aroma-Skelett → Nachbau aus **eigenem** Bestand.

**DoD:**
- [ ] Input Text/fremde Karte → Zerlegung in GPs (Matching gegen Stamm; Unmatched → Review-Queue, kein Raten — nutzt #507-Recall + 07-LA-First-Mint wo eine LA existiert).
- [ ] **Aroma-Skelett** aus dem Pairing-Graph (tragende Anker + Verbund-Kanten).
- [ ] Rekonstruktion aus eigenem VK-Portfolio: „nächstes Gericht bei uns" + Lücken („dieser Anker fehlt im Bestand").
- [ ] Ergebnis mündet per Klick in R6.4 Ideen-Labor / `recipes.POST`-Draft.
- [ ] Foto-Input als Ausbaustufe markiert (Multimodal-Provider = Martin); **Textpfad zuerst**.
- [ ] Test: 3 bekannte Gerichte reverse-engineered → Zerlegung plausibilisiert.

## 4. R6.10 — Überschuss-zu-Gericht · Größe M · hängt an Q1 + Pairing-Graph

Erster **bidirektionaler** Contract-Fall: Lager meldet Überschuss, FA schlägt Verwertung vor.

**DoD:**
- [ ] Input: Überschuss-Bestand eines GP über den Core-Contract (aus Nachbar-Modul, NICHT FA-eigene Lagerhaltung).
- [ ] Graph schlägt Gerichte/Konzepte, die den GP geschmacklich **tragen** (Anker-Relevanz, nicht bloß „enthält").
- [ ] Vorschlag mit Verwertungs-Menge + Kohäsions-Begründung; Draft-Konzept per Klick.
- [ ] Grenze: FA rechnet/schlägt vor, Bestand + Bestellung bleiben Nachbar-Modul.
- [ ] FA-seitig baubar + testbar mit **Mock-Bestand**; produktiv erst mit Q1/N-Track.
- [ ] Test: Mock-Überschuss rein → sinnvoller Gericht-Vorschlag raus (erster Contract-Beweis in Rückrichtung).

---

## 5. Reihenfolge + Abhängigkeiten

```
Q5 (Anker-Reichweite + Kohärenz-Score-Lauf) ── halb entblockt ──┐
R6.3 Kosten-senken-Tausch-Strecke ──► R6.8 (nutzt die Strecke)  │
R1 Portfolio ──► R6.9                                           ├─► voller Effekt
Q1 Core-Contract + N-Track ──► R6.10 (produktiv)               ─┘
```

**Empfehlung:** R6.8 zuerst (kleinste, baut auf R6.3), dann R6.9 (Textpfad), R6.10 zuletzt (Contract-blockiert, aber mit Mock vorbereitbar).

## 6. Bewusste Nicht-Ziele
- Keine FA-eigene Lagerhaltung (R6.10 = Contract-Konsument).
- Kein Auto-Tausch (immer explizit, Allergen-Check first).
- Kein Foto-Input in v1 (Text zuerst).
- Nichts ohne Evidenz-Stufe (Warum-Layer).

*Verzahnt: Q4 (Warum-Layer), Q5 (Graph-Konnektivität), R6.3 (Tausch-Strecke), [07_LA_First_GP_Mint_ueberall.md](07_LA_First_GP_Mint_ueberall.md) (GP-Matching im Reverse-Engineering), [08_Planungs_und_Kreativ_Ebene.md](08_Planungs_und_Kreativ_Ebene.md)/R6.4 (Ideen-Labor als Zielklick), [02_RAG_System_FoodAlchemist.md](02_RAG_System_FoodAlchemist.md) (#507 Recall). Erstellt 2026-07-18.*
