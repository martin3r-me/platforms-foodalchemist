{{-- M5-07 / D-7: Aroma-Netz — Quell-Rezept zentral, Anker-Ring, Verwandte außen; Brücken-Typen GL-10 --}}
<x-foodalchemist::modal name="aroma-netz" title="Aroma-Netz: {{ $zentrum['name'] ?? '' }}" size="max-w-5xl">
    @if($zentrum === null)
        <p class="text-xs text-gray-400">Kein Rezept gewählt.</p>
    @else
        <div x-data="{ alle: false, hov: null }" wire:key="aroma-netz-{{ $zentrum['id'] }}-{{ $vorschlaege }}">
            {{-- Kopf: Brücken-Toggle · Vorschlags-Modus · Bedien-Hint --}}
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 mb-2 text-[11px] text-gray-600 dark:text-gray-300" data-netz-kopf>
                <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                    <input type="checkbox" x-model="alle" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" data-netz-bruecken-toggle />
                    Alle Aroma-Brücken ({{ count($kanten) }})
                </label>
                <span class="text-gray-300 dark:text-gray-600">·</span>
                <label class="inline-flex items-center gap-1.5">
                    Pairing-Vorschläge pro Anker:
                    <select wire:model.live="vorschlaege" class="rounded-md border-gray-300 dark:border-white/10 dark:bg-gray-800 text-[11px] py-0.5" data-netz-vorschlaege>
                        <option value="0">aus (0)</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                    </select>
                </label>
                <span class="text-gray-400">Hover über Anker = dessen Brücken · Klick auf Rezept = öffnen</span>
            </div>

            <svg viewBox="0 0 900 640" class="w-full rounded-xl bg-black/[0.02] dark:bg-white/[0.03]" data-netz-svg>
                {{-- 1. Zentrum→Anker (Grundgerüst, dezent) --}}
                @foreach($anker as $a)
                    <line x1="{{ $cx }}" y1="{{ $cy }}" x2="{{ $a['x'] }}" y2="{{ $a['y'] }}"
                          class="stroke-gray-400" stroke-width="0.7" opacity="0.10" />
                @endforeach

                {{-- 2. Verwandte→gemeinsame Anker (grün = Basis, blau = VK) --}}
                @foreach($verwandte as $v)
                    @foreach($v['shared_anker_ids'] as $aid)
                        @if($pos->has($aid))
                            <line x1="{{ $v['x'] }}" y1="{{ $v['y'] }}" x2="{{ $pos[$aid]['x'] }}" y2="{{ $pos[$aid]['y'] }}"
                                  stroke="{{ $v['vk'] ? '#3b82f6' : '#22c55e' }}" stroke-width="0.8" opacity="0.14" />
                        @endif
                    @endforeach
                @endforeach

                {{-- 3. Aroma-Brücken Anker↔Anker (GL-10-Typen) — sichtbar bei Toggle oder Anker-Hover --}}
                @foreach($kanten as $k)
                    @if($pos->has($k['a']) && $pos->has($k['b']))
                        <line x1="{{ $pos[$k['a']]['x'] }}" y1="{{ $pos[$k['a']]['y'] }}"
                              x2="{{ $pos[$k['b']]['x'] }}" y2="{{ $pos[$k['b']]['y'] }}"
                              data-bruecke="{{ $k['typ'] }}"
                              :opacity="(alle || hov === {{ $k['a'] }} || hov === {{ $k['b'] }}) ? 0.85 : 0"
                              @switch($k['typ'])
                                  @case('klassisch') stroke="#d6409f" stroke-width="1.8" @break
                                  @case('modern') stroke="#d6409f" stroke-width="1.4" stroke-dasharray="2 4" @break
                                  @case('kontrast') stroke="#06b6d4" stroke-width="1.4" stroke-dasharray="2 4" @break
                                  @default stroke="#9ca3af" stroke-width="1" stroke-dasharray="5 4"
                              @endswitch
                              style="transition: opacity .15s" />
                    @endif
                @endforeach

                {{-- 4. Vorschläge (amber, gestrichelt an ihren Anker) --}}
                @foreach($vorschlaege_liste as $s)
                    @if($pos->has($s['anker_id']))
                        <line x1="{{ $pos[$s['anker_id']]['x'] }}" y1="{{ $pos[$s['anker_id']]['y'] }}" x2="{{ $s['x'] }}" y2="{{ $s['y'] }}"
                              stroke="#f59e0b" stroke-width="1.2" stroke-dasharray="3 3" opacity="0.6" />
                        <circle cx="{{ $s['x'] }}" cy="{{ $s['y'] }}" r="6" fill="#fcd34d" stroke="#f59e0b" data-netz-vorschlag="{{ $s['slug'] }}">
                            <title>{{ $s['display_de'] }} ({{ $s['typ'] }}) — Vorschlag über {{ $pos[$s['anker_id']]['slug'] }}</title>
                        </circle>
                        <text x="{{ $s['x'] }}" y="{{ $s['y'] + 16 }}" text-anchor="middle" font-size="9"
                              style="paint-order: stroke; stroke: rgba(255,255,255,.8); stroke-width: 2px;" class="fill-amber-600 dark:fill-amber-400">{{ $s['slug'] }}</text>
                    @endif
                @endforeach

                {{-- 5. Anker-Ring (rosa; Kern-Anker ★ mit Akzent-Ring) --}}
                @foreach($anker as $a)
                    <g @mouseenter="hov = {{ $a['id'] }}" @mouseleave="hov = null" class="cursor-default" data-netz-anker="{{ $a['slug'] }}">
                        <circle cx="{{ $a['x'] }}" cy="{{ $a['y'] }}" r="{{ $a['kern'] ? 11 : 9 }}"
                                fill="#f9a8d4" stroke="{{ $a['kern'] ? '#be185d' : '#ec4899' }}" stroke-width="{{ $a['kern'] ? 2.5 : 1 }}"
                                :opacity="(hov === null || hov === {{ $a['id'] }}) ? 1 : 0.45" style="transition: opacity .15s">
                            <title>{{ $a['display_de'] }}{{ $a['kern'] ? ' (Kern-Anker)' : '' }}</title>
                        </circle>
                        <text x="{{ $a['x'] }}" y="{{ $a['y'] + ($a['kern'] ? 25 : 22) }}" text-anchor="middle" font-size="10"
                              class="fill-gray-700 dark:fill-gray-200 {{ $a['kern'] ? 'font-semibold' : '' }}" style="paint-order: stroke; stroke: rgba(255,255,255,.8); stroke-width: 2.5px;">{{ $a['kern'] ? '★ ' : '' }}{{ $a['slug'] }}</text>
                    </g>
                @endforeach

                {{-- 6. Verwandte Rezepte (klickbar) --}}
                @foreach($verwandte as $v)
                    <g wire:click="zeigeRezept({{ $v['recipe_id'] }})" class="cursor-pointer" data-netz-rezept="{{ $v['recipe_id'] }}">
                        <circle cx="{{ $v['x'] }}" cy="{{ $v['y'] }}" r="9"
                                fill="{{ $v['vk'] ? '#93c5fd' : '#86efac' }}" stroke="{{ $v['vk'] ? '#2563eb' : '#16a34a' }}" stroke-width="1.5">
                            <title>{{ $v['name'] }} — {{ $v['shared'] }} gemeinsame Anker (von {{ $v['eigene_gesamt'] }})</title>
                        </circle>
                        <text x="{{ $v['x'] }}" y="{{ $v['y'] + 21 }}" text-anchor="middle" font-size="9.5"
                              style="paint-order: stroke; stroke: rgba(255,255,255,.8); stroke-width: 2.5px;" class="fill-gray-600 dark:fill-gray-300">{{ mb_strimwidth($v['name'], 0, 18, '…') }}</text>
                    </g>
                @endforeach

                {{-- 7. Quell-Rezept zentral (orange, groß) --}}
                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="32" fill="#fdba74" stroke="#ea580c" stroke-width="2.5" data-netz-zentrum>
                    <title>{{ $zentrum['name'] }}</title>
                </circle>
                <text x="{{ $cx }}" y="{{ $cy + 50 }}" text-anchor="middle" font-size="12" font-weight="600"
                      class="fill-gray-900 dark:fill-gray-100" style="paint-order: stroke; stroke: rgba(255,255,255,.85); stroke-width: 3px;">{{ mb_strimwidth($zentrum['name'], 0, 34, '…') }}</text>
            </svg>

            {{-- Legende (Referenz: Knoten-Typen | Brücken-Typen) --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-[10px] text-gray-500 dark:text-gray-400" data-netz-legende>
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-orange-300 border border-orange-600"></span> Quell-Rezept</span>
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-blue-300 border border-blue-600"></span> Gericht</span>
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-green-300 border border-green-600"></span> Basisrezept</span>
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-pink-300 border border-pink-600"></span> Pairing-Anker (★ Kern)</span>
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-amber-300 border border-amber-600"></span> Vorschlag über Anker</span>
                <span class="text-gray-300 dark:text-gray-600">|</span>
                <span class="inline-flex items-center gap-1"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#d6409f" stroke-width="2"/></svg> klassisch</span>
                <span class="inline-flex items-center gap-1"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#d6409f" stroke-width="1.5" stroke-dasharray="2 3"/></svg> modern</span>
                <span class="inline-flex items-center gap-1"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#06b6d4" stroke-width="1.5" stroke-dasharray="2 3"/></svg> kontrast</span>
                <span class="inline-flex items-center gap-1"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#9ca3af" stroke-width="1" stroke-dasharray="5 4"/></svg> sonstige</span>
            </div>
        </div>
    @endif
</x-foodalchemist::modal>
