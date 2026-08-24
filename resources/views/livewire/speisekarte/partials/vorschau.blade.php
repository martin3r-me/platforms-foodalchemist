{{-- Speisekarte-Vorschau (read-only Kundensicht) — dieselben Daten wie Dokument/Präsentation.
     Namen über die Wording-Kette (sauber, ohne interne [HG]-Marker), Preise brutto/netto, Fußnoten.
     Erwartet: $vorschau (SpeisekarteService::dokumentDaten). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($brutto = $vorschau['brutto'])
@php($brand = ($vorschau['branding']['color'] ?? '#7c3aed') ?: '#7c3aed')
@php($leg = $vorschau['legende'])

<div class="relative overflow-hidden {{ $card }} p-6 md:p-8" wire:key="sk-vorschau-{{ $vorschau['karte']->id }}">
    <div class="{{ $cardAccent }}"></div>

    @if($vorschau['branding']['logo'] ?? null)
        <div class="text-center mb-4"><img src="{{ $vorschau['branding']['logo'] }}" alt="" class="inline-block max-h-16 object-contain" /></div>
    @endif
    <h2 class="text-xl font-semibold text-center text-gray-900">{{ $vorschau['karte']->name }}</h2>
    <div class="mx-auto h-1 w-14 rounded my-3" style="background: {{ $brand }};"></div>
    @if($vorschau['branding']['cover'] ?? null)
        <div class="mb-5"><img src="{{ $vorschau['branding']['cover'] }}" alt="" class="w-full rounded-lg" /></div>
    @endif

    <div class="max-w-2xl mx-auto">
        @forelse($vorschau['rubriken'] as $rubrik)
            <div class="mb-5" @if($rubrik['depth'] > 0) style="margin-left: {{ $rubrik['depth'] }}rem" @endif>
                <h3 class="text-[13px] font-semibold uppercase tracking-wide border-b pb-1 mb-2" style="color: {{ $brand }}; border-color: {{ $brand }}2b;">{{ $rubrik['title'] }}</h3>
                @if($rubrik['claim'])<p class="text-xs italic text-gray-500 mb-2">{{ $rubrik['claim'] }}</p>@endif

                @php($prevVg = null)
                @foreach($rubrik['positionen'] as $pos)
                    {{-- Werkstrang M Phase D-Renderer: Wahl-Gruppe „A oder B" — „oder" zwischen aufeinanderfolgenden Positionen derselben variant_group_id. --}}
                    @if(($pos['variant_group_id'] ?? null) !== null && ($pos['variant_group_id'] ?? null) === $prevVg)
                        <div class="text-[11px] italic text-gray-400 text-center py-0.5">oder</div>
                    @endif
                    @if($pos['typ'] === 'header')
                        <div class="font-medium text-[11px] uppercase tracking-wide text-gray-500 mt-3">{{ $pos['name'] }}</div>
                    @elseif($pos['typ'] === 'text')
                        <p class="text-xs text-gray-500 italic py-0.5">{{ $pos['consumer_text'] ?: $pos['name'] }}</p>
                    @elseif($pos['typ'] === 'spacer')
                        <div class="h-2"></div>
                    @else
                        <div class="flex items-baseline gap-2 py-1">
                            <span class="text-gray-900">
                                {{ $pos['name'] }}@if(!empty($pos['codes']))<sup class="text-[9px] text-gray-400 ml-0.5">{{ implode(',', $pos['codes']) }}</sup>@endif
                            </span>
                            <span class="flex-1 border-b border-dotted border-gray-300 translate-y-[-2px]"></span>
                            <span class="tabular-nums text-gray-900 whitespace-nowrap">
                                @php($w = $brutto ? $pos['vk_brutto'] : $pos['vk_netto'])
                                @if($w !== null){{ number_format((float) $w, 2, ',', '.') }} €@endif
                            </span>
                        </div>
                        @if(!empty($pos['wein']))<div class="text-[11px] text-gray-400 -mt-0.5 mb-1">{{ implode(' · ', array_map('ucfirst', array_values($pos['wein']))) }}</div>@endif
                        @if($pos['consumer_text'])<div class="text-[11px] text-gray-500 -mt-0.5 mb-1">{{ $pos['consumer_text'] }}</div>@endif
                        @foreach(($pos['gaenge'] ?? []) as $gang)
                            <div class="text-[11px] text-gray-500" style="padding-left: {{ (($gang['einrueckung'] ?? 0) + 1) * 0.5 }}rem">{{ $gang['type'] === 'header' ? '— ' . $gang['text'] . ' —' : $gang['text'] }}</div>
                        @endforeach
                    @endif
                    @php($prevVg = $pos['variant_group_id'] ?? null)
                @endforeach
            </div>
        @empty
            <p class="text-center text-sm text-gray-400 py-8">Noch keine Rubriken — oben „Bearbeiten" öffnen und die Karte aufbauen.</p>
        @endforelse

        <div class="mt-6 text-[10px] text-gray-400">
            Alle Preise in Euro{{ $brutto ? ', inkl. ' . rtrim(rtrim(number_format($vorschau['mwstSatz'], 1, ',', '.'), '0'), ',') . ' % MwSt.' : ' (netto)' }}
        </div>

        @if(count($leg['allergene']) || count($leg['zusatzstoffe']))
            <div class="mt-3 pt-3 border-t border-black/10 text-[10px] text-gray-500 leading-relaxed">
                @if(count($leg['allergene']))
                    <div><span class="font-semibold uppercase tracking-wide text-gray-600">Allergene:</span>
                        @foreach($leg['allergene'] as $a)<span style="color: {{ $brand }}" class="font-semibold">{{ $a['code'] }}</span> {{ $a['label'] }}@if(!$loop->last) · @endif @endforeach
                    </div>
                @endif
                @if(count($leg['zusatzstoffe']))
                    <div class="mt-1"><span class="font-semibold uppercase tracking-wide text-gray-600">Zusatzstoffe:</span>
                        @foreach($leg['zusatzstoffe'] as $z)<span style="color: {{ $brand }}" class="font-semibold">{{ $z['code'] }}</span> {{ $z['label'] }}@if(!$loop->last) · @endif @endforeach
                    </div>
                @endif
                <div class="mt-1 text-gray-400">Kennzeichnung nach LMIV/ZZulV, Vorsorgeprinzip (ALL-MAXIMAL); <span class="font-semibold" style="color: {{ $brand }}">*</span> = Spuren möglich.</div>
            </div>
        @endif
    </div>
</div>
