                            <label class="{{ $label }}">Kategorie</label>
                            <select wire:model="form.category" class="{{ $input }} w-full" data-wissen-kategorie>
                                @foreach($kategorien as $kat)
                                    <option value="{{ $kat->slug }}">{{ $kat->label }}</option>
                                @endforeach
                            </select>

                            {{-- Spec 52/H1: die Kategorie sagt WORUM, die Art sagt WIE benutzt werden darf. --}}
                            <label class="{{ $dt }} mt-2 block">Wissensart</label>
                            <select wire:model.live="form.art" class="{{ $input }} w-full" data-wissen-art>
                                <option value="">— noch nicht eingeordnet —</option>
                                @foreach(\Platform\FoodAlchemist\Services\Knowledge\Wissensart::LABELS as $wert => $artLabel)
                                    <option value="{{ $wert }}">{{ $artLabel }}</option>
                                @endforeach
                            </select>
                            <fieldset class="mt-3 space-y-2" data-wissen-geltung>
                                <legend class="{{ $dt }}">Gilt unter diesen Bedingungen</legend>
                                <p class="text-xs text-gray-500">Leere Achse: keine Einschränkung. Mehrere Werte mit Komma trennen. Alle ausgefüllten Achsen müssen passen.</p>
                                @foreach(\Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::ACHSEN as $axis => $axisLabel)
                                    <label class="block text-xs">{{ $axisLabel }}
                                        <input wire:model="form.geltung.{{ $axis }}" class="{{ $input }} w-full" placeholder="Nicht eingeschränkt" />
                                    </label>
                                @endforeach
                            </fieldset>
                            @if(($form['art'] ?? '') === 'datenwerk')
                                <fieldset class="mt-3 space-y-3" data-wissen-datenwerte>
                                    <legend class="{{ $dt }}">Strukturierte Datenwerte</legend>
                                    <p class="text-xs text-gray-500">Einzelwert: Minimum und Maximum gleich setzen. Bezugsgröße präzise angeben, etwa „Rohgewicht pro Portion“. Ohne passende Werte bleibt eine sichtbare Datenlücke.</p>
                                    @foreach(($form['datenwerte'] ?? []) as $index => $row)
                                        <div wire:key="datenwert-{{ $selectedId }}-{{ $index }}" class="border rounded p-2 space-y-2">
                                            @foreach(['kennzahl' => 'Kennzahl', 'min' => 'Minimum', 'max' => 'Maximum', 'einheit' => 'Einheit', 'bezug' => 'Bezugsgröße', 'quelle' => 'Quelle / Fundstelle'] as $field => $fieldLabel)
                                                <label class="block text-xs">{{ $fieldLabel }}<input wire:model="form.datenwerte.{{ $index }}.{{ $field }}" class="{{ $input }} w-full" /></label>
                                            @endforeach
                                            <details><summary class="text-xs cursor-pointer">Zusätzliche Bedingungen für diesen Wert</summary>
                                                @foreach(\Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::ACHSEN as $axis => $axisLabel)
                                                    <label class="block text-xs">{{ $axisLabel }}<input wire:model="form.datenwerte.{{ $index }}.geltung.{{ $axis }}" class="{{ $input }} w-full" /></label>
                                                @endforeach
                                            </details>
                                            <button type="button" wire:click="removeDatenwert({{ $index }})" class="text-xs text-red-600">Wert entfernen</button>
                                        </div>
                                    @endforeach
                                    <button type="button" wire:click="addDatenwert" class="text-xs text-violet-700">Datenwert hinzufügen</button>
                                </fieldset>
                            @endif

                            <p class="text-[10px] text-gray-500 mt-0.5">
                                <span class="font-medium">datenwerk</span> wird über Achsen aufgelöst, nicht gesucht ·
                                <span class="font-medium">ablauf</span> geht an Agenten (<code>ablauf.GET</code>) und
                                kommt in <span class="font-medium">keinen</span> Prompt.
                            </p>
