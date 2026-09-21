<script setup>
import { appPath, apiPath } from '@/path';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { fetchDictionaryPronounceUrl, playPronunciation } from '@/lib/pronunciation';

const props = defineProps({
    prefill: { type: String, default: '' },
    lazy: { type: Boolean, default: false },
    emptyTitle: { type: String, default: 'Start a conversation' },
    emptyHint: { type: String, default: 'Try: “What does <em>outlet</em> mean?” then “<em>save this word</em>” — or use <strong>Save word</strong> on the reply.' },
    variant: { type: String, default: '' },
});

const messages = ref([]);
const draft = ref(props.prefill || '');
const sending = ref(false);
const ready = ref(false);
const agentLoading = ref(false);
const agentLoadingText = ref('Starting your word tutor session.');
const errorText = ref('');
const messagesEl = ref(null);

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

async function ensureAgent() {
    agentLoading.value = true;
    agentLoadingText.value = 'Starting your word tutor session.';
    try {
        const res = await fetch(apiPath('/api/word-chat/agent/ensure'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        if (!res.ok) {
            const payload = await res.json().catch(() => ({}));
            throw new Error(payload.message || 'Could not prepare chat.');
        }
        ready.value = true;
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not prepare chat.';
    } finally {
        agentLoading.value = false;
    }
}

async function loadHistory() {
    try {
        const res = await fetch(apiPath('/api/word-chat/messages'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) return;
        const payload = await res.json();
        const items = Array.isArray(payload.data) ? payload.data : [];
        messages.value = items.map((item) => ({
            id: item.id,
            role: item.role,
            content: item.content,
            lookup: item.metadata?.lookup || null,
            insights: item.insights || [],
            savedVocabulary: item.saved_vocabulary || null,
        }));
    } catch {
        // ignore history errors
    }
}

function scrollBottom() {
    nextTick(() => {
        if (messagesEl.value) {
            messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
        }
    });
}

watch(messages, scrollBottom, { deep: true });

async function sendMessage() {
    const text = draft.value.trim();
    if (!text || sending.value || !ready.value) return;

    sending.value = true;
    errorText.value = '';
    draft.value = '';
    messages.value.push({ role: 'user', content: text });
    const assistant = { role: 'assistant', content: '', streaming: true, lookup: null, insights: [] };
    messages.value.push(assistant);
    const assistantIndex = messages.value.length - 1;

    try {
        const res = await fetch(apiPath('/api/word-chat/messages'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ text }),
        });
        const payload = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(payload.message || 'Could not send message.');
        }

        if (payload.data?.lookup) {
            messages.value[assistantIndex].lookup = payload.data.lookup;
        }

        const streamUrl = payload.data?.stream_url;
        if (!streamUrl) {
            throw new Error('Missing stream URL.');
        }

        await consumeStream(streamUrl, assistantIndex);
    } catch (error) {
        messages.value[assistantIndex].streaming = false;
        messages.value[assistantIndex].content =
            error instanceof Error ? error.message : 'Something went wrong.';
        messages.value[assistantIndex].role = 'error';
    } finally {
        sending.value = false;
    }
}

async function consumeStream(streamUrl, assistantIndex) {
    const res = await fetch(streamUrl, {
        headers: { Accept: 'text/event-stream' },
        credentials: 'same-origin',
    });
    if (!res.ok || !res.body) {
        throw new Error('Stream failed.');
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let fullText = '';

    while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });

        let sep;
        while ((sep = buffer.indexOf('\n\n')) !== -1) {
            const block = buffer.slice(0, sep);
            buffer = buffer.slice(sep + 2);
            let event = 'message';
            let data = '';
            for (const line of block.split('\n')) {
                if (line.startsWith('event:')) event = line.slice(6).trim();
                if (line.startsWith('data:')) data += line.slice(5).trim();
            }
            if (!data) continue;
            let payload = null;
            try {
                payload = JSON.parse(data);
            } catch {
                continue;
            }

            if (event === 'assistant' && typeof payload.text === 'string') {
                fullText += payload.text;
                messages.value[assistantIndex].content = stripJsonFence(fullText);
            }
            if (event === 'result' && typeof payload.text === 'string') {
                fullText = payload.text;
                messages.value[assistantIndex].content = stripJsonFence(fullText);
            }
            if (event === 'lookup' && payload.lookup) {
                messages.value[assistantIndex].lookup = payload.lookup;
            }
            if (event === 'insights' && Array.isArray(payload.items)) {
                messages.value[assistantIndex].insights = payload.items;
            }
            if (event === 'vocab_saved' && payload.vocabulary) {
                messages.value[assistantIndex].savedVocabulary = payload.vocabulary;
            }
            if (event === 'saved' && payload.assistant_message) {
                const saved = payload.assistant_message;
                messages.value[assistantIndex].content = saved.content || stripJsonFence(fullText);
                messages.value[assistantIndex].id = saved.id;
                if (Array.isArray(saved.insights)) {
                    messages.value[assistantIndex].insights = saved.insights;
                }
                if (saved.saved_vocabulary) {
                    messages.value[assistantIndex].savedVocabulary = saved.saved_vocabulary;
                }
            }
            if (event === 'error') {
                throw new Error(payload.message || 'Stream error.');
            }
        }
    }

    messages.value[assistantIndex].streaming = false;
    messages.value[assistantIndex].content =
        stripJsonFence(messages.value[assistantIndex].content || fullText);
}

function stripJsonFence(text) {
    return String(text || '')
        .replace(/```json[\s\S]*?```/gi, '')
        .trim();
}

async function saveWord(word) {
    const res = await fetch(apiPath('/api/vocabularies'), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ word }),
    });
    if (!res.ok) {
        const payload = await res.json().catch(() => ({}));
        throw new Error(payload.message || 'Could not save word.');
    }
    return res.json();
}

async function pronounce(word) {
    await playPronunciation({
        word,
        fetchAudioUrl: () => fetchDictionaryPronounceUrl(word),
    });
}

onMounted(async () => {
    if (!props.lazy) {
        await ensureAgent();
        await loadHistory();
    } else {
        ready.value = true;
    }
});

defineExpose({ ensureAgent, loadHistory });
</script>

<template>
    <div
        class="word-chat"
        :class="{
            [`word-chat--${variant}`]: !!variant,
            'is-agent-error': !!errorText && !ready,
        }"
    >
        <div class="word-chat-agent-loading" :hidden="!agentLoading">
            <div class="word-chat-agent-loading-card">
                <p class="word-chat-agent-loading-title">Preparing chat…</p>
                <p class="muted word-chat-agent-loading-text">{{ agentLoadingText }}</p>
            </div>
        </div>

        <div ref="messagesEl" class="word-chat-messages" role="log" aria-live="polite" aria-relevant="additions">
            <div class="word-chat-messages-inner">
                <div v-if="messages.length === 0" class="word-chat-empty">
                    <p class="word-chat-empty-title">{{ emptyTitle }}</p>
                    <p class="muted" v-html="emptyHint" />
                </div>

                <div
                    v-for="(message, index) in messages"
                    :key="message.id || index"
                    class="word-chat-bubble"
                    :class="{
                        'is-user': message.role === 'user',
                        'is-assistant': message.role === 'assistant',
                        'is-error': message.role === 'error',
                        'is-streaming': message.streaming,
                    }"
                >
                    <div v-if="message.lookup" class="word-chat-lookup">
                        <div class="word-chat-lookup-head">
                            <span class="word-chat-lookup-word">{{ message.lookup.word }}</span>
                            <button
                                type="button"
                                class="btn-icon word-chat-lookup-pronounce"
                                aria-label="Pronounce"
                                @click="pronounce(message.lookup.word)"
                            >🔊</button>
                        </div>
                        <p v-if="message.lookup.phonetic" class="word-chat-lookup-phonetic">{{ message.lookup.phonetic }}</p>
                    </div>

                    <div class="word-chat-bubble-body">{{ message.content }}</div>

                    <div v-if="message.savedVocabulary" class="word-chat-vocab-saved">
                        Saved
                        <Link :href="`/home/vocab/${message.savedVocabulary.id}`">
                            {{ message.savedVocabulary.word }}
                        </Link>
                        to vocabulary
                    </div>

                    <div v-if="message.insights?.length" class="word-chat-insights">
                        <div
                            v-for="insight in message.insights"
                            :key="insight.id || insight.content"
                            class="word-chat-insight"
                        >
                            <div class="word-chat-insight-meta">
                                <span class="word-chat-insight-word">{{ insight.word }}</span>
                                <span class="word-chat-insight-type">{{ insight.insight_type }}</span>
                            </div>
                            <p class="word-chat-insight-content">{{ insight.content }}</p>
                            <div class="word-chat-insight-actions">
                                <button
                                    type="button"
                                    class="word-chat-insight-save"
                                    @click="saveWord(insight.word)"
                                >Save word</button>
                                <Link
                                    v-if="insight.vocabulary_id"
                                    class="word-chat-insight-practice"
                                    :href="`/home/quiz/play?autostart=1&vocabulary_id=${insight.vocabulary_id}`"
                                >Practice in quiz</Link>
                            </div>
                        </div>
                    </div>
                </div>

                <p v-if="errorText" class="muted" style="color:var(--danger,#c1121f)">{{ errorText }}</p>
            </div>
        </div>

        <form class="word-chat-composer" @submit.prevent="sendMessage">
            <label class="sr-only" for="word-chat-input">Message</label>
            <textarea
                id="word-chat-input"
                v-model="draft"
                class="form-control word-chat-input"
                maxlength="4000"
                rows="1"
                placeholder="Ask about a word or phrase..."
                autocomplete="off"
                :disabled="!ready || sending"
                @keydown.enter.exact.prevent="sendMessage"
            />
            <button
                type="submit"
                class="btn word-chat-send"
                aria-label="Send message"
                :disabled="!ready || sending || !draft.trim()"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="currentColor" d="M2.01 21 23 12 2.01 3 2 10l15 2-15 2z"/>
                </svg>
            </button>
        </form>
    </div>
</template>
