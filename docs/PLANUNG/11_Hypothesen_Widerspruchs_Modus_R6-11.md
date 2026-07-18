# Hypothesen- & Widerspruchs-Modus (R&D) — R6.11

> **ROADMAP-Bezug:** R6.11 (Track „Alleinstellung"), Größe M, hängt an Q4 (Evidenz/Wissensbasis) + Pairing-Graph.
> **Idee:** Der Warum-Layer **offensiv** — nicht erklären, was ist, sondern **erforschen, was sein könnte.** Der R&D-Modus für die Food-Alchemist-DNA: gezielte Experimente statt Zufall, und Widersprüche im Wissen als Forschungsfragen sichtbar machen statt still zu übertünchen.

---

## 1. Zwei Modi

**Hypothesen-Modus** — „paare X ungewöhnlich":
- Kandidaten gerankt nach **geteilten Volatil-Klassen** (Aromastoff-Gruppen), mit **Mechanismus + Evidenz-Stufe**.
- Experiment mit Absicht: warum diese Paarung plausibel sein könnte, nicht „random".

**Widerspruchs-Detektor** — Wissen gegen sich selbst prüfen:
- Domain-Doc ⇄ Graph-Kante uneinig → als **R&D-Frage surfacen** (nicht still auflösen) + ab in die Research-Queue (Q4).

## 2. DoD

- [ ] Hypothesen-Modus: „paare X ungewöhnlich" → Kandidaten nach geteilten Volatil-Klassen gerankt, mit Mechanismus + Evidenz-Stufe.
- [ ] Widerspruchs-Detektor: Domain-Doc ⇄ Graph-Kante uneinig → R&D-Frage (nicht still auflösen) + Research-Queue (Q4).
- [ ] Ergebnis **immer mit Evidenz-Stufe**; T3/T0 klar als **Hypothese**, nie als Fakt.
- [ ] Vorschlag → 1 Klick → Draft-Rezept (`recipes.POST`) oder **Lab-Journal-Eintrag** (03.05 Lab Journal).
- [ ] MCP-Tool (`knowledge.HYPOTHESIZE` o. ä.), read-only bis Draft.
- [ ] Test: bekannter strittiger Fall (Domain-Doc vs. Graph) wird korrekt als offene Frage geflaggt, nicht willkürlich entschieden.

## 3. Abhängigkeiten + Einordnung

- **Q4 (Evidenz-Abdeckung)** ist die harte Voraussetzung: ohne Evidenz-Stufen kein ehrlicher Hypothesen/Fakt-Unterschied. Der Widerspruchs-Detektor **speist Q4 zurück** (findet Lücken/Konflikte fürs Wissens-Kuratieren).
- **Pairing-Graph + Molekül-/Volatil-Daten** (Station 0/2, FooDB/Ahn) — die Basis für „geteilte Volatil-Klassen".
- **Grenzt an [08](08_Planungs_und_Kreativ_Ebene.md):** dort ist Erfindung im *Planungs*-Skizzenraum; hier ist Erfindung im *Wissens-/R&D*-Raum (Aroma-Hypothesen). Beide teilen die „darf spekulieren, aber markiert"-Haltung — 11 ist die wissenschaftliche Variante.
- **„Keine Erfindungen" gilt weiter für Fakten** — der Modus erfindet HYPOTHESEN, klar als solche markiert; das ist der erlaubte Spekulations-Raum (Q4-Evidenzstufen T0/T3).

## 4. Bewusste Nicht-Ziele

- Keine als Fakt getarnte Spekulation — Evidenz-Stufe ist Pflicht.
- Kein stilles Auflösen von Widersprüchen — sie werden als Frage sichtbar.
- Kein Auto-Persist — Vorschlag bleibt Draft/Lab-Journal bis zur menschlichen Übernahme.

*Verzahnt: Q4 (Warum-Layer/Evidenz, wechselseitig), Pairing-Graph/Station 0-2, [08_Planungs_und_Kreativ_Ebene.md](08) (Kreativ-Raum, Schwester), Lab Journal (03.05). Erstellt 2026-07-18.*
