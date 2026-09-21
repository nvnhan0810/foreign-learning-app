@extends('admin.layout')

@section('title', $entry ? 'Sửa: '.$entry->word : 'Thêm từ')
@section('heading', $entry ? 'Sửa: '.$entry->word : 'Thêm từ / cụm từ')

@section('content')
@php
    $isJsonEditor = ($meaningsEditor ?? 'form') === 'json';
@endphp
<div class="card dictionary-form-card">
    @if ($entry)
        <p class="muted" style="margin-top:0">
            save_count={{ $entry->save_count }} ·
            curated={{ $entry->is_curated ? 'yes' : 'no' }} ·
            source={{ $entry->source }}
        </p>
    @else
        <p class="muted" style="margin-top:0">Curate từ hoặc cụm từ dùng chung cho lookup.</p>
    @endif

    <form method="POST" action="{{ $entry ? route('admin.dictionary.update', $entry) : route('admin.dictionary.store') }}" id="dictionary-form">
        @csrf
        @if ($entry)
            @method('PUT')
        @endif

        <div class="form-grid form-grid-3">
            <div class="form-group">
                <label for="word">Từ / cụm từ</label>
                <input type="text" name="word" id="word" value="{{ old('word', $entry->word ?? '') }}" placeholder="happy, get along with..." required>
            </div>
            <div class="form-group">
                <label for="phonetic">Phiên âm</label>
                <input type="text" name="phonetic" id="phonetic" value="{{ old('phonetic', $entry->phonetic ?? '') }}" placeholder="/ˈhæpi/">
            </div>
            <div class="form-group">
                <label for="audio_url">Audio URL</label>
                <input type="url" name="audio_url" id="audio_url" value="{{ old('audio_url', $entry->audio_url ?? '') }}">
            </div>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label for="entry_synonyms">Đồng nghĩa (cấp từ)</label>
                <input type="text" name="entry_synonyms" id="entry_synonyms" value="{{ old('entry_synonyms', $entrySynonymsText) }}" placeholder="joyous, glad — cách nhau bởi dấu phẩy">
            </div>
            <div class="form-group">
                <label for="entry_antonyms">Trái nghĩa (cấp từ)</label>
                <input type="text" name="entry_antonyms" id="entry_antonyms" value="{{ old('entry_antonyms', $entryAntonymsText) }}" placeholder="sad, unhappy — cách nhau bởi dấu phẩy">
            </div>
        </div>

        <div class="meanings-header">
            <h3>Nghĩa</h3>
            <div class="meanings-header-actions">
                <div class="meanings-mode-toggle" role="group" aria-label="Chế độ chỉnh sửa nghĩa">
                    <button type="button" class="btn btn-sm {{ $isJsonEditor ? 'btn-secondary' : '' }}" id="meanings-mode-form" data-meanings-mode="form" aria-pressed="{{ $isJsonEditor ? 'false' : 'true' }}">Form</button>
                    <button type="button" class="btn btn-sm {{ $isJsonEditor ? '' : 'btn-secondary' }}" id="meanings-mode-json" data-meanings-mode="json" aria-pressed="{{ $isJsonEditor ? 'true' : 'false' }}">JSON</button>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" id="copy-meanings-prompt">Copy prompt AI</button>
                <button type="button" class="btn btn-secondary btn-sm" id="add-meaning">+ Thêm nghĩa</button>
            </div>
        </div>
        <p class="muted" id="meanings-form-hint">Examples: mỗi dòng một câu. Đồng/trái nghĩa: cách nhau bởi dấu phẩy.</p>
        <p class="muted{{ $isJsonEditor ? '' : ' is-hidden' }}" id="meanings-json-hint">Dán JSON array meanings (hoặc {"meanings":[...]}). Dùng “Copy prompt AI” để nhờ AI xuất đúng format.</p>

        @error('meanings_json')
            <p class="form-error">{{ $message }}</p>
        @enderror
        @error('meanings')
            <p class="form-error">{{ $message }}</p>
        @enderror

        <input type="hidden" name="meanings_editor" id="meanings-editor" value="{{ $meaningsEditor ?? 'form' }}">

        <div id="meanings-list" class="{{ $isJsonEditor ? 'is-hidden' : '' }}">
            @php
                $meanings = old('meanings', $formMeanings);
            @endphp
            @foreach ($meanings as $i => $meaning)
                <div class="meaning-card">
                    <div class="meaning-card-top">
                        <span class="muted">Nghĩa {{ $i + 1 }}</span>
                        <button type="button" class="btn btn-sm btn-danger" data-remove-meaning>Xóa</button>
                    </div>
                    <div class="form-grid meaning-pos-def">
                        <div class="form-group">
                            <label>Loại từ (POS)</label>
                            <input type="text" name="meanings[{{ $i }}][part_of_speech]" value="{{ $meaning['part_of_speech'] ?? '' }}" placeholder="noun, verb, phrase...">
                        </div>
                        <div class="form-group form-span-grow">
                            <label>Định nghĩa</label>
                            <textarea name="meanings[{{ $i }}][definition]" rows="2" {{ $isJsonEditor ? '' : 'required' }}>{{ $meaning['definition'] ?? '' }}</textarea>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Examples</label>
                        <textarea name="meanings[{{ $i }}][examples_text]" rows="2" placeholder="They get along with each other well.">{{ $meaning['examples_text'] ?? '' }}</textarea>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Đồng nghĩa</label>
                            <input type="text" name="meanings[{{ $i }}][synonyms_text]" value="{{ $meaning['synonyms_text'] ?? '' }}" placeholder="clever, smart">
                        </div>
                        <div class="form-group">
                            <label>Trái nghĩa</label>
                            <input type="text" name="meanings[{{ $i }}][antonyms_text]" value="{{ $meaning['antonyms_text'] ?? '' }}" placeholder="dull, dim">
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="form-group meanings-json-editor{{ $isJsonEditor ? '' : ' is-hidden' }}" id="meanings-json-wrap">
            <label for="meanings-json">Meanings JSON</label>
            <textarea name="meanings_json" id="meanings-json" rows="16" spellcheck="false" {{ $isJsonEditor ? 'required' : '' }}>{{ old('meanings_json', $meaningsJson ?? '[]') }}</textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Lưu (đánh dấu curated)</button>
            <a href="{{ route('admin.dictionary.index') }}" class="btn btn-secondary">Quay lại</a>
        </div>
    </form>
</div>

<template id="meaning-template">
    <div class="meaning-card">
        <div class="meaning-card-top">
            <span class="muted">Nghĩa mới</span>
            <button type="button" class="btn btn-sm btn-danger" data-remove-meaning>Xóa</button>
        </div>
        <div class="form-grid meaning-pos-def">
            <div class="form-group">
                <label>Loại từ (POS)</label>
                <input type="text" name="meanings[__INDEX__][part_of_speech]" placeholder="noun, verb, phrase...">
            </div>
            <div class="form-group form-span-grow">
                <label>Định nghĩa</label>
                <textarea name="meanings[__INDEX__][definition]" rows="2" required></textarea>
            </div>
        </div>
        <div class="form-group">
            <label>Examples</label>
            <textarea name="meanings[__INDEX__][examples_text]" rows="2"></textarea>
        </div>
        <div class="form-grid">
            <div class="form-group">
                <label>Đồng nghĩa</label>
                <input type="text" name="meanings[__INDEX__][synonyms_text]" placeholder="clever, smart">
            </div>
            <div class="form-group">
                <label>Trái nghĩa</label>
                <input type="text" name="meanings[__INDEX__][antonyms_text]" placeholder="dull, dim">
            </div>
        </div>
    </div>
</template>

<script type="application/json" id="meanings-ai-prompt-template">@json($meaningsAiPrompt ?? '')</script>

<script>
(function () {
    var MODE_FORM = 'form';
    var MODE_JSON = 'json';
    var list = document.getElementById('meanings-list');
    var template = document.getElementById('meaning-template');
    var addBtn = document.getElementById('add-meaning');
    var form = document.getElementById('dictionary-form');
    var editorInput = document.getElementById('meanings-editor');
    var jsonArea = document.getElementById('meanings-json');
    var jsonWrap = document.getElementById('meanings-json-wrap');
    var formHint = document.getElementById('meanings-form-hint');
    var jsonHint = document.getElementById('meanings-json-hint');
    var modeFormBtn = document.getElementById('meanings-mode-form');
    var modeJsonBtn = document.getElementById('meanings-mode-json');
    var copyPromptBtn = document.getElementById('copy-meanings-prompt');
    var wordInput = document.getElementById('word');
    var promptTemplateEl = document.getElementById('meanings-ai-prompt-template');
    var promptTemplate = '';

    try {
        promptTemplate = JSON.parse(promptTemplateEl.textContent || '""');
    } catch (e) {
        promptTemplate = '';
    }

    function currentMode() {
        return editorInput.value === MODE_JSON ? MODE_JSON : MODE_FORM;
    }

    function nextIndex() {
        return list.querySelectorAll('.meaning-card').length;
    }

    function setButtonActive(btn, active) {
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        if (active) {
            btn.classList.remove('btn-secondary');
        } else {
            btn.classList.add('btn-secondary');
        }
    }

    function splitCsv(text) {
        return String(text || '')
            .split(/[,;]+/)
            .map(function (part) { return part.trim(); })
            .filter(Boolean);
    }

    function splitLines(text) {
        return String(text || '')
            .split(/\r\n|\r|\n/)
            .map(function (line) { return line.trim(); })
            .filter(Boolean);
    }

    function readFormMeanings() {
        var cards = list.querySelectorAll('.meaning-card');
        var meanings = [];
        cards.forEach(function (card) {
            var definition = (card.querySelector('[name*="[definition]"]') || {}).value || '';
            definition = definition.trim();
            if (!definition) return;
            var pos = ((card.querySelector('[name*="[part_of_speech]"]') || {}).value || '').trim();
            meanings.push({
                part_of_speech: pos || null,
                definition: definition,
                examples: splitLines((card.querySelector('[name*="[examples_text]"]') || {}).value || ''),
                synonyms: splitCsv((card.querySelector('[name*="[synonyms_text]"]') || {}).value || ''),
                antonyms: splitCsv((card.querySelector('[name*="[antonyms_text]"]') || {}).value || '')
            });
        });
        return meanings;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function buildCardHtml(index, meaning) {
        var pos = meaning.part_of_speech == null ? '' : String(meaning.part_of_speech);
        var definition = meaning.definition == null ? '' : String(meaning.definition);
        var examples = Array.isArray(meaning.examples) ? meaning.examples.join('\n') : '';
        var synonyms = Array.isArray(meaning.synonyms) ? meaning.synonyms.join(', ') : '';
        var antonyms = Array.isArray(meaning.antonyms) ? meaning.antonyms.join(', ') : '';

        return '' +
            '<div class="meaning-card">' +
            '<div class="meaning-card-top">' +
            '<span class="muted">Nghĩa ' + (index + 1) + '</span>' +
            '<button type="button" class="btn btn-sm btn-danger" data-remove-meaning>Xóa</button>' +
            '</div>' +
            '<div class="form-grid meaning-pos-def">' +
            '<div class="form-group"><label>Loại từ (POS)</label>' +
            '<input type="text" name="meanings[' + index + '][part_of_speech]" value="' + escapeHtml(pos) + '" placeholder="noun, verb, phrase..."></div>' +
            '<div class="form-group form-span-grow"><label>Định nghĩa</label>' +
            '<textarea name="meanings[' + index + '][definition]" rows="2" required>' + escapeHtml(definition) + '</textarea></div>' +
            '</div>' +
            '<div class="form-group"><label>Examples</label>' +
            '<textarea name="meanings[' + index + '][examples_text]" rows="2">' + escapeHtml(examples) + '</textarea></div>' +
            '<div class="form-grid">' +
            '<div class="form-group"><label>Đồng nghĩa</label>' +
            '<input type="text" name="meanings[' + index + '][synonyms_text]" value="' + escapeHtml(synonyms) + '" placeholder="clever, smart"></div>' +
            '<div class="form-group"><label>Trái nghĩa</label>' +
            '<input type="text" name="meanings[' + index + '][antonyms_text]" value="' + escapeHtml(antonyms) + '" placeholder="dull, dim"></div>' +
            '</div></div>';
    }

    function writeFormMeanings(meanings) {
        if (!Array.isArray(meanings) || meanings.length === 0) {
            meanings = [{
                part_of_speech: null,
                definition: '',
                examples: [],
                synonyms: [],
                antonyms: []
            }];
        }
        var html = '';
        meanings.forEach(function (meaning, index) {
            html += buildCardHtml(index, meaning || {});
        });
        list.innerHTML = html;
    }

    function parseJsonMeanings(raw) {
        var decoded = JSON.parse(raw);
        var items = Array.isArray(decoded) ? decoded : (decoded && Array.isArray(decoded.meanings) ? decoded.meanings : null);
        if (!items) {
            throw new Error('JSON phải là mảng meanings hoặc object { "meanings": [...] }.');
        }
        return items.map(function (item) {
            item = item || {};
            var examples = Array.isArray(item.examples) ? item.examples : [];
            if (examples.length === 0 && typeof item.example === 'string' && item.example.trim()) {
                examples = [item.example.trim()];
            }
            return {
                part_of_speech: item.part_of_speech || null,
                definition: String(item.definition || '').trim(),
                examples: examples.filter(function (v) { return typeof v === 'string' && v.trim(); }).map(function (v) { return v.trim(); }),
                synonyms: (Array.isArray(item.synonyms) ? item.synonyms : []).filter(function (v) { return typeof v === 'string' && v.trim(); }).map(function (v) { return v.trim(); }),
                antonyms: (Array.isArray(item.antonyms) ? item.antonyms : []).filter(function (v) { return typeof v === 'string' && v.trim(); }).map(function (v) { return v.trim(); })
            };
        }).filter(function (item) { return item.definition; });
    }

    function syncFormToJson() {
        jsonArea.value = JSON.stringify(readFormMeanings(), null, 2);
    }

    function syncJsonToForm() {
        var meanings = parseJsonMeanings(jsonArea.value || '[]');
        if (meanings.length === 0) {
            throw new Error('Cần ít nhất một nghĩa có definition.');
        }
        writeFormMeanings(meanings);
    }

    function setMode(mode, opts) {
        opts = opts || {};
        var next = mode === MODE_JSON ? MODE_JSON : MODE_FORM;
        var prev = currentMode();

        if (next !== prev && !opts.skipSync) {
            try {
                if (next === MODE_JSON) {
                    syncFormToJson();
                } else {
                    syncJsonToForm();
                }
            } catch (err) {
                alert(err.message || 'Không thể chuyển chế độ.');
                return;
            }
        }

        editorInput.value = next;
        var isJson = next === MODE_JSON;
        list.classList.toggle('is-hidden', isJson);
        jsonWrap.classList.toggle('is-hidden', !isJson);
        formHint.classList.toggle('is-hidden', isJson);
        jsonHint.classList.toggle('is-hidden', !isJson);
        addBtn.classList.toggle('is-hidden', isJson);
        setButtonActive(modeFormBtn, !isJson);
        setButtonActive(modeJsonBtn, isJson);
        jsonArea.required = isJson;
        list.querySelectorAll('[name*="[definition]"]').forEach(function (el) {
            el.required = !isJson;
        });
        list.querySelectorAll('input, textarea').forEach(function (el) {
            el.disabled = isJson;
        });
        jsonArea.disabled = !isJson;
    }

    function buildPrompt() {
        var word = (wordInput.value || '').trim() || '{WORD}';
        if (promptTemplate && promptTemplate.indexOf('{WORD}') !== -1) {
            return promptTemplate.replace(/\{WORD\}/g, word);
        }
        if (promptTemplate) {
            return promptTemplate.replace(/Word \/ phrase: .*/, 'Word / phrase: ' + word);
        }
        return 'Return a JSON array of meanings for "' + word + '" with keys part_of_speech, definition, examples, synonyms, antonyms.';
    }

    addBtn.addEventListener('click', function () {
        if (currentMode() !== MODE_FORM) return;
        var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex()));
        list.insertAdjacentHTML('beforeend', html);
    });

    list.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-remove-meaning]');
        if (!btn) return;
        var cards = list.querySelectorAll('.meaning-card');
        if (cards.length <= 1) {
            alert('Cần ít nhất một nghĩa.');
            return;
        }
        btn.closest('.meaning-card').remove();
    });

    modeFormBtn.addEventListener('click', function () { setMode(MODE_FORM); });
    modeJsonBtn.addEventListener('click', function () { setMode(MODE_JSON); });

    copyPromptBtn.addEventListener('click', function () {
        var text = buildPrompt();
        var done = function () {
            var original = copyPromptBtn.textContent;
            copyPromptBtn.textContent = 'Đã copy';
            setTimeout(function () { copyPromptBtn.textContent = original; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                window.prompt('Copy prompt:', text);
            });
        } else {
            window.prompt('Copy prompt:', text);
            done();
        }
    });

    form.addEventListener('submit', function () {
        if (currentMode() === MODE_JSON) {
            list.querySelectorAll('input, textarea').forEach(function (el) {
                el.disabled = true;
            });
            jsonArea.disabled = false;
        }
    });

    setMode(currentMode(), { skipSync: true });
})();
</script>
@endsection
