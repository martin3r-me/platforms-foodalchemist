{{-- Speiseplan-Vorschau = Aushang (Druck-Layout, read-only). Wochen-Grid Linie × Tag + Legende.
     Erwartet: $vorschau (SpeiseplanService::dokumentDaten). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($leg = $vorschau['legende'])

<div class="relative overflow-hidden {{ $card }} p-6" wire:key="sp-vorschau-{{ $vorschau['plan']->id }}">
    <div class="{{ $cardAccent }}"></div>

    <div class="text-center mb-4">
        <h2 class="text-lg font-semibold text-gray-900">{{ $vorschau['plan']->name }}</h2>
        <p class="text-xs text-gray-500 mt-0.5">{{ $vorschau['mahlzeitLabel'] ?? '' }} · {{ $vorschau['kwLabel'] ?? '' }}</p>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-xs border-collapse">
            <thead>
                <tr>
                    <th class="text-left align-bottom px-2 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-gray-500 border-b border-black/10">Linie</th>
                    @foreach($vorschau['tage'] as $t)
                        <th class="text-left align-bottom px-2 py-1.5 text-[10px] font-semibold uppercase tracking-wider text-gray-500 border-b border-black/10 whitespace-nowrap">{{ $t['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($vorschau['zeilen'] as $zeile)
                    <tr class="align-top">
                        <td class="px-2 py-2 border-b border-black/5">
                            <span class="inline-flex items-center gap-1.5 font-medium text-gray-800">
                                @if($zeile['color'])<span class="w-2 h-2 rounded-full" style="background: {{ $zeile['color'] }}"></span>@endif
                                {{ $zeile['linie'] }}
                            </span>
                        </td>
                        @foreach($vorschau['tage'] as $t)
                            <td class="px-2 py-2 border-b border-black/5 text-gray-700 min-w-[8rem]">
                                @forelse($zeile['zellen'][$t['ymd']] ?? [] as $e)
                                    <div class="py-0.5">
                                        {{ $e['name'] }}@if(!empty($e['codes']))<sup class="text-[9px] text-gray-400 ml-0.5">{{ implode(',', $e['codes']) }}</sup>@endif
                                    </div>
                                @empty
                                    <span class="text-gray-300">—</span>
                                @endforelse
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($vorschau['tage']) + 1 }}" class="px-2 py-8 text-center text-gray-400">Noch keine Belegung — oben „Bearbeiten" öffnen.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(count($leg['allergene']) || count($leg['zusatzstoffe']))
        <div class="mt-4 pt-3 border-t border-black/10 text-[10px] text-gray-500 leading-relaxed">
            @if(count($leg['allergene']))
                <div><span class="font-semibold uppercase tracking-wide text-gray-600">Allergene:</span>
                    @foreach($leg['allergene'] as $a)<span class="font-semibold text-violet-600">{{ $a['code'] }}</span> {{ $a['label'] }}@if(!$loop->last) · @endif @endforeach
                </div>
            @endif
            @if(count($leg['zusatzstoffe']))
                <div class="mt-1"><span class="font-semibold uppercase tracking-wide text-gray-600">Zusatzstoffe:</span>
                    @foreach($leg['zusatzstoffe'] as $z)<span class="font-semibold text-violet-600">{{ $z['code'] }}</span> {{ $z['label'] }}@if(!$loop->last) · @endif @endforeach
                </div>
            @endif
            <div class="mt-1 text-gray-400">Kennzeichnung nach LMIV/ZZulV, Vorsorgeprinzip (ALL-MAXIMAL); <span class="font-semibold text-violet-600">*</span> = Spuren möglich.</div>
        </div>
    @endif
</div>
