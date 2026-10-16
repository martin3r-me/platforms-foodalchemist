# Non-Food-Erkennung: Regeln mit Belegen und Kosten (16.10.2026)

Anlass: Die KI-Stufe der Non-Food-Erkennung kostete auf io 26,30 $ (629 Aufrufe à 20 Artikel, Tier B = Plattform-Default gpt-5.5, 743k Eingabe-, 753k Ausgabe-Tokens).
Ziel: weniger Artikel an die KI, kürzere Prompts und Antworten, ein eigenes günstiges Modell nur hierfür.

**Datenbasis der Zahlen:** der lokale Bestand (264.514 Lieferantenartikel aus dem WaWi-Import, 120 Lieferanten), lesend per SQL. io hat dieselbe Quelle plus die Necta-Nachholer;
die Größenordnungen gelten dort, die Einzelzahlen können leicht abweichen. Die Auswertung läuft über die echte Regelklasse (`NonFoodRegeln::bewerte`), nicht über eine Nachbildung.

## 1. Ergebnis der Regelstufe (alt → neu)

| Klasse | alt | neu | Differenz |
|---|---:|---:|---:|
| Non-Food (sicher, ohne KI) | 5.099 | 12.477 | +7.378 |
| unklar (geht an die KI) | 12.182 | 8.083 | −4.099 (−34 %) |
| Food (keine Zeile) | 247.233 | 243.954 | −3.279 |

Übergänge: unklar → Non-Food 4.049 · **Food → Non-Food 3.332** (bisher stillschweigend als Food geführt, also FALSCH) · unklar → Food 56 (Artikel mit GP-Zuordnung) · Non-Food → unklar 3 · Food → unklar 3.

## 2. Die Regeln und ihre Belege

**R1 — Food-Belege zweistufig.** Bisher zählte JEDE vorhandene Allergen-/Nährwert-/Deklarationszeile als „hat Food-Daten“. Gemessen: 139.011 Allergenzeilen, aber nur 76.029 mit einem Wert;
127.644 Nährwertzeilen, 88.584 mit Wert. Die Necta-/WaWi-Importe legen leere Zeilen an. Folge: 2.802 offensichtliche Non-Food-Artikel (starkes Wort + leere Zeile) gingen an die KI.
Neu entscheidet bei einem STARKEN Wort nur ein enger Beleg: GP-Zuordnung, ein Allergen ausdrücklich „enthalten/Spuren“, Nährwert > 0 (kcal, Eiweiß, Fett, Kohlenhydrate), Zutatenliste oder Alkohol.
Das weite Kriterium (irgendeine Zeile, plus GP) hält mehrdeutige Wörter bei Food-Artikeln weiter aus der KI. Hätte man das weite Kriterium überall durch das enge ersetzt, wären 3.549 Food-Artikel
(Hummer „mit Schale“, Spieße, Körbe) neu zur KI gegangen — deshalb nur an der einen Stelle.

**R2 — neue starke Wörter** (jeweils: Treffer gesamt · davon mit GP-Zuordnung · mit Zutatenliste · mit Nährwert; „Ware“ = Treffer an Lebensmittel):
- `windlicht(er)` 760 · 0 · 0 · 4 · `untersetzer` 156 · 0 · 0 · 0 · `kerzenhalter` 91 · 0 · 0 · 0 · `vase(n)` 501 · 1 · 2 · 2 (Deko & Tischkultur)
- `melamin` 233 · 0 · 0 · 0 · `porzellan` 143 · 0 · 0 · 4 · `tablett(s)` 387 · 0 · 0 · 0 · `schüssel(n)` 401 · 0 · 0 · 0 · `servierplatte(n)` 84 · 0 · 0 · 0 (Geschirr & Ausstattung, seit #433)
- `edelstahl` 1.060 · 0 · 0 · 144 · `kunststoff` 398 · 0 · 0 · 51 · `schneidebrett(er)`/`schneidbrett(er)` 191 · 0 · 0 · 32 · `backform(en)` 87 · 0 · 0 · 9 · `lampe(n)` 153 · 0 · 0 · 1 (Küchenbedarf)
- `bagasse` 161 · 0 · 0 · 0 (Einweg & Verpackung)
Gegenproben, ALLE Treffer gesichtet (30 an Lebensmittel-Lieferanten, 120 an Großlieferanten mit Food-Wort in der Bezeichnung, z. B. Tortenplatte, Brotkorb, Kaffeekanne): kein Fehlgriff außer zwei, die behoben sind:
- **„Tablette Guanaja 70 % Cacao“ / „Tablette Tainori“ (Delta Fleisch)**: Schokolade. `tablett` trifft nur ganze Wörter (`tablett`, `tabletts`), nicht `Tablette`. Test.
- **„KOPPERT VENUS VASE“** (Henning Broscheit): Kresse in der Glasvase. Ausnahme-Wörter `koppert`, `kresse`, `microgreens` entwerten ein starkes Wort; der Artikel wird unklar (die KI entscheidet). `vase` steht dafür zusätzlich bei den mehrdeutigen Wörtern. Test.
Wörter mit Beleg GEGEN eine Aufnahme (bleiben mehrdeutig bzw. ohne Regel): `mehrweg` (Seitan-Gulasch/Diebels Alt „Mehrweg Glas“ sind Food), `palmblatt` („Palmblatt Poke Bowl“ von Achenbach ist Food), `holz`, `bambus`
(Bambussprossen), `geschenk` (Geschenksets mit Food: 33 Allergen-, 37 Nährwert-Treffer), `teller`/`schale`/`glas`/`becher`/`korb`/`platte`/`kiste`/`dose`/`beutel`/`deckel` (jeweils Food-Treffer mit GP bzw. Zutaten: z. B. „MM Teller Linsen“, „Kiste“ mit 61 GP-Zuordnungen).
`tablett`, `schüssel` und `untersetzer` verließen dafür die Liste der mehrdeutigen Wörter (gemessen: kein Treffer mit GP/Zutaten/Nährwert).

**R3 — Lieferantensortiment.** Reine Non-Food-Lieferanten sind klein (Bunzl 56, Franz Mensch 29, ETIVERA 16, Blitz Fritz 15, Pacovis 5 Artikel) und wurden von der Branchen-Regel schon erfasst;
mit R1 gehen die 7 ETIVERA-Artikel mit leerer Zeile jetzt ebenfalls direkt auf Non-Food. Eine eigene Sortiments-Regel würde < 100 Artikel sparen und wird nicht gebaut.

## 3. Kosten: Prompt, Antwort, Stapel

Gemessen über den echten Gateway (mitschreibender Fake-Provider, kein API-Aufruf) an 300 echten unklaren Bezeichnungen. Kalibrierung: alt 4.113 Zeichen je 20er-Aufruf ↔ 1.181 geloggte Eingabe-Tokens (743k/629) → 3,48 Zeichen je Token.

| | alt | neu |
|---|---:|---:|
| Artikel je Aufruf | 20 | 50 |
| Eingabe je Aufruf (Zeichen / Tokens) | 4.113 / ≈ 1.181 | 5.733 / ≈ 1.647 |
| **Eingabe je Artikel (Tokens)** | **≈ 59** | **≈ 33** (−44 %) |
| sichtbare Antwort je Urteil (Zeichen) | 139 (mit Grund) | 83 (ohne), Grund nur < 0,8 |
| Ausgabe je Artikel (Tokens, geloggt / geschätzt) | 60 (geloggt) | ≈ 30–48 (Schätzung, siehe unten) |

Änderungen am Prompt: Lieferant einmal je Stapel (`lieferanten` + Index `l`), `verkaufsname` nur wenn abweichend, Antwort nur `{id, nonfood, unterkategorie, konfidenz}`, `grund` nur bei Konfidenz < 0,8;
Begründung in der Tabelle: „KI: sicher“ / „KI: unsicher“ / Satz. `max_tokens` 3000 → 6000 (50 Artikel; Reasoning-Modelle brauchen Luft).

**Ausgabe-Schätzung** (ehrlich): der sichtbare Teil sinkt von ≈ 40 auf ≈ 24 Tokens je Artikel (+ ≈ 4 für den Grund bei ~10 % Unsicheren). Die geloggten 60 Tokens/Artikel enthalten außerdem interne Denk-Tokens
(≈ 20); ob sie bei 50er-Stapeln und knapper Antwort sinken, lässt sich nur messen. Obergrenze deshalb ≈ 48, plausibel ≈ 30. **Vor dem ganzen Bestand: Messlauf mit `--ki-limit=100` (2 Stapel) und die geloggten Zahlen ansehen.**

**Kosten je Artikel** (Listenpreise der FA-Preistabelle, USD je Mio Tokens):
| Modell | Eingabe/Ausgabe | alt (59 in / 60 out) | neu (33 in / 30–48 out) |
|---|---|---:|---:|
| gpt-5.5 (heute) | 5,00 / 30,00 | 0,0021 $ | 0,0011–0,0016 $ |
| gpt-5-mini | 0,25 / 2,00 | — | 0,00007–0,00010 $ |
| gpt-5.6-luna | 0,20 / 1,20 | — | 0,00004–0,00006 $ |
| gpt-4.1-mini (ohne Denk-Tokens, ≈ 28 out) | 0,40 / 1,60 | — | ≈ 0,00006 $ |

**Hochrechnung für den ganzen unklaren Bestand** (alt 12.182 Artikel, neu 8.083):
- alt, gpt-5.5, 20er mit Begründung: **≈ 25,6 $** (bestätigt die geloggten 26,30 $ für 12.580 Artikel).
- neu, gpt-5.5: ≈ 8,6–12,9 $ (nur durch Regeln + knappere Antwort + 50er-Stapel).
- neu, gpt-5.6-luna: ≈ 0,3–0,5 $ · gpt-5-mini: ≈ 0,6–0,8 $ · gpt-4.1-mini: ≈ 0,5 $.
Qualitätsprüfung vor der Umstellung: dieselben 200 Artikel mit gpt-5.5 und dem günstigen Modell laufen lassen und die Abweichungen in der Prüfliste ansehen (die Schwelle 0,8 schickt Unsicheres ohnehin zum Menschen).

## 4. Betrieb

- Nur noch die NIE von der KI versuchten Artikel einreihen: `php artisan foodalchemist:nonfood-erkennen --apply --ki` (neu: ohne `--ki-nochmal` werden Artikel mit `ki_versucht_at` übersprungen).
- Messlauf: `--ki-limit=100` (und optional `--stapel=25`); ganzer Bestand erst nach Dominiques Ja.
- Eigenes Modell NUR für diese Stufe: Env `FOODALCHEMIST_AI_MODEL_NONFOOD` (leer = Tier B wie bisher). Hinweis: im Auftrag „KI-Klassen“ geht dieser Schalter in die Klasse LEICHT auf (kein dritter Sonderweg).
