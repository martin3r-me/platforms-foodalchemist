# Konzeptpapier: Concepter-Ebene

## Zweck

Concepter ist die Kompositions-Ebene zwischen den einzelnen Gerichten und dem
Foodbook. Hier werden Gerichte und austauschbare Module zu wiederverwendbaren
Foodkonzepten gebündelt — typischerweise gesteuert nach Anlass und Preis. Ein
Foodbook setzt sich aus vielen Concepts zusammen.

Der Name "Concept" (statt "Menü") ist bewusst offen gewählt: Er deckt klassische
Menüs ab, lässt aber Raum für Linien, Aktionen, Flying Buffets, Saison-Sets und
andere Zusammenstellungen, die nicht der Gang-Logik folgen.

## Einordnung in die Kaskade

```
GP → Rezept → Gericht → [Concept] → Foodbook
                          ↑
                  bündelt Gerichte & Module,
                  wiederverwendbar, fließt in mehrere Foodbooks
```

Vollständige Struktur innerhalb eines Concepts:

```
Foodbook
 └─ enthält viele Concepts
     └─ Concept = Slot-Gerüst
         └─ Slot = Rolle (z.B. Vorspeise / Hauptgang / Dessert)
             └─ gefüllt mit EINEM von:
                 · Modul   (austauschbar, mehrere Optionen, preisgesteuert)
                 · Gericht  (fest gesetzt, eigener Preis)
                     └─ Gericht → Rezept → GP
```

## Kernprinzip 1 — Der Slot ist stabil, der Inhalt ist flexibel

Nicht alles ist ein Modul. Ein Concept besteht aus **Slots**, die eine Rolle
definieren (was gehört an diese Stelle). Was den Slot füllt, kann zweierlei sein:

- **Modul** — ein austauschbarer Baustein mit mehreren Optionen. Wird verwendet,
  wo echte Austauschbarkeit existiert (z.B. der Hauptgang, der je nach Budget
  wechselt).
- **Gericht (fest)** — ein direkt gesetzter Eintrag mit eigenem Preis. Wird
  verwendet, wo der Inhalt fix ist (z.B. ein immer gleiches Dessert).

Beide liefern einen Preis an den Slot. Der Slot summiert sich ins Concept, das
Concept ins Foodbook. Die Live-Kalkulation funktioniert in beiden Fällen gleich.

Vorteil: Man muss nicht alles künstlich in Module zwängen. Ein fixes Dessert ist
einfach ein gesetztes Gericht — kein "Modul mit einer einzigen Option".

### Was ist ein Modul konkret? (Beispiel Grillbuffet)

Ein Modul ist ein **austauschbarer Baustein, der eine bestimmte Rolle füllt** —
nicht der ganze Slot, sondern das, was in den Slot eingesetzt wird, und das durch
ein gleichartiges Modul ersetzt werden kann.

Angenommen es gibt mehrere Grillbuffets:

```
Grillbuffet 1                     Grillbuffet 2
 ├─ Slot: Hauptgang (HG) ──→ Modul "HG Grillbuffet 1"
 ├─ Slot: Beilagen      ──→ …       ⇅  austauschbar
 └─ Slot: Salate        ──→ …    Modul "HG Grillbuffet 2"
```

Der **Hauptgang vom Grill ist ein Modul**, weil er austauschbar ist: Den
HG-Slot in Grillbuffet 1 kann man mit dem HG-Modul aus Grillbuffet 2 füllen.
Beide Module füllen dieselbe **Rolle** (Hauptgang im Grillkontext), darum sind
sie gegeneinander tauschbar — du tauschst nicht das ganze Buffet, sondern nur
den Baustein in dem einen Slot.

Das Entscheidende ist die **Rolle**: Ein Modul "weiß", welche Rolle es füllt
(hier: Grill-Hauptgang). Nur Module derselben Rolle sind füreinander
einsetzbar. Ein Dessert-Modul kann nicht in den Hauptgang-Slot, ein
Grill-Hauptgang schon mit jedem anderen Grill-Hauptgang getauscht werden.

Daraus folgt auch der Preis-Hebel: Tauscht man das HG-Modul gegen ein
günstigeres/teureres der gleichen Rolle, ändert sich der Concept-Preis
automatisch — genau das ist die Stellschraube für den Zielpreis-Konfigurator.

Ein fixes Element (z.B. immer derselbe Begrüßungsgruß) bleibt dagegen ein fest
gesetztes Gericht im Slot, kein Modul — weil es nichts auszutauschen gibt.

## Kernprinzip 2 — Preis: Output heute, Input als Ausbaustufe

- **Preis als Output (Basis):** Module und Gerichte frei zusammenstellen, das
  System rechnet den Preis live aus (wie im Editor mit der Live-Summe).
- **Preis als Input (Konfigurator, Ausbaustufe):** Zielpreis vorgeben, das System
  schlägt Module vor bzw. tauscht sie. Der Konfigurator greift **nur an den
  Modul-Slots** an — fest gesetzte Gerichte sind Fixkosten, austauschbare Module
  sind die Stellschrauben. Das macht den Konfigurator einfacher als "alles ist
  konfigurierbar".

Voraussetzung für den Input-Modus: Module müssen mit Preis-Metadaten und
Rollen-/Austauschbarkeits-Tags versehen sein ("dieses Modul füllt Rolle X und
ist ersetzbar durch jene in derselben Rolle").

## Kernprinzip 3 — Vorlage vs. Freiform: eine Mechanik, nicht zwei

Eine Vorlage ist nichts anderes als ein **gespeichertes Slot-Gerüst**. Freiform
ist der Bau, Vorlage ist das eingefrorene Ergebnis eines Baus.

- Ein Concept startet entweder **leer** (Freiform — Slots frei zusammenstellen:
  3 Gänge, 5 Gänge, Flying Buffet …) oder **aus einer Vorlage** (Concept-Typ mit
  vordefiniertem Slot-Gerüst).
- In beiden Fällen sind die Slots danach **editierbar** (hinzufügen, entfernen,
  tauschen). Die Vorlage gibt nur einen Startpunkt, kettet aber nicht.
- Jedes freiform gebaute Concept kann **als neue Vorlage gespeichert** werden,
  wenn sich die Struktur als wiederkehrend erweist.

## Zwei getrennte Wiederverwendungs-Mechaniken (wichtig)

Diese beiden NICHT vermischen — sonst wird es später schmerzhaft:

- **Vorlage = Kopie-Quelle.** Beim Start aus einer Vorlage wird geforkt; das
  Concept lebt danach eigenständig. Ändert sich die Vorlage, ziehen bestehende
  Concepts NICHT mit.
- **Modul = Referenz.** Ein Modul kann zentral gepflegt und in vielen Concepts
  referenziert werden. Ändert sich das Modul, ziehen alle referenzierenden
  Concepts mit.

Praktische Konsequenz: "Ich habe 40 Concepts aus einer Vorlage gebaut und will
bei allen denselben Hauptgang tauschen" geht **nur über die Modul-Referenz**,
nicht über die Vorlage. Deshalb müssen die zwei Ebenen sauber getrennt bleiben.

## Produktionsrechner (HK1 → HK2)

Da jedes Concept aus Modulen/Gerichten besteht, die aus Rezepten und GPs
bestehen, lässt sich ein Concept komplett durchrechnen — die Kaskade wird einmal
abwärts aufgelöst und aufsummiert. Ziel ist die **Vollkostenrechnung auf der
Food-Seite** (kein Personal-Service, keine Logistik, keine Marge — nur: was
kostet das Essen in der Herstellung wirklich).

### Kostenstufen

```
HK1  = reiner Wareneinsatz, verlustkorrigiert
        Σ (GP-Preis × eingesetzte Menge), bereinigt um Garverlust/Schwund
        → entspricht der Live-Summe aus dem Editor, aber Brutto→Netto sauber gerechnet

HK2  = HK1 + produktionsbezogene Nebenkosten
        + Energie (Strom/Gas fürs Garen, Kühlen)
        + ggf. Verbrauchsmaterial (Verpackung, Öl, Folie …)
        + ggf. anteilige Produktionszeit, falls food-seitig zugerechnet
```

### Was jetzt, was später (bewusste Reihenfolge)

- **Jetzt:** HK1 sauber bauen — inkl. Garverlust/Schwund pro Position
  (Brutto-Einkauf → Netto-Teller). Das ist die Grundlage und größtenteils schon
  im System vorhanden ("Garverluste vorschlagen", `per_instance`-Mengen).
- **Jetzt, aber grob:** HK2 zunächst als **Pauschal-Aufschlag** auf HK1
  (HK1 + X %). Schnell machbar. Wichtig: Die **Datenstruktur** so anlegen, dass
  pro Rezept bereits ein Feld "Energie-/Nebenkosten" existiert — anfangs nur grob
  geschätzt befüllt.
- **Später, Verfeinerung:** Energie pro **Garmethoden-Kategorie** (Kochen /
  Backen / Schmoren / Kalt → Energieklasse), statt Pauschale. Hybrid-Ansatz,
  deutlich genauer, weil Strom nicht mit dem Warenwert skaliert (langer
  Schmorprozess = hohe Energie bei niedrigem Wareneinsatz).
- **Optional, maximale Genauigkeit:** Energie pro konkretem Gar-Prozess
  (Gerät × Temperatur × Dauer → kWh). Pflegeintensiv, nur wo es sich lohnt.

### Wichtige Designentscheidung: Nebenkosten auf Rezept-Ebene

Energie-/Nebenkosten sollten auf **Rezept-/Modul-Ebene** sitzen, nicht erst auf
Concept-Ebene aufgeschlagen werden. Grund: Nur dann wandern die Garkosten **mit
dem Modul mit**, wenn es getauscht wird. Tauscht man im Grillbuffet den
HG (langer Schmorprozess) gegen einen kalt angerichteten HG, muss HK2
automatisch sinken — das geht nur, wenn die Energie am Modul hängt.

### Offene Parameter (später zu entscheiden)

- **Bezugsgröße:** HK2 pro Portion / pro Concept (ganzes Buffet) / pro Person
  bei N Gästen — bestimmt, wo die Skalierung greift.
- **Skalierung:** Skaliert alles linear mit der Personenzahl, oder gibt es
  Fix-/Sprungmengen (z.B. Grundsauce, die ab 50 Personen nur einmal angesetzt
  wird)?
- **Garverlust-Richtung:** Brutto→Netto pro Position sauber definieren, damit der
  Einkaufspreis korrekt auf die Tellermenge umgerechnet wird.
- **HK2-Umfang:** Welche Nebenkostenarten genau (nur Energie, oder auch
  Verbrauchsmaterial / anteilige Zeit)?

## Speiseplan (zweite Ausgabeform neben dem Foodbook)

Neben dem Foodbook soll dieselbe Datenbasis eine zweite Ausgabeform bedienen:
den **Speiseplan**. Logisch betrachtet ist das kein neues Datenmodell, sondern
eine andere **Anordnung über die Zeit** derselben Bausteine.

```
Foodbook   = Bausteine nach Struktur/Anlass gebündelt (Concepts)
Speiseplan = dieselben Bausteine über eine Zeitachse verteilt
             (Tag/Woche/Zyklus: was gibt es wann)
```

### Grundidee

- Ein Speiseplan ist eine **Belegung von Zeit-Slots** (Tag × Mahlzeit, z.B.
  Mo Mittag / Mo Abend / Di Mittag …) mit Gerichten, Modulen oder ganzen
  Concepts — analog dazu, wie ein Concept Rollen-Slots belegt.
- Damit greift dieselbe Mechanik wie bei Concepter: Slot = Zeitpunkt, Inhalt =
  austauschbarer Baustein. Auch der Produktionsrechner funktioniert identisch —
  ein Speiseplan lässt sich genauso bis HK2 durchrechnen (Wareneinsatz und
  Energie pro Tag/Woche).

### Was später zu klären ist

- **Zyklen/Rotation:** Einmaliger Plan oder rotierender Zyklus (4-Wochen-Plan,
  der sich wiederholt)?
- **Wiederholungs-/Abstandsregeln:** Soll das System verhindern, dass dasselbe
  Gericht zu oft / in zu kurzem Abstand wiederkehrt (typische
  Speiseplan-Anforderung in der Gemeinschaftsverpflegung)?
- **Nährwert-/Ausgewogenheits-Sicht:** Braucht der Speiseplan eine Bilanz über
  die Woche (Abwechslung, Allergene, ggf. DGE-artige Vorgaben)?
- **Verhältnis zu Concepts:** Belegt der Speiseplan einzelne Gerichte/Module
  direkt, oder setzt er ganze Concepts auf Zeit-Slots (z.B. "Concept Grillbuffet
  am Freitag")? Vermutlich beides.
- **Gemeinsame Quelle:** Foodbook und Speiseplan ziehen aus demselben Bestand —
  ein Gericht, das im Speiseplan geändert wird, sollte konsistent zum Foodbook
  bleiben (gleiche Referenz-vs-Kopie-Frage wie bei Concepts).

## Offene Punkte

- **Concept-Referenz im Foodbook:** Liegt ein Concept im Foodbook als Referenz
  (zentral gepflegt, Änderung zieht durch) oder als Kopie (Foodbook
  unabhängig)? Vermutlich werden beide gebraucht — Master plus Fork-Möglichkeit
  pro Foodbook (analog zur Standard-vs-kundenspezifisch-Spannung aus der
  BankettProfi-Ablösung).
- **Verschachtelung:** Kann ein Concept andere Concepts enthalten (z.B.
  Sommer-Menü enthält Dessert-Set) oder ist es strikt eine Ebene über Slots?
- **Kundenbindung:** Lebt ein Concept/Modul kundenübergreifend (eine
  Broich-Linie für alle) oder ist es an einen Kunden gebunden?
- **Konfigurator-Tiefe:** Reicht "frei zusammenstellen + Preis live sehen", oder
  soll der Zielpreis-Konfigurator aktiv Module vorschlagen/tauschen?
- **Vorlagen-Pflege:** Wie wird mit veralteten Vorlagen umgegangen (Versionierung
  der Vorlage selbst)?

## Bezug zu Bestehendem

- Deckt sich strukturell mit der DOEC-Notion-Kaskade (Konzept → Kapitel → Hüllen
  → Module → Sections); Module als unterste Baukasten-Einheit sind dort bereits
  etabliert.
- Niveau-System (Haute / Gehoben / Klassisch) kann sowohl auf Modul- als auch auf
  Concept-Ebene als Tag/Filter wirken.
- Die Live-Kalkulation aus dem Editor (Σ live) ist die Grundlage für den
  Output-Preis und damit für den späteren Konfigurator.
