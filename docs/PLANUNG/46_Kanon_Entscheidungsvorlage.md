# Kanon — Entscheidungsvorlage (Stand 2026-09-03)

Auftrag: „zu Kanon ja" + „musst du mir nochmal Input geben". Hier ist er. Alles unten ist
auf demo gemessen, keine Schätzung. Zwei Entscheide für dich, einer davon fachlich.

---

## 1. Was die Befunde sagen — und was sie über die Architektur beweisen

167 Konformitäts-Befunde auf 68 Artefakte. Nach § sortiert, gegen den Bindungszustand
gestellt:

| § | Befunde | Anteil | Dossier | im Prompt? | Durchsetzung im Code? |
|---|---|---|---|---|---|
| §1 Naming | **65** | 38,9 % | 5.938 Z. | **nein** | nein |
| §2 Verarbeitungs-Reduktion | 27 | 16,2 % | 1.968 Z. | ja | teilweise (neu) |
| §10 Anti-Patterns | 21 | 12,6 % | 1.885 Z. | **nein** | nein |
| §11 Derivate | 14 | 8,4 % | 4.075 Z. | **nein** | nein |
| §5 Default-GPs | 12 | 7,2 % | 4.796 Z. | nein | ja (Alias) |
| §8 KI-Beschreibung | 12 | 7,2 % | 2.403 Z. | **nein** | nein |
| §6 Mengen/Yield | 11 | 6,6 % | 2.609 Z. | ja | nein |
| §4 Sub-Rezept | 1 | 0,6 % | 2.630 Z. | ja | nein |
| §3 Pürees | **0** | — | 2.459 Z. | ja | nein |
| §7 Allergene | **0** | — | 1.599 Z. | nein | **ja** |
| §12 Reihenfolge | **0** | — | 3.458 Z. | nein | **ja** |

**Das ist der Beleg für die Entscheidung „Regelwerke durchsetzen statt in den Prompt legen".**
§7 und §12 sind aus dem Prompt entbunden und haben **null** Befunde — weil
`RecipeRecomputeService::allergene()` bzw. `sortiereNachRolle()` sie erzwingen. Kein Zufall,
sondern der Punkt.

**Und der Gegenbeweis dazu:** §2 steht im Prompt und hat trotzdem 27 Befunde. Ein Dossier im
Prompt ist eine Bitte, kein Zwang.

Eine Asymmetrie, die ich nicht überdehnen will: null Befunde bei einem **gebundenen** § (§3)
sind zweideutig — entweder wirkt die Bindung, oder niemand macht bei Pürees Fehler. Das lässt
sich aus diesen Daten nicht trennen. **Ich schlage darum nicht vor, §3 oder §4 zu entbinden.**
Nur bei entbundenen §§ ist die Null ein Beweis.

---

## 2. Der Kanon-Vorschlag

### 2a. Was rein soll — nach Befundlast, nicht nach Gefühl

§1 zerfällt in Unternummern: **§1.2 allein trägt 33 der 65 Befunde**, §1.0 neun, §1.1 sechs.
Das Dossier `regelwerk-basisrezepte-10-12-naming-grundprinzip-typ-vokabular` (5.938 Z.) deckt
genau §1.0–§1.2 ab, also **48 der 65** — und damit 29 % *aller* Befunde. §1.3–1.5 (15 Befunde)
liegen in einem anderen Dossier und bleiben erst mal draußen.

| hinzufügen als `always` | Zeichen | erschlägt |
|---|---|---|
| §1.0–1.2 Naming Grundprinzip + Typ-Vokabular | 5.938 | 48 Befunde (29 %) |
| §10 Anti-Patterns | 1.885 | 21 Befunde (13 %) |
| **Summe** | **7.823** | **69 von 167 = 41 %** |

### 2b. Rechnet sich das? — ja, mit Faktor 10 Luft

Der Heil-Pfad ist nicht gratis: `pruefeUndHeile()` startet Revise **und** Nachprüfung nur,
wenn Befunde vorliegen (`if ($vorher['befunde'] !== [] && …)`). Ein sauberes Rezept spart
also beides.

- **Kosten:** 7.823 Zeichen ≈ **2.608 Token** je Generierung.
- **Ersparnis je vermiedener Heil-Runde:** 1× `recipe.ueberarbeiten` (⌀ 7.374) + 1×
  `conformance.check` (⌀ 18.147) ≈ **25.500 Token**.
- **Break-even bei 10,2 %.** Heute tragen **68 von 299** Generierungen (23 %) Befunde, und
  §1+§10 machen 41 % davon aus.

Selbst wenn die beiden Dossiers nur die Hälfte ihrer Befundklasse verhindern, liegt der
Handel weit im Plus. Und die Messsonde (`prompt_parts`) zeigt danach, ob es eingetreten ist.

### 2c. Was das am Deckel ändert — weniger als gedacht, weil ich vorher aufgeräumt habe

Pflicht heute an `recipe.generator`: **18.521 Z.** (mengen_defaults 7.446 · §4 2.630 · §6 2.609
· §3 2.459 · §2 1.968 · Erstellungs-Dossier 1.409). Deckel **19.000** — 479 Zeichen Luft.

**Beim Messen ist mir aufgefallen, dass davon 1.953 Zeichen gar keine Regel sind.** Jedes
gesplittete §-Dossier trägt einen identischen Provenienz-Vorspann („*Regelwerk Basisrezepte
(verbindlich, Domain `03_KUECHE/…`). Pflicht-Referenz bei Recipe-Migration (Skripte 200-208)…*"),
der dem Modell von Vault-Python-Skripten erzählt. Der Titel trägt die Herkunft schon. Ist raus
(`DossierText::ohneVorspann`, in diesem Deploy) — gegen alle 598 Docs geprüft: **kein §, keine
Tabellenzeile verloren, jeder Rest ein echtes Suffix des Originals**.

| | vorher | nachher |
|---|---|---|
| Pflicht `recipe.generator` | 18.521 | **16.568** |
| + §1.0–1.2 und §10 (auch bereinigt) | — | **≈ 23.400** |
| Deckel nötig | 19.000 | **24.000** (statt 27.000) |
| Deckel `vk.generator` | 27.500 | **31.000** (statt 34.000) |

Der Vorspann-Strip hat also zwei Drittel der nötigen Ausweitung schon bezahlt.

### 2d. Der stille Ballast, den ich beim Messen gefunden habe

An `target_key='recipe'` — also am **Präfix**, das *alle 22* `recipe.*`-Prompts mitschlucken —
hängen zwei `discovery`-Bindungen:

| Dossier | Zeichen | wo es hingehört |
|---|---|---|
| `geschmacksbalance` | 10.670 | `recipe.geschmack`, `recipe.sensorik` |
| `produktion-arbeitszeit-und-personenminuten` | 7.089 | `recipe.production_depth`, `recipe.steps`, `recipe.equipment` |

Bei 479 Zeichen Restluft kommen die beiden als **Anschnitt oder gar nicht** an: die Sonde
meldet `dropped = 16.952`. Ein Tabellen-Anschnitt ist kein Wissen, nur Kosten — dasselbe
Muster wie bei `substitutionen`. Arbeitszeit-Wissen hat im Rezept-**Inhalt** ohnehin nichts zu
suchen; das ist Produktionsplanung.

**Zweiter Grund, gewichtiger:** W3-1 (Cache-Prefix) setzt voraus, dass an den Generatoren
**kein** `discovery`-Binding hängt — ein score-gegatetes Dossier macht den Block call-abhängig
und zerstört den stabilen Vorlauf. Mein eigener Prüf-Command hat das nie gesehen, weil er nur
`recipe.generator` prüfte und nicht das Präfix `recipe`. Das ist jetzt geschlossen (dieselbe
Präfix-Blindheit, die das MCP-Dossier 6.761 Z. überleben ließ).

**Vorschlag:** beide vom Präfix lösen und gezielt binden. Kostet nichts, räumt 16.952 Z.
Blindleistung, und macht W3-1 erst wahr.

---

## 3. Der Fund, der DEIN Entscheid ist — §2 ist kein Bug, sondern eine offene Frage

§2 steht im Prompt und hat 27 Befunde. Die Befunde nennen dreimal dasselbe:

```
zutat:Schalotten: frisch, Wuerfel 5 mm     (hart)
zutat:Petersilie glatt: frisch, gehackt    (hart)
```

Das sind keine Fehlschreibungen — das sind **echte GPs mit Schnittform im Namen**, die der
Matcher wählt, weil sie lexikalisch besser treffen als die Ganz-Form.

Gemessen (nur frische Schnittware, wo die frische Ganz-Form desselben Hauptzutat-Slugs
existiert; TK/konserviert als Convenience-Trigger nach §2 und Derivate nach §11.2
ausgeschlossen):

| Rez. | Schnittform-GP | vorhandene Ganz-Form |
|---|---|---|
| 27 | Schalotten: frisch, Wuerfel 5 mm | Schalotten: frisch, ganz |
| 18 | Champignons: frisch, Scheiben 3 mm | Champignons: frisch, ganz |
| 13 | Karotten: frisch, Stifte, Bio | Karotten: frisch, mini, gemischt |
| 10 | Petersilie glatt: frisch, gehackt | Petersilie glatt: frisch, ganz |
| 9 | Paprika: frisch, Wuerfel 30 mm | Paprika: frisch, gruen, ganz |
| 8 | Knollensellerie: frisch, Stifte 3 mm | Knollensellerie: frisch |
| … | 29 GPs insgesamt | |

**Zur Ehrlichkeit:** meine erste, grobe Zählung sagte „514 von 3.499 Rezepten". Die war
überzeichnet — sie zählte `Eiswuerfel: TK` (enthält „wuerfel", ist keine Schnittform),
TK-Convenience und Zesten-Derivate mit. Die verschärfte Zahl oben ist die belastbare.

**Warum das nicht ich entscheide:** `Schalotten: frisch, Wuerfel 5 mm` ist ein real kaufbares
Produkt. §2 verlangt die Rohform, **außer Convenience ist gewollt** — und ob sie gewollt ist,
ist eine Kalkulationsfrage: vorgeschnitten frisch kostet mehr EK und weniger Lohn.

### Und jetzt der eigentliche Befund: die Maschinerie ist FERTIG, die zwei Defaults widersprechen sich

Ich hatte hier zuerst „einen Regler bauen" vorgeschlagen. Beim Nachlesen: **der Regler
existiert, vollständig verdrahtet, bis in den Matcher.**

| Stelle | Stand |
|---|---|
| MCP/Leitplanken-Regler `convenience` | `from_scratch \| teil_convenience \| voll_convenience` — da, wird im Briefing extrahiert und in der Kaskade vererbt |
| `RecipeGeneratorService:125` | `teil_convenience` **und** `from_scratch` → `mode = sub_recipe_first` |
| `RecipeGeneratorService:126` | `$preferRaw = $convenience === 'from_scratch';` |
| `MatchHeuristics:425` | `$cut = ($preferRaw && $this->hasCutForm($tokens)) ? CUT_FORM_PENALTY : 0;` (`-2`) |

Es fehlt also **nichts** — die Schnittform-Strafe ist gebaut und wirkt. Sie ist nur **opt-in**:

- Der Code liest `$parameter['convenience'] ?? 'standard'`, und `'standard'` steht in **keinem**
  Enum. Aus der Planungsstelle kommt es nie dort an: `Planung\Index::REGLER_DEFAULT` setzt
  `'convenience' => ''` — ein leerer String ist nicht `null`, der `??`-Fallback greift also gar
  nicht, und `''` trifft weder `from_scratch` noch die `sub_recipe_first`-Liste. **Ohne
  ausdrücklich gesetzten Regler gibt es null Strafe auf Schnittform-GPs** — und der Regler ist
  im Standard-Zustand leer.
- `teil_convenience` dreht den Pool auf Sub-Rezepte, lässt `prefer_raw` aber **aus** — es
  bevorzugt also selbstgebaute Komponenten und nimmt trotzdem bereitwillig vorgeschnittene
  Ware.

**Das ist der ganze Konflikt in einer Zeile:** das Regelwerk macht die Rohform zur **Regel**
und Convenience zur **Ausnahme** („Convenience-Trigger"), der Code macht die Rohform zur
**Ausnahme** (`=== 'from_scratch'`) und Convenience zum Default. Deshalb beanstandet der
Critic 27× `hart`, was der Matcher regelkonform-nach-Code tut. Niemand hat sich verrechnet —
die beiden Defaults haben nie miteinander gesprochen.

**Drei Wege, meine Empfehlung zuerst:**

1. **Default umdrehen** (empfohlen): `$preferRaw = $convenience !== 'voll_convenience';`
   Eine Zeile, und sie bringt den Code auf die Aussage des Regelwerks — Rohform ist die Regel,
   Voll-Convenience die bewusste Ausnahme. Wirkung: Schnittform-GPs verlieren 2 Punkte, wo
   heute niemand steuert. **Die Konsequenz ist echt und deine:** in den ~29 betroffenen
   GP-Paaren sinkt der EK und der Lohnbedarf steigt. Bestandsrezepte bleiben unberührt (der
   Schalter wirkt nur bei Erzeugung); ein Remapping wäre ein eigener, getrennter Entscheid.
2. **`teil_convenience` nachziehen** (halber Schritt): `in_array($convenience,
   ['from_scratch','teil_convenience'])` — dann folgt `prefer_raw` derselben Liste wie
   `sub_recipe_first`, was ohnehin konsistenter ist. Ändert nichts für nicht gesetzte Regler.
3. **Nichts tun** — dann bleiben 27 Befunde als Dauerrauschen, und §2 kostet jede Generierung
   1.968 Zeichen ohne Wirkung.

**Was ich schon gemacht habe** (in diesem Deploy): die *andere* Hälfte von §2. Wenn die breite
Abfrage die Rohform gar nicht mehr **findet**, findet sie sie jetzt wieder — »Karotten,
Brunoise« landet auf der Karotte statt auf einem Gemüsemix (`TokenEngine::isCutFormToken` +
Fallback unter der Mindestschwelle). Das ist unabhängig von 1–3: der Fallback stellt den
Kandidaten überhaupt erst zur Wahl, `prefer_raw` entscheidet dann, wer gewinnt. Ein GP, das die
Schnittform **wirklich trägt**, gewinnt weiterhin direkt — bewusst, weil das genau die Frage
oben ist.

---

## 4. Was ich NICHT vorschlage

- **§5 und §7 wieder binden.** §7 hat null Befunde, der Code erzwingt es. §5 hat 12 — aber die
  Ursache ist gefunden und behoben: der Alias zeigte auf »Wasser: Leitung«, einen Namen, den
  **kein GP trägt** (es ist `Leitungswasser: frisch`). Erst messen, ob die 12 damit weg sind.
- **§3/§4 entbinden.** Null bzw. ein Befund bei *gebundenem* § ist zweideutig (siehe 1).
- **Slug-Kanonisierung.** Der Kanon adressiert Sections per FK, nicht per Slug — kosmetisch.
- **`substitutionen`/`mengen_defaults` in den Kanon.** Zutatenabhängig; gehören ins
  Chunk-Retrieval (W1-5), nicht in jeden Prompt.

---

---

## 5. Nebenwirkung, schon umgesetzt: der Konformitäts-Prüfblock schrumpft mit

`ConformanceService::ladeRegelwerke()` sendet die Regelwerke **ungekappt** — richtig so, der
Critic prüft §-genau. Derselbe Vorspann-Strip wirkt dort aber auch, und `conformance.check` ist
mit **191 Calls × ⌀ 18.147 Tk = 3,16 M** der zweitgrößte Verbraucher:

| Präfix | vorher | nachher | |
|---|---|---|---|
| `regelwerk-basisrezepte-` | 51.030 | 42.347 | **−17,0 %** |
| `regelwerk-gp-` | 61.952 | 48.921 | **−21,0 %** |
| `regelwerk-la-` | 25.730 | 25.730 | −0,0 % |

≈ **−2.900 Token je Prüf-Call**, ~550k über 30 Tage. Dazu wird der Block **byte-identisch über
alle Calls** (`orderBy('slug')` + kein variabler Vorspann) und damit cache-prefix-fähig — das
ist wahrscheinlich der größere Teil des Gewinns.

**Zwei Korrekturen am Plan, damit niemand später auf falschen Zahlen aufbaut:**

1. Der Plan projizierte für W3-3 „18.102 → ~11.000 Tk" aus der Annahme „BR 46.011 normativ /
   11.331 referenz". Gemessen tragen die gesplitteten §-Dossiers **null Changelog-Zeichen**
   (die stecken in den ungesplitteten Originalen), und 21 % ihres Textes sind Tabellenzeilen —
   die aber **normativ** sind (§5-Default-GP-Tabelle, §8-Pflichtangaben-Matrix). Die
   Plan-Regel „tabellendominant → `kind=referenz`" hätte bindende Regeln verworfen. Der
   ehrliche Wert ist **−17…21 %**, nicht −39 %.
2. **Darum brauchte es die `knowledge_sections`-Tabelle hier nicht.** Nach Abzug von Changelog
   (null) und Referenz-Tabellen (normativ) bleibt als einziger nicht-normativer Anteil genau
   dieser Vorspann — 15 Zeilen Code statt Schema, Sectionizer und Re-Index. Die Migration liegt
   angelegt und leer da; sie verdient sich ihren Platz beim **Chunking** (`heading_path` im
   Vektor, W1-5), nicht hier. Die LA-Dossiers nutzen einen anderen Vorspann-Aufbau und bleiben
   bewusst unangetastet — lieber nicht bereinigen als in einem fremden Aufbau schneiden.

**Nebenbefund aus dem Bau, weil er lehrreich ist:** mein erster Strip nutzte `mb_substr` auf
einem `PREG_OFFSET_CAPTURE`-Offset. Der ist in **Bytes**, `mb_substr` zählt **Zeichen** — bei
»§«, »—«, Umlauten im Vorspann schnitt das mitten in den Paragraphen (»— Mengen, Einheiten &
Yield« statt »## §6 …«). Der Test hat es gefangen, nicht mein Nachlesen. Ein Strip ohne
Suffix-Invariante hätte still Regelwerk-Text abgeschnitten.

---

## Was ich brauche

1. **Kanon 2a+2c:** §1.0–1.2 und §10 als `always`, Deckel 19.000 → 24.000 (VK 27.500 → 31.000)? — *ja/nein*
2. **Ballast 2d:** die zwei `discovery`-Dossiers vom `recipe`-Präfix lösen und gezielt binden? — *ja/nein* (kostenneutral, macht W3-1 wahr)
3. **§2 / Convenience (Abschnitt 3):** Weg 1 (`prefer_raw` als Default, Voll-Convenience als
   Ausnahme), Weg 2 (nur `teil_convenience` nachziehen) oder Weg 3 (nichts)? — *fachlicher
   Entscheid mit EK-/Lohn-Folge, keine Eile*

1 und 2 kann ich sofort umsetzen und messen. 3 wartet auf dich — es ist eine Zeile Code und
eine Kalkulationsaussage.
