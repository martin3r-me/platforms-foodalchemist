# Notiz an Martin — Semantic Layer aus FA-Sicht (Stand 2026-09-03)

Wir haben den Semantic Base Layer aus FoodAlchemist-Sicht durchgesehen. Vorab: das Konzept
trägt, und die Begründung in `konzept.md` (Attention-Gewichtung früher Tokens, Kompression,
Interference) deckt sich mit dem, was wir im FA-Wissensprogramm unabhängig gemessen haben.
Drei Punkte, die uns betreffen — alle klein, alle in deinem Core.

Kontext zu uns: FA fährt **71 Prompt-Keys** über `AiGatewayService::propose()`. Die
überwiegende Mehrheit sind **strukturierte JSON-Generatoren** (Rezept, Gericht, Konzept,
Foodbook, Klassifikatoren) mit hartem Umschlag-Contract. Eine Minderheit erzeugt echte
**Kundenprosa** (Wording, Kundentext, Präsentation, Sprachbefehl).

---

## 1. Der Layer ist untrennbar an eine Chat-Assistenten-Instruktion geklebt

`src/Tools/CoreContextTool.php:56-61`:

```php
$baseInstruction = 'Du bist ein Assistent, der den angegebenen Nutzer beim Bedienen der
Plattform unterstützt. … Antworte kurz, präzise und auf Deutsch. WICHTIG: Nutze die
verfügbaren Tools proaktiv … rufe das entsprechende Tool automatisch auf. …';

$systemPrompt = $layerBlock
    ? trim($layerBlock . "\n\n" . $baseInstruction)
    : $baseInstruction;
```

**Was uns daran trifft:** man kann den Layer nicht ohne diese Instruktion bekommen. Für unsere
JSON-Generatoren ist sie aktiv schädlich — „antworte kurz und präzise" und „rufe Tools
automatisch auf" widersprechen einem Prompt, der ein vollständiges JSON-Objekt ohne
Tool-Aufrufe liefern soll.

**Der interessante Teil:** das ist genau das Interference-Problem aus deinem eigenen
`konzept.md`, nur eine Ebene höher als dort beschrieben. Der Rollen-Konflikt sitzt nicht
zwischen Modul-Prompt und Layer — er sitzt **innerhalb der Injektion**: der Layer-Kanal
`perspektive` verankert eine Identität, und zwei Zeilen später sagt `$baseInstruction`
„Du bist ein Assistent". Das V1.1-Lint würde Modul-Prompts gegen den `negativ_raum` prüfen —
diese Kollision fände es nicht, weil sie im Core steht.

**Was wir gemacht haben:** in FA `with_context => false` gesetzt
(`AiGatewayService::propose()`), nach dem Muster, das der Core selbst nutzt
(`OpenAiService.php:1347` → `with_context => false, tools => false`). Damit fällt neben der
Instruktion auch der sekundengenaue `'Zeit: ' . $time`-Vorlauf weg
(`OpenAiService.php:1431`), der Prompt-Caching strukturell unmöglich machte — bei uns lag die
Cache-Quote vorher bei 0,35 %.

**Was wir bräuchten:** die Basis-Instruktion vom Layer trennen, damit ein Aufrufer den Prior
ohne die Chat-Rolle bekommen kann. Zwei getrennte Optionen statt einer, oder die Instruktion
als überschreibbarer Default. Solange sie verklebt sind, bleibt FA vollständig draußen — und
damit auch die Prosa-Prompts, die den Layer wirklich gebrauchen könnten.

**Angebot:** wenn du für V1.1 einen echten Konfliktfall zum Testen brauchst — wir haben einen,
inklusive Messung, was er gekostet hat.

---

## 2. `enabled_modules` ist zu grob für uns — und bei `production` wirkungslos

`enabled_modules` schaltet auf **Modul**-Ebene (`resolveFor($team, $module)`), und
`src/Tools/SemanticLayer/SetStatusTool.php:40` sagt:

> „Bei status=production wirkt der Layer auf ALLEN Modulen, unabhängig von enabled_modules."

**Was uns daran trifft:** FA ist kein Modul mit einer Aufgabe, sondern 71 Prompt-Keys mit
gegensätzlichen Anforderungen. Wir bräuchten den Layer bei `concept.wording`,
`foodbook.kundentext` und `voice.command` — und ausdrücklich **nicht** bei `recipe.generator`
oder den Klassifikatoren. Auf Modul-Ebene ist das alles oder nichts.

Bei `status=production` verschärft sich das: dann greift die Modul-Liste gar nicht mehr, und
unser einziger Ausweg bleibt `with_context => false` — was den Layer mitsamt allem anderen
wegwirft. Für uns heißt das: sobald ein Layer produktiv geht, sind wir gezwungen, ganz
draußen zu bleiben.

**Was wir bräuchten:** eine feinere Ebene unter dem Modul. Ein optionaler Kontext-Key, den der
Aufrufer mitgibt (bei uns wäre das der Prompt-Key), gegen den `enabled_modules` matchen kann —
gern mit Präfix-Semantik, damit `foodalchemist` weiter alles trifft und
`foodalchemist:concept.wording` gezielt. Das ist dieselbe Präfix-Logik, die wir bei unseren
Wissens-Bindings verwenden; sie hat sich als tragfähig erwiesen (mit einer Falle, siehe unten).

**Erfahrung dazu, ungefragt aber vielleicht nützlich:** wir hatten genau so eine
Präfix-Auflösung und darin einen stillen Bug — unser Prüfwerkzeug sah nur den vollen Key und
nie das Präfix, weshalb ein an das Präfix gebundenes Dossier monatelang in *jedem* Prompt
landete, ohne dass es jemand merkte. Falls du das baust: das Werkzeug, das den aufgelösten
Zustand anzeigt, muss dieselbe Präfix-Liste lesen wie der Resolver. Sonst ist der Zustand
unsichtbar, nicht falsch.

---

## 3. `prompt_cache_key` ist nicht durchleitbar

Der Payload wird an zwei Stellen explizit konstruiert — `OpenAiService.php:90-93` (non-stream)
und `:391-394` (stream) — jeweils nur mit `model`, `input`, `stream`, `max_output_tokens`.
Zusätzliche Optionen erreichen den Provider nicht, und `applySupportedSamplingParams` ist eine
geschlossene Whitelist (temperature/top_p/penalties/reasoning).

**Was uns daran trifft:** wir haben unser Message-Layout bewusst so gebaut, dass der statische
Vorlauf (Hüllen + verbindliches Regelwerk + Task) **byte-identisch** über alle Calls eines
Prompt-Keys ist — bei `recipe.generator` gemessen 24.718 Zeichen ≈ 8.200 Token, in zwei
aufeinanderfolgenden Calls identisch. Damit liegen wir klar über der 1024-Token-Schwelle des
impliziten Prefix-Caches, und Caching greift auf diesem Pfad nachweislich (zwei Calls mit
98 % bzw. 23 % `cached_tokens`). Ohne expliziten Schlüssel bleibt die Zuordnung aber dem
Provider überlassen, und wir sehen es nur statistisch.

**Was wir bräuchten:** eine Zeile im Payload-Bau, Wert vom Aufrufer, z. B.
`'prompt_cache_key' => $options['prompt_cache_key'] ?? null` (weglassen wenn null). Bei
gecachten Tokens zu 10 % des Preises ist das der billigste Hebel im ganzen System — bei
unserem heutigen Volumen (~17,9 M Input-Token in 30 Tagen) reden wir über eine relevante
Summe, und der Nutzen wächst mit jedem weiteren Modul, das strukturiert generiert.

**Ausdrücklich kein Blocker.** Unsere Reihenfolge ist unabhängig davon richtig, weil sie das
Statische vor das Variable stellt.

---

## Was wir NICHT von dir brauchen

Nur zur Abgrenzung, damit die Liste nicht länger klingt als sie ist: unser eigentliches
Problem ist Fachwissens-Volumen (Regelwerke, Dossiers, Retrieval) und liegt vollständig in
FA. Der Semantic Layer löst das nicht und soll es nicht — das ist eine andere Achse. Wir
brauchen von dir nur, dass wir ihn dort einschalten können, wo er passt.

Ein Beitrag ist uns dabei am wertvollsten, und der kostet dich nichts: die
Attention-Dilution-Begründung in `konzept.md` („zu langer Layer = die Gewichtung verteilt
sich, einzelne Instruktionen verlieren Einfluss", Optimum 150–200 Token). Wir schieben
6.300–10.000 Token Regelwerk in unsere Generator-Prompts und haben gemessen, dass ein
gebundener Paragraf trotzdem 27 Regelverstöße produziert, während zwei code-erzwungene
Paragrafen bei null liegen. Deine Erklärung passt darauf — und sie ist der Grund, warum wir
den nächsten Wissens-Block **messen statt einfach hinzufügen**.
