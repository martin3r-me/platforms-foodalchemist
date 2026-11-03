{{-- Spec 94 · Gerichte eines Konfigurator-Konzepts (Haupt oder Add-on) mit Wording-Überschreibung je Gericht und KI-Wording
     im gewählten Schreibstil (wie „Speisen neu betexten“ im Foodbook-Kapitel). Erwartet $kz, $gerichtZeilen, $schreibstile. --}}
@php $zeiten = $gerichtZeilen[$kz->id] ?? []; $ovr = $kz->payload_json['wording_overrides'] ?? []; $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]'; @endphp
@if($zeiten !== [])
    <details class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] p-2" data-kf-wording="{{ $kz->id }}">
        <summary class="cursor-pointer {{ $leise }}">Gerichte und Wording im Kundenlink (nur für diesen Konfigurator überschreiben)</summary>
        @foreach($zeiten as $t => $zeilen)
            @if(count($zeiten) > 1)<p class="mt-2 font-medium">{{ $t === 'lunch' ? 'Tag' : 'Abend' }}</p>@endif
            <ul class="mt-1 flex flex-col gap-1">
                @foreach($zeilen as $z)
                    @if(($z['type'] ?? '') === 'gericht' && ! empty($z['slot_id']))
                        @php $eigen = isset($ovr[(string) $z['slot_id']]); @endphp
                        <li class="flex flex-wrap items-center gap-2" wire:key="kfw-{{ $kz->id }}-{{ $z['slot_id'] }}" style="padding-left: {{ ($z['einrueckung'] ?? 0) * 16 }}px">
                            <x-fa::input size="sm" class="flex-1 min-w-[14rem]" value="{{ $z['text'] }}" wire:change="wordingSetzen({{ $kz->id }}, {{ $z['slot_id'] }}, $event.target.value)"
                                aria-label="Wording" data-kf-wording-feld="{{ $z['slot_id'] }}" />
                            <x-fa::badge :tone="$eigen ? 'accent' : (($z['source'] ?? '') === 'name' ? 'warn' : 'neutral')">{{ $eigen ? 'nur hier' : ['konzept' => 'aus dem Concept', 'standard' => 'Gericht-Wording', 'name' => 'Wording fehlt'][$z['source'] ?? ''] ?? '' }}</x-fa::badge>
                            @if($eigen)<x-fa::button size="sm" variant="ghost" wire:click="wordingZuruecksetzen({{ $kz->id }}, {{ $z['slot_id'] }})" data-kf-wording-reset="{{ $z['slot_id'] }}">Zurücksetzen</x-fa::button>@endif
                        </li>
                    @elseif(in_array($z['type'] ?? '', ['header', 'paket'], true))
                        <li class="{{ $leise }} uppercase tracking-wide mt-1">{{ $z['text'] }}</li>
                    @endif
                @endforeach
            </ul>
        @endforeach
        <div class="mt-2 flex flex-wrap items-end gap-2 pt-2 border-t border-[var(--fa-line)]" data-kf-ki-wording="{{ $kz->id }}">
        <x-fa::field label="Schreibstil" for="kfw-stil-{{ $kz->id }}" class="flex-1 min-w-[12rem] max-w-xs">
            <x-fa::select id="kfw-stil-{{ $kz->id }}" wire:model.live="wordingStil" :options="$schreibstile" placeholder="Schreibstil wählen" />
        </x-fa::field>
        <x-foodalchemist::ki-action action="wordingGenerieren({{ $kz->id }})" variant="ai" icon="heroicon-o-sparkles" label="Speisen per KI betexten"
            :disabled="$wordingStil === ''" busy="Betextet …" class="shrink-0 mb-0.5"
            title="Schreibt die Gästetexte dieses Konzepts im gewählten Schreibstil neu. Gilt nur in diesem Konfigurator, das Concept bleibt unverändert." />
    </div>
    <p class="mt-2 {{ $leise }}">Bilder und Standard-Wording pflegst du im Concepter. Leeres Feld = zurück auf das Concept-Wording.</p>
    </details>
@endif
