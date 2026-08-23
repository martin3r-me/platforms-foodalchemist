{{--
    Geteilte Katalog-Picker-Hülle (rechte Spalte) für die Ausgabe-Editoren — Foodbook · Speisekarte
    (Produktion/Basisrezept ziehen in einer Folgerunde nach). Ein Look statt drei Kopien.
    Übernimmt Aside-Optik + Modus-Tab-Leiste aus dem Produktions-Muster (editor-ziele).

    Server-Modus: der aktive Modus lebt als Livewire-Property beim Aufrufer; `switch` ist der
    Methodenname zum Umschalten (wire:click), `active` je Modus ein bool. Der Body (Suche,
    Facetten, Kandidaten via <x-foodalchemist::katalog-row>) kommt als Default-Slot vom Aufrufer,
    weil die Daten je Editor unterschiedlich sind. Optionaler `hint`-Slot über dem Body (z. B. Ziel-Rubrik).

    marker → data-{marker}-katalog am Aside + data-{marker}-kat="{key}" je Tab (für Tests/Anker).
    Klassen literal (Tailwind-Scan, README §234).
--}}
@props(['marker' => null, 'modes' => [], 'switch' => null])
<aside {{ $attributes->merge(['class' => 'w-80 shrink-0 flex flex-col rounded-xl bg-gray-500/[0.07] border border-black/5 p-2.5 sticky top-4 self-start max-h-[75vh]']) }} @if($marker) data-{{ $marker }}-katalog @endif>
    @if(count($modes) > 1)
        <div class="flex items-center gap-1 text-[11px] font-semibold mb-2 shrink-0">
            @foreach($modes as $m)
                <button type="button" @if($switch) wire:click="{{ $switch }}('{{ $m['key'] }}')" @endif
                        class="flex-1 px-2 py-1 rounded-md {{ ($m['active'] ?? false) ? 'bg-violet-500/20 text-violet-700' : 'text-gray-500 hover:bg-black/[0.03]' }}"
                        @if($marker) data-{{ $marker }}-kat="{{ $m['key'] }}" @endif>{{ $m['label'] }}</button>
            @endforeach
        </div>
    @endif
    @isset($hint)
        <div class="mb-2 shrink-0">{{ $hint }}</div>
    @endisset
    <div class="min-h-0 flex-1 flex flex-col overflow-hidden">
        {{ $slot }}
    </div>
</aside>
