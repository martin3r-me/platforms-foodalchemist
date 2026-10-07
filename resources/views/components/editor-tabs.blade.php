{{--
    Spec 28 / E0.1: sticky Tab-Leiste für Voll-Editoren.

    Herausgelöst aus dem Master-Editor (Basisrezepte, `recipe-modal`). Vorher 3× copy-paste
    (Rezept · Gericht/VK · Concepter) plus eine vierte Variante ohne sticky (GP) — mit jeweils
    eigenen Kopien der drei Livewire/Alpine-Fallen. Die stecken jetzt EINMAL hier drin:

    1. Ein Alpine-Scope umspannt Leiste UND Panels. Ein Header/Body-Split desynct unter
       Livewire-Morph (Leiste in einem Scope, Panels in einem anderen → Klick tut nichts).
       Deshalb liegen die Panels im Slot dieses Bausteins, nicht daneben.
    2. `wire:key` erzwingt Element-Ersatz beim Datensatz-Wechsel. Alpine wertet x-data bei
       morphdom NICHT neu aus → ohne Key bleibt der aktive Tab „stale" (Rezept B öffnet auf
       dem Tab von Rezept A).
    3. `x-effect` setzt den Tab bei JEDEM Öffnen zurück (liest `open` aus dem Modal-Scope).
       Ohne das bleibt der zuletzt gewählte Tab beim erneuten Öffnen DESSELBEN Datensatzes
       stehen — der wire:key ersetzt das Element ja nur bei Wechsel.

    Panels bleiben absichtlich beim Aufrufer und alle im DOM (x-show, nicht @if):
    eingebettete Livewire-Kinder (Zutaten-Editor) dürfen nicht neu gemountet werden.

    Nutzung (Label `null` = Tab entfällt; Panels als x-show-Divs in den Slot):

        x-foodalchemist::editor-tabs  marker="rezept"  wire-key="rezept-tabs-{id|neu}"
            :init="$neu ? 'eigenschaften' : 'aufbau'"
            :tabs="['aufbau' => 'Aufbau', 'eigenschaften' => 'Stammdaten',
                    'feedback' => $neu ? null : 'Feedback']"
          → Slot:  div x-show="tab === 'aufbau'"  x-cloak class="pt-4 space-y-4"  …

    ACHTUNG beim Ergänzen dieses Kopfes: KEIN verschachteltes Kommentar-Ende und KEINE echten
    Component-Tags in Beispielen. Blade strippt Kommentare VOR der Component-Kompilierung — ein
    zweites Kommentar-Ende beendet den Block vorzeitig und das Beispiel wird real gerendert.

    Tab-Ordnung (Spec 28 / E1): links steht, was am häufigsten geändert wird — «Aufbau» vor
    «Stammdaten», «Notizen» zuletzt.

    Die Klassen stehen literal in dieser Datei, NICHT in Ui.php: Tailwind scannt nur
    `resources/views/**/*.blade.php` (Sandbox-`app.css`-@source), ein Token in Ui.php wäre
    im Kompilat nicht garantiert (Build-Falle, README §234).

    ZWEI MECHANIKEN, EINE LEISTE:
    · Alpine (Default) — Panels liegen alle im DOM, Umschalten ohne Server-Roundtrip. Richtig,
      wenn eingebettete Livewire-Kinder oder ungespeicherte Eingaben erhalten bleiben müssen.
    · Server (`action` + `active` gesetzt) — die Livewire-Komponente hält den Tab und rendert nur
      das aktive Panel. Richtig, wenn die Panels zu schwer sind, um alle gleichzeitig zu leben
      (Concepter: Coverage, Kohäsion, Picker). Der Baustein liefert dann NUR die Leiste; die
      Panel-Steuerung bleibt beim Aufrufer.
    Die Mechanik zu wechseln ist ein Verhaltens-Umbau, kein Design-Schritt — nicht nebenbei tun.
--}}
@props([
    'tabs' => [],                  {{-- ['key' => 'Label', …] — Einträge mit null/false entfallen --}}
    'init' => null,                {{-- Alpine-Modus: Start-Tab; default = erster Schlüssel --}}
    'wireKey' => null,             {{-- Pflicht bei wechselndem Datensatz (siehe Falle 2) --}}
    'marker' => null,              {{-- data-{marker}-tabs am Root + data-{marker}-tab je Button --}}
    'action' => null,              {{-- Server-Modus: Livewire-Methode, z. B. 'setTab' --}}
    'active' => null,              {{-- Server-Modus: aktiver Tab-Schlüssel aus der Komponente --}}
    'visitAction' => null,         {{-- Alpine-Modus: beim ersten Besuch schwere Inhalte serverseitig freischalten --}}
    'visited' => [],               {{-- bereits serverseitig aufgebaute Reiter; verhindert Folge-Roundtrips --}}
    'counts' => [],                {{-- Server-Modus (optional): ['key' => int] → Zähler-Badge, nur wenn > 0 --}}
    'gesperrt' => false,           {{-- Spec 65: Lesemodus — Panels als <fieldset disabled>, Reiter bleiben klickbar --}}
])
@php
    $sichtbar = array_filter($tabs, fn ($label) => $label !== null && $label !== false && $label !== '');
    $startTab = $init ?? (array_key_first($sichtbar) ?? '');
    $serverModus = $action !== null;
    $leiste = 'flex flex-wrap gap-1 border-b border-[var(--fa-line)] sticky -top-4 z-20 -mx-6 px-6 bg-[var(--fa-surface)]'; // fa-pass: Token-Leiste, hell + Werkbank. -top-4 = Body-Padding (py-4): mit top-0 klebte sie 16 px tiefer und überlappte das erste Panel
    $knopf = 'h-11 px-3.5 text-[length:var(--fa-text-base)] font-medium border-b-2 -mb-px rounded-t-[var(--fa-radius-control)] transition-colors whitespace-nowrap focus-visible:-outline-offset-2';
    $an = 'border-[var(--fa-accent)] text-[var(--fa-accent)] font-semibold bg-[var(--fa-accent-soft)]';
    $aus = 'border-transparent text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
@endphp

@if($serverModus)
    {{-- Server-Modus: nur die Leiste. Kein Alpine-Scope — der aktive Tab kommt aus der Komponente. --}}
    <div class="{{ $leiste }} mt-1 py-2" data-fa-editor-tabs @if($marker) data-{{ $marker }}-tabs @endif>
        @foreach($sichtbar as $tabKey => $tabLabel)
            @php $cnt = $counts[$tabKey] ?? null; @endphp
            <button type="button" wire:click="{{ $action }}('{{ $tabKey }}')"
                    class="{{ $knopf }} inline-flex items-center {{ $active === $tabKey ? $an : $aus }}"
                    data-fa-editor-tab="{{ $tabKey }}" @if($marker) data-{{ $marker }}-tab="{{ $tabKey }}" @endif>{{ $tabLabel }}@if($cnt !== null && $cnt > 0)<span class="ml-1.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[length:var(--fa-text-sm)] font-semibold {{ $active === $tabKey ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'bg-[var(--fa-neutral-soft)] text-[var(--fa-ink-2)]' }}">{{ number_format($cnt, 0, ',', '.') }}</span>@endif</button>
        @endforeach
    </div>
@else
    <div @if($wireKey) wire:key="{{ $wireKey }}" @endif
         x-data="{ tab: @js($startTab), visited: @js(array_values($visited)) }"
         {{-- `open` existiert nur im Modal-Scope — Guard, damit der Baustein auch außerhalb eines
              Modals (Panel, Seite) ohne Alpine-Fehler läuft. --}}
         x-effect="if (typeof open !== 'undefined' && open) tab = @js($startTab)"
         data-fa-editor-tabs @if($marker) data-{{ $marker }}-tabs @endif>

        {{-- Eine einzige Lasche ist keine Navigation, sondern Rauschen (z. B. GP-Neuanlage, die
             nur „Allgemein" hat). Der Alpine-Scope bleibt trotzdem — die Panels binden an `tab`. --}}
        {{-- wire:ignore (2026-10-06): der erste Besuch eines Reiters ruft visitAction → Livewire-Morph
             setzt das class-Attribut auf den Server-Stand zurück, Alpines :class-Buchführung kippt →
             zwei Reiter gleichzeitig markiert, die blaue Lasche „springt" auf Aufbau. Die Leiste ist
             serverseitig statisch; ein Datensatz-Wechsel ersetzt sie ohnehin über wire:key. --}}
        @if(count($sichtbar) > 1)
            <div wire:ignore class="{{ $leiste }} -mt-4 pt-4">
                @foreach($sichtbar as $tabKey => $tabLabel)
                    <button type="button" @click="tab = @js($tabKey)@if($visitAction); if (! visited.includes(@js($tabKey))) { visited.push(@js($tabKey)); $wire.{{ $visitAction }}(@js($tabKey)); }@endif"
                            :class="tab === @js($tabKey) ? '{{ $an }}' : '{{ $aus }}'"
                            class="{{ $knopf }}"
                            data-fa-editor-tab="{{ $tabKey }}" @if($marker) data-{{ $marker }}-tab="{{ $tabKey }}" @endif>{{ $tabLabel }}</button>
                @endforeach
            </div>
        @endif

        {{-- Spec 65: im Lesemodus (keine eigene Bearbeitungssperre) sind alle Eingaben und Knöpfe der Panels aus;
             die Reiterleiste steht außerhalb und bleibt bedienbar. display:contents = kein eigener Kasten. --}}
        <fieldset @disabled($gesperrt) class="contents" data-fa-lesemodus="{{ $gesperrt ? '1' : '0' }}">
            {{ $slot }}
        </fieldset>
        {{-- Spec 65: Panels, die auch ohne „Bearbeiten“ bedienbar sind (z.B. KI-Feedback) — außerhalb des fieldsets,
             aber im selben Alpine-Scope (x-show="tab === …" wirkt weiter). --}}
        @isset($frei)
            {{ $frei }}
        @endisset
    </div>
@endif
