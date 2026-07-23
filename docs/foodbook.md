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

---

> **Die Logik dahinter:** Concepter = das Kochen im Kopf (was passt zusammen, was kostet es). Foodbook = das Verkaufen (welches Concept biete ich wem, für wie viele, zu welchem Preis).
