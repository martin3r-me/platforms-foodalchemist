# 58 · Pairing: Harmonie und Kontrast

**Stand 2026-10-06 · Branch `feat/pairing-harmonie-kontrast` off main (`16b92b4a`) · Status: Pakete 1–6 gebaut, Suite läuft — PR/demo offen**

> **Tracking:** Office Dev-Package 23, Features-Board.

## Anlass

Dominique (2026-10-05/06, UI-Durchgang „fa-pass"): Am Pairing-Netz ist auf den ersten Blick nicht
ablesbar, ob zwei Bestandteile zusammenpassen („passt Simmentaler Rind zur A1-Sauce?"). Die
Analyse dahinter ergab, dass nicht nur die Darstellung, sondern die Logik das Problem ist:

1. **Namensraten:** Bestandteile ohne Anker-Zuordnung werden per Wortteil aufgelöst
   (`resolveByName`). Gemessen am Gericht 2619 (lokal und demo identisch): „Sauce: Chimichurri" →
   *A1 Original Sauce*, „Mojosauce rot: frisch" → *Atlantischer Lachs*, „Geröstete Bundmöhren" →
   *Mandel, trocken geröstet*.
2. **Zwei Wahrheiten:** Das Netz liest die Anker-Mappings am Gericht (Beutel bis 24 KI-Anker),
   der Zusammenhalt zerlegt die Zutaten — dasselbe Gericht zeigt verschiedene Anker.
3. **Unehrliche Kennzahl:** Zusammenhalt „90" bei 5 von 45 bewerteten Paaren (11 %).
4. **Brücken fast immer „best"** (Overlap über den kleineren Grad), Partner unsortiert.
5. **Vorschläge ohne Ernährungsform** (Hühnerfond für ein veganes Gericht) und Allrounder vorn
   (Toast, Ciabatta, Kaffee).
6. **Kontrast leer:** die Kontrast-Logik liest `anchor_taste_vectors` (0 Zeilen).

## Entscheidungen (Dominique)

- **Wahrheit = Foodpairing-Sterne** (foodpairing.com / Foodpairing-Buch, eine Quelle). Keine
  Molekül-Erklärung: gemessen haben nur 295 von 2.628 Inspire-Ankern direkte Molekül-Daten,
  über die Grundzutat höchstens rund die Hälfte und dann nur ungefähr. Eine halbe Erklärung ist
  schlechter als keine (bestätigt die Entscheidung beim Inspire-Umbau).
- **3 Sterne = Harmonie** („echtes Food Pairing") — nur diese zählen für Zusammenhalt, Brücken
  und Vorschläge. **2 Sterne = „passt"** — Rauschen (247.070 von 279.920 Kanten), wird nur leise
  gezeigt, zählt nicht. 1 Stern ist nicht importiert.
- **Kontrast gehört dazu, mit eigener Logik:** nicht geteilte Aromen, sondern Geschmacks-
  Gegensätze zwischen den Bestandteilen (Säure–Fett, süß–salzig, süß–sauer, bitter–süß,
  scharf–süß/Fett, umami–sauer) plus Textur (knusprig–cremig).
- **Fett und Zubereitung** fließen ein: Zubereitung verschiebt das Profil eines Bestandteils
  (Rösten → bitter/umami, Karamellisieren → süß), Fett ist Kontrastpartner von Säure/Schärfe.
- **Rolle der Zutat** (Aromaträger, Komponente, Beilage, Garnitur) gewichtet: Gewicht =
  Rolle × Mengenanteil. Harmonie zählt vor allem zwischen Aromaträgern und Komponenten;
  beim Kontrast zählen Garnitur und Beilage voll.

## Modell

Ein Gericht = Bestandteile (Zutatenzeilen). Je Bestandteil: Anker (für Harmonie),
Geschmacksprofil + Textur (für Kontrast), Rolle und Mengenanteil (Gewicht).

**Anker-Auflösung (eine Quelle für Netz und Zusammenhalt):**
- Grundprodukt → `gp_anchor_mappings` (Kern). Ohne Mapping: nur **exakte** Gleichheit des
  Grundnamens (vor dem Doppelpunkt) mit `display_de`/Slug (`ankerSlugExakt`), sonst Lücke.
- Basisrezept → eigenes Kern-Mapping; ohne Mapping **rekursiv über seine eigenen Zutaten**
  (max. 3 Ebenen, Regelwerk Basisrezepte §4), stärkster Bestandteil = Kern. Kein Namensraten.
- Nicht auflösbar → sichtbare Lücke („noch keinem Aroma zugeordnet").

**Harmonie je Bestandteil-Paar:** beste Kante zwischen ihren Ankern.
`sehr gut` (3★) · `passt` (2★, zählt nicht) · `über Brücke` (kein Direktpaar, aber ein dritter
Bestandteil des Gerichts harmoniert 3★ mit beiden) · `kein Bezug`.

**Zusammenhalt:** gewichteter Anteil der 3★-Paare an den bewerteten Paaren — immer zusammen mit
der Abdeckung (bewertete / alle Paare). Keine Zahl ohne Abdeckung.

**Kontrast je Bestandteil-Paar:** Gegensatz-Paare der Geschmacksachsen
(`PairingService::GESCHMACK_GEGENSATZ`), Profil je Bestandteil aus `SensorikService`
(Basisrezept = gegartes Profil, Grundprodukt = roh, salzig/süß/fettig an LA-Nährwerten
geerdet = „belegt", sauer/bitter/umami/scharf = „geschätzt"), Zubereitungs-Delta aus
`vocab_process_sensory_deltas`. Textur-Kontrast knusprig ↔ weich.

**Lücken (Gericht):** z. B. viel Fett ohne Säure, nur weich ohne Knuspriges.

## Pakete

1. **Auflösung** — Namensraten raus, Basisrezepte rekursiv; Golden-/Batch-Tests nachziehen.
2. **Analyse-Service** — Harmonie (3★/2★/Brücke), Zusammenhalt mit Abdeckung, Kontrast, Lücken,
   Rollen-Gewicht. Eine Methode für Gericht und freie Anker-Menge (Composer).
3. **Netz** — Innenring aus der neuen Auflösung; Brücken über Bestandteile des Gerichts;
   Basisrezept-Knoten nur als getrennte Vorschlagsliste bis zur Rezept-Atom-Ebene (2c).
4. **Vorschläge** — Ernährungsform des Gerichts beachten, spezifisch vor Allrounder.
5. **MCP** — `pairings.SUGGEST` / `composer.KOHAESION` liefern Harmonie/Kontrast/Lücken.
6. **Anzeige** — Satz je Paar, Matrix ab 3 Bestandteilen, ruhiges Netz (Liniendicke = Sterne).

## Umsetzung (2026-10-06)

| Paket | Commit | Inhalt |
|---|---|---|
| 1 | `4cd594c0` | `PairingService::ankerZeilen` ohne `resolveByName`; GP ohne Mapping nur exakter Grundname (`ankerIdExakt`), Basisrezept rekursiv (`rekursiverKern`, Menge × Rolle, reine Salze nie Kern). Golden-Test nachgezogen. |
| 2 | `fb913bc4` | Neuer `PairingAnalyseService`: Harmonie (3★/2★/Brücke), Zusammenhalt mit Abdeckung, Kontrast (Geschmack + Textur, je Art das stärkste Beispiel), Zubereitungs-Delta, Lücken, Sätze. |
| 5 | `6b88702a` | MCP `pairings.SUGGEST` + `composer.KOHAESION` liefern `harmonie_kontrast`; ausführender Test. |
| 4 | `f40f25d2` | `componentSuggestions`: nur 3★, Ernährungsform (vegan/vegetarisch) filtert Fleisch/Fisch/tierische Fonds bzw. Milch/Ei/Honig. |
| 3 | `e637f41c` | Netz-Innenring aus derselben Auflösung; 2★ standardmäßig aus. |
| 6 | `9c495714` | Baustein `passt-zusammen` im Gericht- und Basisrezept-Detail; Kopfzeile „Harmonie … · n % der Paare bewertbar". |

Gemessen lokal (Kopie Dev-DB, ohne GP-Anker-Mappings): Gericht 2619 → „Rosmarinkartoffeln und
Chimichurri: harmonieren sehr gut", „Spannung: Fett von Rosmarinkartoffeln gegen Säure von
Chimichurri"; Chimichurri löst sich über die eigenen Zutaten auf statt als A1-Sauce. Auf demo
(4.708 GP-Anker-Mappings) wird die Auflösung vollständiger.

## Nicht in dieser Spec

Molekül-Projektion (bewusst nicht), Rezept-Atom-Ebene (Inspire-Umbau 2c), Datenpflege der
Rollen und Anker-Zuordnungen.
