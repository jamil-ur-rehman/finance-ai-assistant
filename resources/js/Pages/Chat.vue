<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { nextTick, ref } from 'vue';

const messages = ref([]);
const input = ref('');
const loading = ref(false);
const messagesContainer = ref(null);

async function ensureCsrfCookie() {
    await fetch('/sanctum/csrf-cookie', {
        credentials: 'same-origin',
    });
}

function getCsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function scrollToBottom() {
    await nextTick();

    if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
}

async function sendMessage() {
    const message = input.value.trim();

    if (!message || loading.value) {
        return;
    }

    messages.value.push({ role: 'user', content: message });
    input.value = '';
    loading.value = true;
    await scrollToBottom();

    try {
        await ensureCsrfCookie();

        const response = await fetch('/api/chat', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({ message }),
        });

        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Failed to get a response.');
        }

        messages.value.push({
            role: 'assistant',
            content: payload.data?.message || 'No response message received.',
        });
    } catch (error) {
        messages.value.push({
            role: 'assistant',
            content: error.message || 'Something went wrong while sending your message.',
        });
    } finally {
        loading.value = false;
        await scrollToBottom();
    }
}

function onKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}
</script>

<template>
    <AuthenticatedLayout>
        <div class="py-6">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <header class="mb-4 border-b border-gray-200 pb-4">
                    <h1 class="text-2xl font-semibold text-gray-900">Finance Assistant</h1>
                    <p class="mt-1 text-sm text-gray-600">Ask about spending, insights, or budgets.</p>
                </header>

                <div
                    ref="messagesContainer"
                    class="mb-4 h-[60vh] space-y-3 overflow-y-auto rounded-lg border border-gray-200 bg-white p-4"
                >
                    <p v-if="messages.length === 0" class="text-sm text-gray-500">
                        Start a conversation by typing a message below.
                    </p>

                    <div
                        v-for="(message, index) in messages"
                        :key="index"
                        class="flex"
                        :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
                    >
                        <div
                            class="max-w-[85%] rounded-lg px-3 py-2 text-sm whitespace-pre-wrap"
                            :class="
                                message.role === 'user'
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-gray-100 text-gray-900'
                            "
                        >
                            {{ message.content }}
                        </div>
                    </div>

                    <p v-if="loading" class="text-sm text-gray-500">Assistant is thinking...</p>
                </div>

                <form class="flex gap-2" @submit.prevent="sendMessage">
                    <input
                        v-model="input"
                        type="text"
                        placeholder="Ask a finance question..."
                        class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        :disabled="loading"
                        @keydown="onKeydown"
                    />
                    <button
                        type="submit"
                        class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="loading || !input.trim()"
                    >
                        Send
                    </button>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
