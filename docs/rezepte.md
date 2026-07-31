---
title: Rezepte
order: 3
---

# 📖 Rezepte

Rezepte gibt es in zwei Sorten: **Basisrezepte** sind das, was in der Küche produziert wird. **Gerichte** sind das, was verkauft wird. Beide bauen auf den [Stammdaten](stammdaten) auf — und damit rechnet sich ihr Wareneinsatz von selbst.

---

## 🍲 Basisrezepte

Ein Basisrezept ist ein **Produktionsrezept** — eine Sauce, ein Fond, eine Beilage, eine Komponente. Es besteht aus Grundprodukten und kann andere Basisrezepte als Bausteine enthalten (verschachtelt bis zu drei Ebenen tief). So baust du einmal eine „Demi-Glace" und verwendest sie in zehn anderen Rezepten weiter.

Pro Rezept werden u. a. gepflegt:

- **Zutaten** mit Menge und Einheit (g, ml, kg, l, Stk …).
- **Garverlust** je Zutat — damit aus der Roh-Einwaage die echte Ausbeute wird.
- **Zubereitung** als echte Schritt-für-Schritt-Anleitung (siehe unten).
- **Eigenschaften** wie Haltbarkeit, Regenerierbarkeit, Transportstabilität (wichtig fürs Catering).

Der **Wareneinsatz** ergibt sich automatisch aus den Lead-LA-Preisen der enthaltenen Grundprodukte.

### 👣 Die Zubereitung als Anleitung

Die Zubereitung ist keine Textwand, sondern eine **Liste aus Schritten**. Ein Schritt hat eine Nummer, einen optionalen Abschnitt (z. B. „Mise en Place", „Garen", „Finish") und seine **eigenen Fotos**.

- Die **Nummer entsteht aus der Reihenfolge** — sie wird nie getippt. Wer einen Schritt nach oben zieht, nummeriert alles automatisch neu.
- **Fotos kleben am Schritt**, nicht an einer Nummer. Umsortieren verliert also keine Bildzuordnung mehr.
- Ein Foto darf an **mehreren Schritten** hängen (dasselbe Mise-en-Place-Bild bei Vorbereitung und Anrichten), und ein Foto ohne Schritt-Zuordnung gilt als allgemeines Rezept-Foto.
- Alle Fotos eines Rezepts liegen in einem **Pool** — man wählt per Klick, statt eine Schrittnummer einzutippen.
- Vorhandenen Text (z. B. aus Word) kann man per **„Markdown einfügen"** übernehmen: `##` wird zum Abschnitt, `1.` oder `-` zum Schritt.
- Die **KI** schlägt auf Wunsch eine komplette Schrittfolge aus den Zutaten vor — als Vorschlag, der erst mit „Übernehmen" gespeichert wird.

Für alles, was die Anleitung nur *liest* (Suche, Prozessanker-Erkennung, Auswertungen), wird zusätzlich eine Textfassung mitgeführt. Sie wird **automatisch aus den Schritten erzeugt** und ist deshalb nie von Hand zu pflegen.

**Drucken.** Die Anleitung kommt an drei Stellen aufs Papier — jeweils mit einem Umschalter **mit Fotos / nur Text**:

| Wo | Wofür |
|---|---|
| Produktionsblatt | Rezept-Übergabe samt Mengen und Ausbeute |
| Produktionsauftrag | der Tagesauftrag; der Stand wird beim Start **eingefroren** und ändert sich nicht mehr mit dem Rezept |
| Postenzettel „Anleitung" | nur die Schritte, groß gesetzt zum Aufhängen am Posten — kein Wareneinsatz, kein Einkauf |

Bestehende Rezepte werden per `php artisan foodalchemist:steps-backfill` einmalig überführt (deterministisch, ohne KI; Fotos wandern über ihre alte Schrittnummer an den passenden Schritt).

---

## 🍽️ Gerichte (Verkaufsrezepte)

Ein Gericht ist die **verkaufsfähige Speise** — der Teller, den der Gast bekommt. Es wird aus Komponenten zusammengesetzt (am besten aus fertigen Basisrezepten) und trägt alles, was es zum Verkauf braucht:

- **Speisen-Klasse** — die Einordnung in die Verkaufs-Taxonomie.
- **Plating & Service** — wie der Teller aufgebaut und serviert wird (nicht zu verwechseln mit der Produktion).
- **Preis & Marge** — der Verkaufspreis und was davon hängenbleibt.

Weil ein Gericht auf Basisrezepten und Grundprodukten steht, kennt es seinen Wareneinsatz automatisch — die Grundlage für die [Kalkulation](kalkulation).

---

> **Faustregel:** Wiederkehrende Komponenten gehören in ein **Basisrezept**, nicht direkt ins Gericht. Dann pflegst du sie an einer Stelle und jedes Gericht profitiert von der Änderung.
