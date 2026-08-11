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
    'visitAction' => null,         {{-- Alpine-Modus: optionale Livewire-Methode, die beim ersten Tab-Besuch lazy Inhalte freischaltet --}}
    'counts' => [],                {{-- Server-Modus (optional): ['key' => int] → Zähler-Badge, nur wenn > 0 --}}
])
@php
    $sichtbar = array_filter($tabs, fn ($label) => $label !== null && $label !== false && $label !== '');
    $startTab = $init ?? (array_key_first($sichtbar) ?? '');
    $serverModus = $action !== null;
    $leiste = 'flex gap-4 border-b border-black/5 sticky top-0 z-20 -mx-6 px-6 bg-white/90 backdrop-blur-xl shadow-md rounded-b-xl';
    $knopf = 'px-1 py-2 text-xs font-medium border-b-2 -mb-px transition-colors';
    $an = 'border-violet-500 text-violet-700';
    $aus = 'border-transparent text-gray-600 hover:text-gray-700';
@endphp

@if($serverModus)
    {{-- Server-Modus: nur die Leiste. Kein Alpine-Scope — der aktive Tab kommt aus der Komponente. --}}
    <div class="{{ $leiste }} mt-1 py-2" data-fa-editor-tabs @if($marker) data-{{ $marker }}-tabs @endif>
        @foreach($sichtbar as $tabKey => $tabLabel)
            @php $cnt = $counts[$tabKey] ?? null; @endphp
            <button type="button" wire:click="{{ $action }}('{{ $tabKey }}')"
                    class="{{ $knopf }} inline-flex items-center {{ $active === $tabKey ? $an : $aus }}"
                    data-fa-editor-tab="{{ $tabKey }}" @if($marker) data-{{ $marker }}-tab="{{ $tabKey }}" @endif>{{ $tabLabel }}@if($cnt !== null && $cnt > 0)<span class="ml-1.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-semibold {{ $active === $tabKey ? 'bg-violet-500/15 text-violet-700' : 'bg-black/[0.06] text-gray-500' }}">{{ number_format($cnt, 0, ',', '.') }}</span>@endif</button>
        @endforeach
    </div>
@else
    <div @if($wireKey) wire:key="{{ $wireKey }}" @endif
         x-data="{ tab: @js($startTab) }"
         {{-- `open` existiert nur im Modal-Scope — Guard, damit der Baustein auch außerhalb eines
              Modals (Panel, Seite) ohne Alpine-Fehler läuft. --}}
         x-effect="if (typeof open !== 'undefined' && open) tab = @js($startTab)"
         data-fa-editor-tabs @if($marker) data-{{ $marker }}-tabs @endif>

        {{-- Eine einzige Lasche ist keine Navigation, sondern Rauschen (z. B. GP-Neuanlage, die
             nur „Allgemein" hat). Der Alpine-Scope bleibt trotzdem — die Panels binden an `tab`. --}}
        @if(count($sichtbar) > 1)
            <div class="{{ $leiste }} -mt-4 pt-4">
                @foreach($sichtbar as $tabKey => $tabLabel)
                    <button type="button" @click="tab = @js($tabKey)@if($visitAction); $wire.{{ $visitAction }}(@js($tabKey))@endif"
                            :class="tab === @js($tabKey) ? '{{ $an }}' : '{{ $aus }}'"
                            class="{{ $knopf }}"
                            data-fa-editor-tab="{{ $tabKey }}" @if($marker) data-{{ $marker }}-tab="{{ $tabKey }}" @endif>{{ $tabLabel }}</button>
                @endforeach
            </div>
        @endif

        {{ $slot }}
    </div>
@endif
