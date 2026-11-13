---
title: Concepter
order: 4
---

# 🧱 Concepter

Der Concepter ist das **Rückgrat** des Moduls. Hier stellst du aus einzelnen Gerichten stimmige Angebote zusammen — die Brücke zwischen „wir haben Rezepte" und „wir haben etwas zu verkaufen".

Er kennt zwei Bausteine:

- **Concept** — ein **Slot-Gerüst**: Plätze für mehrere Rollen (z. B. Vorspeise · Hauptgang · Dessert), die mit Gerichten belegt werden. Das Gerüst steht, die Belegung variiert.
- **Paket** — ein **bepreistes Bündel** aus mehreren Gerichten, das als Einheit angeboten wird.

---

## Ein Browser für beides

Concepts und Pakete teilen sich einen **gemeinsamen Browser** mit einem Umschalter `Concepts | Pakete`. Du musst nicht zwischen zwei Screens springen — alles liegt an einem Ort, geöffnet wird im **Voll-Editor-Modal** mit Tabs.

Im Editor arbeitest du mit:

- **Gericht-Baum-Picker** — Gerichte über die Warengruppen-/Kategorie-Struktur finden und in die Slots ziehen.
- **Live-Kosten-Streifen** — während du zusammenstellst, siehst du laufend, wie sich Wareneinsatz und Kalkulation verändern.
- **Kalkulation-Tab** — die Aufschlüsselung pro Position, genau wie beim einzelnen Gericht (siehe [Kalkulation](kalkulation)).

> Die alten Einzel-Screens für Concepts und Pakete bleiben als Deep-Link erreichbar, tauchen aber nicht mehr in der Seitenleiste auf — der vereinheitlichte Browser ist der Standardweg.

---

> **Warum das zählt:** Im Concepter entscheidet sich, ob ein Angebot kulinarisch *und* wirtschaftlich aufgeht. Der Live-Kosten-Streifen macht beides gleichzeitig sichtbar — du komponierst nicht ins Blaue.

## Pakete über MCP (Agenten)

`foodalchemist.pakete.*` und `paket_gerichte.*` arbeiten auf den Concepter-Paketen (Concepts mit `kind=paket`), genau denen im Reiter „Pakete“.
Die Alt-Pakete (Tabelle `foodalchemist_packages`), auf denen Abos, Speisepläne und Bestellungen noch laufen, liest `foodalchemist.abo_pakete.GET/LIST`. Dort steht bei einer Abo-Fassung der Team-Preis des Mandanten (unvollständiger EK = kein Preis + Grund, nie 0 €). Beide Modelle haben eigene Id-Räume.

- Anlegen: `foodalchemist.pakete.POST` oder `foodalchemist.concepts.POST(kind=paket)`. Agenten legen immer als **Entwurf** an.
- Befüllen: `foodalchemist.paket_gerichte.SET` (Vollersatz der Gericht-Positionen) oder `concept_slots.POST`.
- Einhängen: `foodalchemist.concept_slots.POST(concept_id, package_id=<Paket-Id>)`; `package_id` meint das Concepter-Paket.
- Bearbeiten per MCP nur im **Entwurf**. Ist ein Konzept oder Paket aktiv, setzt es ein Mensch mit Rolle Kuratieren im Concepter (Spalte Status) auf „Entwurf“; die Fehlermeldung sagt das.
- Text-Blöcke (`type=text`, Titel + Text) eines Konzepts erscheinen in Angebots-Präsentation und Angebots-PDF in Slot-Reihenfolge.
- Alt-Pakete (Tabelle `foodalchemist_packages`) überführt `php artisan foodalchemist:pakete-to-concepts --id=<id>` (ohne `--apply` nur Vorschau).
