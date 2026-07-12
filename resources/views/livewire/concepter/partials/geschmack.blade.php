{{-- Geschmacks-Profil (7 Achsen, read-only). Erwartet $geschmack (PairingService aggregatedTaste, Werte 0–1).
     Tokens ($card/$cardAccent) kommen aus dem einbindenden Partial (Ui::maps()). --}}
@php($tasteLabels = ['suess' => 'Süß', 'salzig' => 'Salzig', 'sauer' => 'Sauer', 'bitter' => 'Bitter', 'umami' => 'Umami', 'fettig' => 'Fett', 'scharf' => 'Scharf'])
@php($tasteVals = $geschmack ?? [])
@if(array_sum(array_map('floatval', $tasteVals)) > 0)
    <div class="relative overflow-hidden {{ $card }} mt-3">
        <div class="{{ $cardAccent }}"></div>
        <div class="px-5 py-4 space-y-2">
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Geschmacks-Profil</h3>
            <p class="text-[11px] text-gray-400">Gemittelte Geschmacks-Achsen der Kern-Anker (0–100 %). Grundlage: kuratierte Anker-Vektoren.</p>
            <div class="space-y-1.5 pt-1">
                @foreach($tasteLabels as $key => $label)
                    @php($pct = (int) round(((float) ($tasteVals[$key] ?? 0)) * 100))
                    <div class="flex items-center gap-2">
                        <span class="w-12 text-[11px] text-gray-500 dark:text-gray-400 shrink-0">{{ $label }}</span>
                        <div class="flex-1 h-1.5 rounded-full bg-black/[0.06] dark:bg-white/10 overflow-hidden">
                            <div class="h-full rounded-full bg-violet-500/70" style="width: {{ $pct }}%"></div>
                        </div>
                        <span class="w-7 text-right text-[10px] text-gray-400 tabular-nums">{{ $pct }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
