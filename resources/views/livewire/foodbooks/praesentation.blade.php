{{-- R3.2 (Block C) — Externe Kunden-Präsentation: schöne, EK-freie Web-Seite, Preise pro Person --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Präsentation" icon="heroicon-o-sparkles" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Foodbook', 'href' => route('foodalchemist.foodbooks.index'), 'icon' => 'book-open'],
            ['label' => 'Präsentation'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 pb-10" spacing="space-y-5">

        {{-- ── Hero ──────────────────────────────────────────────────────── --}}
        <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-violet-500/10 via-indigo-500/5 to-transparent border border-white/20 px-8 py-10 text-center">
            <div class="{{ $cardAccent }}"></div>
            <p class="text-[11px] uppercase tracking-[0.2em] text-violet-500 mb-2">Kulinarisches Angebot</p>
            <h1 class="text-3xl font-semibold tracking-tight text-gray-900">{{ $fb->label }}</h1>
            <p class="text-sm text-gray-600 mt-2">
                @if($customer){{ $customer }}@if(($kontakt ?? null) && $kontakt !== $customer) · {{ $kontakt }} @endif @endif
                @if($fb->jahr) · {{ $fb->jahr }} @endif
            </p>
            @if($fb->description)
                <p class="text-sm text-gray-600 mt-4 max-w-xl mx-auto leading-relaxed">{{ $fb->description }}</p>
            @endif
        </div>

        {{-- ── Kapitel ───────────────────────────────────────────────────── --}}
        @forelse($kapitel as $k)
            {{-- E8.3: depth-basierte Überschrift unter dem h1-Hero — Ebene 0 = h2, tiefer h3/h4/h5 (gekappt). --}}
            @php($hTag = 'h' . min(5, 2 + (int) ($k['depth'] ?? 0)))
            @php($istFormat = $k['ist_format'] ?? false)
            <div class="{{ $sectionCard }}" style="margin-left: {{ $k['depth'] * 20 }}px" wire:key="praes-{{ $k['anker'] }}">
                <div class="flex items-start justify-between gap-4 mb-3">
                    <{{ $hTag }} class="text-lg font-medium tracking-tight text-gray-900">{{ $k['title'] }}</{{ $hTag }}>
                    @if($istFormat && ($k['preis_range']['min'] ?? null) !== null)
                        <div class="text-right shrink-0">
                            <div class="text-lg font-semibold text-violet-600">
                                {{ $k['preis_range']['min'] == $k['preis_range']['max']
                                    ? number_format($k['preis_range']['min'], 2, ',', '.') . ' €'
                                    : number_format($k['preis_range']['min'], 2, ',', '.') . '–' . number_format($k['preis_range']['max'], 2, ',', '.') . ' €' }}
                            </div>
                            <div class="text-[10px] uppercase tracking-wider text-gray-400">pro Person</div>
                        </div>
                    @elseif(! $istFormat && $k['vk_pro_person'] > 0)
                        <div class="text-right shrink-0">
                            <div class="text-lg font-semibold text-violet-600">{{ number_format($k['vk_pro_person'], 2, ',', '.') }} €</div>
                            <div class="text-[10px] uppercase tracking-wider text-gray-400">pro Person</div>
                        </div>
                    @endif
                </div>

                @if($istFormat && ($k['claim'] ?? null))
                    <p class="text-sm italic text-violet-600 mb-2">„{{ $k['claim'] }}“</p>
                @endif

                {{-- Spec 03 · L2b: Hinführung des Kapitels — dieselbe Projektion wie im PDF. --}}
                @if(! empty($k['text']))
                    <p class="text-sm text-gray-600 whitespace-pre-line mb-3">{{ $k['text'] }}</p>
                @endif

                @if($istFormat)
                    {{-- Format-Kapitel (Phase C): Marken-Hero + Editionen als Showcase (Alternativen). --}}
                    @if($k['hero'] ?? null)
                        <img src="{{ $k['hero'] }}" alt="{{ $k['title'] }}" class="w-full max-h-72 object-cover rounded-xl border border-black/5 mb-4" />
                    @endif
                    @forelse($k['editionen'] as $ed)
                        <div class="mt-4">
                            <div class="flex items-baseline justify-between gap-3">
                                <p class="font-semibold text-gray-900">{{ $ed['name'] }}</p>
                                @if($ed['preis_pp'] !== null)<span class="text-sm font-medium text-violet-600 shrink-0">{{ number_format($ed['preis_pp'], 2, ',', '.') }} € p. P.</span>@endif
                            </div>
                            @if($ed['claim'] ?? null)<p class="text-xs italic text-violet-600">„{{ $ed['claim'] }}“</p>@endif
                            @if($ed['text'] ?? null)<p class="text-sm text-gray-600 whitespace-pre-line mt-0.5 mb-1">{{ $ed['text'] }}</p>@endif
                            @foreach($ed['gerichte'] as $g)
                                @if($g['type'] === 'paket' || $g['type'] === 'header')
                                    <p class="text-sm font-medium text-gray-700 mt-1" style="margin-left: 8px">{{ $g['text'] }}</p>
                                @else
                                    <p class="text-sm text-gray-600" style="margin-left: {{ 8 + $g['einrueckung'] * 14 }}px">· {{ $g['text'] }}</p>
                                @endif
                            @endforeach
                        </div>
                    @empty
                        <p class="text-xs text-gray-400">Noch keine Editionen im Format.</p>
                    @endforelse
                @else
                    {{-- Bild-Platzhalter (echte Gericht-/Hero-Bilder = spätere Iteration, #461 → Fotos-Plan) --}}
                    <div class="mb-4 rounded-xl border border-dashed border-violet-300/50 bg-violet-500/[0.03] py-8 text-center text-[11px] text-violet-400">
                        @svg('heroicon-o-photo', 'w-3.5 h-3.5 inline-block align-middle') Bild folgt
                    </div>

                    @forelse($k['bloecke'] as $b)
                        @php($istKonzept = in_array($b['type'], ['concept_ref', 'recipe_ref'], true))
                        @if($b['ist_header'])
                            <p class="font-semibold text-gray-800 mt-3 mb-1">{{ $b['label'] }}</p>
                        @else
                            {{-- Konzept-Titel als deutliche Zwischenüberschrift: fett + Luft nach oben, sonst verschwimmt er mit den Gericht-Zeilen (2026-07-21) --}}
                            <p class="font-semibold text-gray-900 {{ $istKonzept ? 'mt-4' : 'mt-2' }}">{{ $b['label'] }}</p>
                        @endif
                        @if($b['untertitel'] ?? null)
                            <p class="text-xs italic text-gray-500 mb-1">{{ $b['untertitel'] }}</p>
                        @endif
                        @foreach($b['gerichte'] ?? [] as $g)
                            @if($g['type'] === 'paket' || $g['type'] === 'header')
                                <p class="text-sm font-medium text-gray-700 mt-2" style="margin-left: 8px">{{ $g['text'] }}</p>
                            @else
                                <p class="text-sm text-gray-600" style="margin-left: {{ 8 + $g['einrueckung'] * 14 }}px">· {{ $g['text'] }}</p>
                            @endif
                        @endforeach
                    @empty
                        <p class="text-xs text-gray-400">—</p>
                    @endforelse
                @endif
            </div>
        @empty
            <div class="{{ $sectionCard }} text-center text-sm text-gray-500 py-8">Dieses Foodbook hat noch keine Inhalte.</div>
        @endforelse

        {{-- ── Preis-Fuß ─────────────────────────────────────────────────── --}}
        <div class="rounded-2xl bg-gray-900 text-white px-8 py-6 flex items-center justify-between gap-4">
            <div>
                <div class="text-[11px] uppercase tracking-wider text-white/50">Preis pro Person</div>
                <div class="text-2xl font-semibold">{{ number_format($gesamt['vk_pro_person'], 2, ',', '.') }} €</div>
            </div>
            @php($mwstSatz = ($mwst ?? null) ? (($mwst['default_satz'] ?? 'ermaessigt') === 'regulaer' ? ($mwst['regulaer'] ?? 19) : ($mwst['ermaessigt'] ?? 7)) : null)
            <div class="text-right text-[11px] text-white/60 max-w-xs leading-relaxed">
                Alle Preise netto <span>@if($mwstSatz !== null) zzgl. gesetzl. MwSt ({{ rtrim(rtrim(number_format((float) $mwstSatz, 1, ',', '.'), '0'), ',') }} %)@endif</span>.
                @if($stand ?? null)<br>Stand {{ $stand->format('d.m.Y') }}@endif
            </div>
        </div>

    </x-ui-page-container>
</x-ui-page>
