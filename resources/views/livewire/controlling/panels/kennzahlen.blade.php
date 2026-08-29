{{-- Kalkulations-Kennzahlen: die ausgerollten Kosten-Regeln, gegen die alles gerechnet wird.
     Gepflegt werden sie in den Einstellungen → Herstellkosten.

     Spec 32: war bis 2026-08-02 die Seite `/kalkulation`, die zusätzlich die Preissimulation
     trug. Die Simulation hat im Controlling einen eigenen Tab — hier wäre sie ein zweiter
     Einstiegspunkt in dieselbe Fläche. Seiten-Hülle entfällt, Titel trägt der Tab. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4" data-ctrl-kennzahlen>
        <div class="relative overflow-hidden {{ $card }} px-5 py-4">
            <div class="{{ $cardAccent }}"></div>
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h3 class="font-medium tracking-tight text-gray-900">Kalkulations-Kennzahlen</h3>
                    <p class="text-[11px] text-gray-500 max-w-2xl">Die aktuellen, ausgerollten Kosten-Regeln — Grundlage jeder Kalkulation und dieser Simulation. Bearbeitet werden sie unter <strong>Einstellungen → Herstellkosten</strong>.</p>
                    @if(!empty($betriebName))
                        <span class="mt-1.5 inline-flex items-center gap-1.5 rounded-full border border-violet-400/30 bg-violet-500/15 px-2.5 py-0.5 text-[11px] text-violet-200" data-ctrl-kennzahlen-betrieb>
                            <span class="w-1.5 h-1.5 rounded-full bg-violet-300"></span>
                            Werte für Betrieb <strong>{{ $betriebName }}</strong> — Fixkosten/Marge/Ziel-WE/Zuschlag/Break-even dieses Betriebs
                        </span>
                    @endif
                </div>
                <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'herstellkosten']) }}" class="{{ $btnGhost }}" wire:navigate>Regeln in den Einstellungen pflegen →</a>
            </div>

            {{-- Spec 32: die zwei Zielwerte, gegen die das Controlling misst, direkt hier setzen.
                 Das volle Zuschlagsschema bleibt in den Einstellungen — zwei Formulare auf
                 dieselben Spalten wären ein Pflege-Widerspruch. --}}
            <div class="flex flex-wrap items-end gap-3 mt-3 pt-3 border-t border-black/5" data-ctrl-ziele>
                <div>
                    <label class="block {{ $label }} mb-1">Ziel-Wareneinsatz %</label>
                    <input type="text" wire:model="zielWe" class="{{ $input }} !w-24" data-ctrl-ziel-we />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Zielmarge %</label>
                    <input type="text" wire:model="marge" class="{{ $input }} !w-24" data-ctrl-ziel-marge />
                </div>
                <button type="button" wire:click="zieleSpeichern" class="{{ $btnGhostXs }} text-violet-600" data-ctrl-ziele-speichern>Zielwerte speichern</button>
                @if(!empty($betriebName))<span class="text-[11px] text-amber-300">Setzt die <strong>Team</strong>-Zielwerte · Betrieb-Overrides in Einstellungen › Betriebe</span>@endif
                @if($meldung)<span class="text-[11px] text-emerald-700">{{ $meldung }}</span>@endif
                @error('zielWe')<span class="text-[11px] text-rose-700">{{ $message }}</span>@enderror
                @error('marge')<span class="text-[11px] text-rose-700">{{ $message }}</span>@enderror
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-2 mt-3">
                <div class="rounded-lg bg-black/[0.03] px-3 py-2"><div class="{{ $label }}">Zielmarge</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format((float) $regeln['marge_pct'], 1, ',', '.') }} %</div></div>
                <div class="rounded-lg bg-black/[0.03] px-3 py-2"><div class="{{ $label }}">Ziel-Wareneinsatz</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format((float) $zielWe, 1, ',', '.') }} %</div></div>
                <div class="rounded-lg bg-black/[0.03] px-3 py-2"><div class="{{ $label }}">Stundensatz</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format((float) $regeln['stundensatz'], 2, ',', '.') }} €</div></div>
                <div class="rounded-lg bg-black/[0.03] px-3 py-2"><div class="{{ $label }}">HK2-Zuschlag (eff.)</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format((float) $zuschlag, 1, ',', '.') }} %</div></div>
                <div class="rounded-lg bg-black/[0.03] px-3 py-2"><div class="{{ $label }}">Fixkosten / Monat</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format((float) $fixkostenMonat, 0, ',', '.') }} €</div></div>
                <div class="rounded-lg bg-black/[0.03] px-3 py-2" title="Σ Fixkosten/Monat ÷ Deckungsbeitragsquote (1 − Ziel-Wareneinsatz). Monatsumsatz, ab dem die Fixkosten gedeckt sind."><div class="{{ $label }}">Break-even / Monat</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ $fixkostenMonat > 0 ? number_format((float) $breakEven, 0, ',', '.') . ' €' : '—' }}</div></div>
            </div>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-2 text-[11px] text-gray-500">
                <span><span class="text-gray-600 font-medium">MwSt</span> regulär {{ rtrim(rtrim(number_format((float) $mwst['regulaer'], 1, ',', '.'), '0'), ',') }} % · ermäßigt {{ rtrim(rtrim(number_format((float) $mwst['ermaessigt'], 1, ',', '.'), '0'), ',') }} % · Standard {{ $mwst['default_satz'] === 'regulaer' ? 'regulär' : 'ermäßigt' }}</span>
                <span class="text-gray-300">·</span>
                <span>{{ count($regeln['schema']) }} aktive Zuschlagsblöcke</span>
                <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'kalkulation']) }}" class="text-violet-600 hover:underline" wire:navigate>MwSt / Verlust-Defaults pflegen →</a>
            </div>
            @if(count($regeln['schema']))
                <div class="flex flex-wrap gap-1 mt-2">
                    @foreach($regeln['schema'] as $b)
                        <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $b['label'] }}: {{ rtrim(rtrim(number_format((float) ($b['value'] ?? 0), 2, ',', '.'), '0'), ',') }}{{ str_starts_with((string) $b['type'], 'pct') ? ' %' : ($b['type'] === 'arbeitszeit' ? ' €/h' : ' €') }}</span>
                    @endforeach
                </div>
            @endif
        </div>

        <p class="text-[11px] text-gray-500 px-1 pt-1">
            Die gerichts- und mengenbezogene Kalkulation (HK1 → HK2 → VK-Vorschlag → Deckungsbeitrag) läuft im
            <a href="{{ route('foodalchemist.concepter.index') }}" class="text-violet-600 hover:underline" wire:navigate>Concepter</a>
            und je Einzelgericht in den
            <a href="{{ route('foodalchemist.verkauf.index') }}" class="text-violet-600 hover:underline" wire:navigate>Gerichten</a>.
            Die Kosten-Regeln pflegst du unter
            <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'herstellkosten']) }}" class="text-violet-600 hover:underline" wire:navigate>Einstellungen → Herstellkosten</a>.
        </p>
</div>
