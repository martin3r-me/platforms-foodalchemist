# Spec 69 · Eigenproduktion im Lager (Entwurf, nicht gebaut)

Stand 2026-10-08 · Anlass Dominique: „Man hat eine Suppe gekocht und eingefroren, das muss auch
erfasst werden. Es geht nicht nur um Grundprodukte, sondern auch um Basisrezepte." Erst Plan, dann
Entscheid, dann Bau.

## Ausgangslage
Lager (Spec 66/67) kennt nur Grundprodukte und Lieferantenartikel. Selbst Hergestelltes, also
Fonds, Suppen, Saucen, vorportionierte Komponenten, gekühlt oder eingefroren, taucht nirgends auf:
- nicht im Bestand und nicht in der Inventur,
- nicht im Bestandswert, damit stimmt die Verbrauchsrechnung nicht,
- nicht in der Produktionsplanung, die kocht neu, obwohl noch 8 l im TK liegen.

## Grundidee
**Rezept als Lagerartikel.** Ein Bestand kann neben `gp_id` / `supplier_item_id` auch `recipe_id`
tragen. Basisrezepte zählen in kg/l (Yield), Gerichte optional in Portionen.

Anders als Zukaufware braucht Eigenproduktion **Chargen**. Jede Herstellung ist ein eigener Posten
mit Herstell- und Einfrierdatum und Haltbarkeit. Ohne Chargen weiß niemand, welche Suppe zuerst weg
muss. Das ist zugleich die Pflicht aus der Lebensmittelhygiene: gekennzeichnet, datiert, rückverfolgbar.

## Bausteine

### 69.1 Datenmodell
- `inventory_stocks.recipe_id` (nullable) und Basiseinheit `g` / `ml` / `Port`.
- `inventory_batches`: stock_id, recipe_id, charge (laufende Nummer), produziert_am,
  eingefroren_am (nullable), haltbar_bis, zustand (gekuehlt | tiefgekuehlt), menge_basis (Rest),
  preis_je_basis (Rezept-EK beim Einlagern), production_order_id (nullable), notiz.
- Rezept: `haltbarkeit_gekuehlt_tage`, `haltbarkeit_tk_tage` als Vorschlagswerte für das MHD.

### 69.2 Zugang
- **Aus der Produktion:** bei der Fertigmeldung einer Produktionszeile „ins Lager" (Menge,
  Lagerort, gekühlt/TK). Daraus entstehen Charge und Etikett.
- **Von Hand:** „Selbst hergestellt" im Reiter Bewegungen. Gleiches Formular wie der Zugang
  (Spec 67), mit Rezept statt Grundprodukt.
- Bewertung: Rezept-EK je kg bzw. je Portion zum Zeitpunkt des Einlagerns.

### 69.3 Etikett
Druck je Charge: Name, Charge, hergestellt, eingefroren, **verbrauchen bis**, Menge,
**Allergene** (aus der Rezept-Kaskade). Das ist der große Mehrwert gegenüber Papier-Etiketten,
weil FA die Allergene schon kennt.

### 69.4 Abgang und Verbrauch
- Entnahme **FIFO nach Haltbarkeit** (älteste Charge zuerst, abweichend wählbar).
- Abgang mit Grund wie Spec 67. MHD-Ablauf erzeugt einen Vorschlag „Verderb".
- **Warnliste:** Chargen, die in ≤ 3 Tagen ablaufen, im Lager und als Signal.

### 69.5 Planung und Produktion (zweiter Schritt)
- Braucht ein Gericht ein Basisrezept, das im Lager liegt, zeigt die Produktion „8 l im TK,
  Charge 14, haltbar bis …" und bietet „aus Lager nehmen" statt neu kochen an. Der Bedarf an
  Zutaten und der Bestellvorschlag sinken entsprechend.
- Entnahme bucht beim Produktionsabschluss.

### 69.6 Inventur und Controlling
- Zählliste mit Rezept-Positionen, je Charge gezählt.
- Bestandswert enthält Eigenproduktion zum Rezept-EK. Das ist richtig für die
  Verbrauchsrechnung (AB + Einkauf − EB), weil der Wert genau die eingekauften Zutaten
  widerspiegelt. Doppelzählung entsteht nicht, weil die Zutaten bei der Produktion nicht als
  Abgang gebucht werden (periodisches Lager).

### 69.7 MCP
`inventory.GET` mit Rezept-Beständen und Chargen; `inventory_batches.GET`;
`inventory_movements.POST` mit `recipe_id`; Etikett als Dokument-Link.

## Offene Entscheidungen für Dominique
1. Nur **Basisrezepte** (kg/l) oder auch **Gerichte in Portionen** (z. B. 40 Portionen Lasagne TK)?
2. **Chargen auch für Zukaufware?** Eher nein für Stufe 1, nur für Eigenproduktion.
3. **Etikettenformat:** A4-Bogen (z. B. 24 Etiketten) oder Etikettendrucker (z. B. 62 mm Rolle)?
4. Soll die Produktion **automatisch** vorschlagen, aus dem Lager zu nehmen, oder nur anzeigen?

## Reihenfolge (Vorschlag)
69.1 + 69.2 (Hand) + 69.3 + 69.6 zuerst, also erfassen, etikettieren, zählen. Danach 69.2
(Produktion) + 69.4 + 69.5.
