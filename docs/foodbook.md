---
title: Foodbook / Portfolio
order: 5
---

# 📔 Foodbook / Portfolio

Das Foodbook ist die **Kunden-Seite** des Moduls. Hier werden fertige [Concepts](concepter) zu einem konkreten **Angebot** zusammengestellt — das, was am Ende beim Kunden landet.

Ein Foodbook bündelt:

- **Kapitel** — die Gliederung des Angebots (z. B. nach Anlass, Menülinie oder Saison).
- **Personenzahl (Pax)** — für wie viele Gäste kalkuliert wird.
- **Angebots-Preise** — die Preise, die der Kunde sieht.

Ein Foodbook arbeitet primär mit **Concepts** (gebündelte Pakete, €/Gast). Die Komposition einzelner Teller passiert eine Ebene tiefer im Concepter — das Foodbook stellt die fertigen Bausteine zum Portfolio zusammen.

Seit Spec 19 (2026-07-23) kann ein Kapitel **zusätzlich einzelne Gerichte direkt** tragen: neben 0–n Concepts (Paket, €/Gast) auch 0–n `recipe_ref`-Blöcke, die ein VK-Gericht direkt referenzieren (€/Position). Damit ist die frühere Regel „Foodbook komponiert nur Concepts" (Weg B exklusiv) teilrevidiert. Ein `recipe_ref`-Block akzeptiert nur echte VK-Gerichte (keine konzept-lokalen Slot-Varianten).

## Kreativ-Modus (E9, 2026-07-25)

Der **Kreativ-Tab** hat einen Modus-Schalter — pro Kapitel wählbar, erbt sonst den Foodbook-Default, Code-Default **`hybrid`**:

- **`voll_kreativ`** — leere Leinwand. Kein Bestand eingeblendet; die Pairing-Inspiration zeigt nur **abstrakte** Aroma-Nachbarn. Erdung nur auf Abruf.
- **`hybrid`** (Default) — Pairing-Nachbarn mit **Verfügbarkeits-Markern** je tragendem GP: `führen` (Favorit), `leicht` (Lead-LA + Preis), `Lücke` (keine beschaffbare Quelle).
- **`datenbank`** — vom Verfügbaren aus; die Inspiration komplettiert aus dem, was wir führen.

Grundprinzip **Pull-not-Push**: Bestand wird nie dauerhaft eingeblendet (kein Anker-Effekt), erst ein Aroma-/Zutat-Seed zeigt Nachbarn. Der **Pairing-Graph ist in allen Modi erlaubte Inspiration** (er öffnet den Aroma-Raum, verankert nicht am Lager) — nur die Bestands-/Verfügbarkeits-Sicht variiert je Modus. Eine markierte **Lücke** (Aroma gewünscht, kein beschaffbarer GP) lässt sich bewusst ins **Signale-Cockpit** melden (`SignalTyp::SortimentsLuecke`) — Kreativität wird zu Sortiments-/Beschaffungs-Nachfrage. Skizzen erden weiterhin nichts; erst der Kapitel-Go legt an.

MCP-Lockstep: `foodbook_kapitel.PUT` trägt `creative_mode`, `foodbooks.POST`/`foodbook.GET` den `creative_mode_default`.

---

> **Die Logik dahinter:** Concepter = das Kochen im Kopf (was passt zusammen, was kostet es). Foodbook = das Verkaufen (welches Concept biete ich wem, für wie viele, zu welchem Preis).
