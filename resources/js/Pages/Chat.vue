<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { nextTick, ref } from 'vue';

const messages = ref([]);
const input = ref('');
const loading = ref(false);
const messagesContainer = ref(null);
const receiptText = ref('');
const receiptMerchant = ref('');
const receiptAmount = ref('');
const receiptLoading = ref(false);
const receiptImage = ref(null);
const receiptPreviewUrl = ref(null);
const showTextFallback = ref(false);

function getCsrfToken() {
    const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    if (metaToken) {
        return metaToken;
    }

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
        const response = await fetch('/chat', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
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

function onReceiptImageSelected(event) {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    if (receiptPreviewUrl.value) {
        URL.revokeObjectURL(receiptPreviewUrl.value);
    }

    receiptImage.value = file;
    receiptPreviewUrl.value = URL.createObjectURL(file);
}

function clearReceiptImage() {
    if (receiptPreviewUrl.value) {
        URL.revokeObjectURL(receiptPreviewUrl.value);
    }

    receiptImage.value = null;
    receiptPreviewUrl.value = null;
}

function canSubmitReceipt() {
    return receiptImage.value || receiptText.value.trim();
}

async function submitReceipt() {
    if (!canSubmitReceipt() || receiptLoading.value) {
        return;
    }

    const userMessage = receiptImage.value
        ? `[Receipt photo] ${receiptImage.value.name}`
        : `[Receipt text]\n${receiptText.value.trim().slice(0, 120)}`;

    messages.value.push({ role: 'user', content: userMessage });
    receiptLoading.value = true;
    await scrollToBottom();

    try {
        const formData = new FormData();

        if (receiptImage.value) {
            formData.append('image', receiptImage.value);
        }

        if (receiptText.value.trim()) {
            formData.append('text', receiptText.value.trim());
        }

        if (receiptMerchant.value.trim()) {
            formData.append('merchant', receiptMerchant.value.trim());
        }

        if (receiptAmount.value !== '' && receiptAmount.value !== null) {
            formData.append('amount', String(receiptAmount.value));
        }

        const response = await fetch('/receipt', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
        });

        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Failed to process receipt.');
        }

        if (payload.data?.extracted_text) {
            receiptText.value = payload.data.extracted_text;
        }

        messages.value.push({
            role: 'assistant',
            content: payload.data?.message || 'Receipt processed successfully.',
        });

        receiptMerchant.value = '';
        receiptAmount.value = '';
        clearReceiptImage();
    } catch (error) {
        messages.value.push({
            role: 'assistant',
            content: error.message || 'Something went wrong while processing the receipt.',
        });
    } finally {
        receiptLoading.value = false;
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
                    <p class="mt-1 text-sm text-gray-600">
                        Chat about spending, insights, and budgets — or upload a receipt photo below.
                    </p>
                </header>

                <section class="mb-4 rounded-lg border-2 border-indigo-200 bg-indigo-50 p-4">
                    <h2 class="text-base font-semibold text-indigo-900">Add Receipt</h2>
                    <p class="mt-1 text-sm text-indigo-800">
                        Upload a photo of your receipt. We extract merchant, amount, category, and date automatically.
                    </p>

                    <div class="mt-3">
                        <label
                            class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-indigo-300 bg-white px-4 py-6 hover:border-indigo-400"
                        >
                            <span class="text-sm font-medium text-indigo-700">Click to upload receipt photo</span>
                            <span class="mt-1 text-xs text-gray-500">JPEG, PNG, or WebP up to 5MB</span>
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="hidden"
                                :disabled="receiptLoading"
                                @change="onReceiptImageSelected"
                            />
                        </label>
                    </div>

                    <div v-if="receiptPreviewUrl" class="mt-3 flex items-start gap-3">
                        <img
                            :src="receiptPreviewUrl"
                            alt="Receipt preview"
                            class="h-32 w-auto rounded-md border border-gray-200 object-contain bg-white"
                        />
                        <div class="text-sm text-gray-700">
                            <p class="font-medium">{{ receiptImage?.name }}</p>
                            <button
                                type="button"
                                class="mt-2 text-xs text-red-600 hover:text-red-800"
                                @click="clearReceiptImage"
                            >
                                Remove photo
                            </button>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="mt-3 text-xs text-indigo-700 underline"
                        @click="showTextFallback = !showTextFallback"
                    >
                        {{ showTextFallback ? 'Hide manual text entry' : 'Or paste receipt text manually' }}
                    </button>

                    <textarea
                        v-if="showTextFallback"
                        v-model="receiptText"
                        rows="4"
                        placeholder="Starbucks&#10;Total: $12.45&#10;Date: 2026-06-01"
                        class="mt-2 w-full rounded-md border-gray-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        :disabled="receiptLoading"
                    />

                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <input
                            v-model="receiptMerchant"
                            type="text"
                            placeholder="Merchant override (optional)"
                            class="rounded-md border-gray-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            :disabled="receiptLoading"
                        />
                        <input
                            v-model="receiptAmount"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="Amount override (optional)"
                            class="rounded-md border-gray-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            :disabled="receiptLoading"
                        />
                    </div>

                    <button
                        type="button"
                        class="mt-3 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="receiptLoading || !canSubmitReceipt()"
                        @click="submitReceipt"
                    >
                        {{ receiptLoading ? 'Extracting & saving...' : 'Upload & Process Receipt' }}
                    </button>
                </section>

                <div
                    ref="messagesContainer"
                    class="mb-4 h-[50vh] space-y-3 overflow-y-auto rounded-lg border border-gray-200 bg-white p-4"
                >
                    <p v-if="messages.length === 0" class="text-sm text-gray-500">
                        Your chat history appears here. Ask a question or upload a receipt above.
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

                    <p v-if="loading || receiptLoading" class="text-sm text-gray-500">Assistant is thinking...</p>
                </div>

                <form class="flex gap-2" @submit.prevent="sendMessage">
                    <input
                        v-model="input"
                        type="text"
                        placeholder="Ask a finance question..."
                        class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        :disabled="loading || receiptLoading"
                        @keydown="onKeydown"
                    />
                    <button
                        type="submit"
                        class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="loading || receiptLoading || !input.trim()"
                    >
                        Send
                    </button>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
