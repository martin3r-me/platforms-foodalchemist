{{-- Spec 19 E5.2 — Leitstellen-Checkliste: 7 abgeleitete Arbeits-Schritte (Bedarf→Preise)
     als klickbare Chips (offen/teil/erledigt). Klick springt via Alpine-Event-Bus (`fb-goto`)
     auf Tab + Anker; die Root des Cockpits (`x-data`) hört darauf. Die Foodbook-Freigabe/
     „Versand" ist NIE Teil dieser Liste (UX 1) — das ist der Phasen-Stepper daneben.
     Erwartet: $checkliste (list<array{key,nr,label,status,tab,anker,hinweis?}>). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusStil = [
    'erledigt' => 'bg-emerald-500/10 border-emerald-500/30 text-emerald-700 hover:bg-emerald-500/15',
    'teil'     => 'bg-amber-500/10 border-amber-500/30 text-amber-700 hover:bg-amber-500/15',
    'offen'    => 'bg-black/[0.03] border-black/10 text-gray-500 hover:bg-black/[0.06]',
])
@php($statusPunkt = ['erledigt' => 'bg-emerald-500', 'teil' => 'bg-amber-500', 'offen' => 'bg-gray-300'])

@if(! empty($checkliste))
    <div class="flex items-center gap-1 flex-wrap" data-leitstelle-checkliste>
        <span class="text-[10px] text-gray-500 uppercase tracking-wider mr-1">Schritte</span>
        @foreach($checkliste as $s)
            <button type="button"
                    @click="$dispatch('fb-goto', { tab: @js($s['tab']), anker: @js($s['anker']) })"
                    class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] border transition-colors {{ $statusStil[$s['status']] ?? $statusStil['offen'] }}"
                    title="{{ $s['hinweis'] ?? $s['label'] }}"
                    data-checkliste-schritt="{{ $s['key'] }}" data-status="{{ $s['status'] }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $statusPunkt[$s['status']] ?? $statusPunkt['offen'] }}"></span>
                <span class="tabular-nums opacity-60">{{ $s['nr'] }}</span>
                <span class="font-medium">{{ $s['label'] }}</span>
            </button>
            @if(! $loop->last)<span class="text-gray-300 text-[10px]">›</span>@endif
        @endforeach
    </div>
@endif
