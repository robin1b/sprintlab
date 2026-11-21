<script setup lang="ts">
import { Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import ChatComposer from '@/components/chat/ChatComposer.vue';
import { useNow } from '@/composables/useNow';
import { formatMessageTime } from '@/lib/time';
import { chat, dashboard } from '@/routes';
import type { ChatColleague, ChatMessage } from '@/types/sprint';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Chat', href: chat() }],
    },
});

const props = defineProps<{
    colleagues: ChatColleague[];
    selected: string;
    messages: ChatMessage[];
    can_send: boolean;
    send_blocked_reason: string | null;
    awaiting_reply: boolean;
    max_characters: number;
}>();

// "selected" is polled too: on a bare /chat it is the newest conversation.
usePoll(5_000, {
    only: [
        'colleagues',
        'selected',
        'messages',
        'awaiting_reply',
        'can_send',
        'send_blocked_reason',
    ],
});

const page = usePage();
const hasSprint = computed(() => page.props.sprint !== null);
const now = useNow(60_000);

const current = computed(
    () => props.colleagues.find((c) => c.key === props.selected) ?? null,
);

// On a phone only one pane shows: the list, or the open conversation.
const showConversation = ref(false);

const scroller = ref<HTMLElement | null>(null);

function scrollToNewest(): void {
    void nextTick(() => {
        if (scroller.value) {
            scroller.value.scrollTop = scroller.value.scrollHeight;
        }
    });
}

onMounted(scrollToNewest);
// The typing line also grows the conversation, so it scrolls too.
watch(
    () => [props.selected, props.messages.length, props.awaiting_reply],
    scrollToNewest,
);
</script>

<template>
    <Head title="Chat" />

    <div class="flex h-[calc(100svh-3rem)] min-h-0 flex-1">
        <aside
            class="min-h-0 w-full flex-col overflow-y-auto border-border bg-card md:flex md:w-72 md:shrink-0 md:border-r"
            :class="showConversation ? 'hidden' : 'flex'"
        >
            <h2
                class="border-b border-border px-4 py-3 font-mono text-xs text-muted-foreground"
            >
                colleagues
            </h2>
            <ul class="divide-y divide-border">
                <li v-for="colleague in colleagues" :key="colleague.key">
                    <Link
                        :href="chat(colleague.key)"
                        preserve-scroll
                        preserve-state
                        class="flex items-center gap-3 border-l-2 px-4 py-3 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                        :class="
                            hasSprint && colleague.key === selected
                                ? 'border-primary bg-accent'
                                : 'border-transparent'
                        "
                        @click="showConversation = true"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-secondary font-mono text-xs"
                        >
                            {{ colleague.name.slice(0, 2).toUpperCase() }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center justify-between gap-2">
                                <span class="truncate font-medium">
                                    {{ colleague.name }}
                                </span>
                                <span
                                    v-if="colleague.unread > 0"
                                    class="rounded-sm border border-primary px-1.5 font-mono text-xs text-primary"
                                >
                                    <span aria-hidden="true">{{
                                        colleague.unread
                                    }}</span>
                                    <span class="sr-only">
                                        {{ colleague.unread }} unread
                                    </span>
                                </span>
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ colleague.role }}
                            </p>
                            <p
                                v-if="colleague.last_message"
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{ colleague.last_message }}
                            </p>
                        </div>
                    </Link>
                </li>
            </ul>
        </aside>

        <div
            v-if="!hasSprint"
            class="min-h-0 flex-1 items-center justify-center p-6 md:flex"
            :class="showConversation ? 'flex' : 'hidden'"
        >
            <p class="max-w-xs text-center text-muted-foreground">
                No messages yet. Your team will reach out when your sprint
                starts.
                <Link
                    :href="dashboard()"
                    class="rounded-sm text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Start one on the overview.
                </Link>
            </p>
        </div>

        <section
            v-else
            class="min-h-0 min-w-0 flex-1 flex-col md:flex"
            :class="showConversation ? 'flex' : 'hidden'"
        >
            <header
                class="flex items-center gap-3 border-b border-border px-4 py-3"
            >
                <button
                    type="button"
                    class="flex items-center gap-1 rounded-sm text-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:hidden"
                    @click="showConversation = false"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    Colleagues
                </button>
                <p v-if="current" class="font-medium">
                    {{ current.name }}
                    <span
                        class="ml-1 text-xs font-normal text-muted-foreground"
                    >
                        {{ current.role }}
                    </span>
                </p>
            </header>

            <!-- Only the conversation announces new messages, not the page. -->
            <div
                ref="scroller"
                class="min-h-0 flex-1 overflow-y-auto"
                aria-live="polite"
                aria-relevant="additions"
                :aria-label="`Conversation with ${current?.name ?? 'your colleague'}`"
                role="log"
            >
                <p
                    v-if="messages.length === 0"
                    class="p-6 text-center text-muted-foreground"
                >
                    No messages from {{ current?.name }} yet.
                </p>
                <ul v-else class="flex flex-col gap-2 p-4">
                    <li
                        v-for="message in messages"
                        :key="message.id"
                        class="max-w-2xl rounded-md border border-border p-3"
                        :class="
                            message.from_user
                                ? 'self-end bg-secondary'
                                : 'bg-card'
                        "
                    >
                        <p class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-medium">
                                {{
                                    message.from_user
                                        ? 'You'
                                        : (current?.name ?? '')
                                }}
                            </span>
                            <span
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{
                                    formatMessageTime(
                                        message.created_at,
                                        new Date(now),
                                    )
                                }}
                            </span>
                            <span
                                v-if="message.ticket_key"
                                class="rounded-sm border border-border px-1.5 font-mono text-xs text-muted-foreground"
                            >
                                {{ message.ticket_key }}
                            </span>
                        </p>
                        <p class="mt-1 text-sm whitespace-pre-line">
                            {{ message.body }}
                        </p>
                    </li>
                </ul>
                <p
                    v-if="awaiting_reply"
                    class="px-4 pb-4 text-xs text-muted-foreground"
                    role="status"
                >
                    {{ current?.name }} is typing…
                </p>
            </div>

            <ChatComposer
                v-if="can_send && current"
                :key="current.key"
                :colleague="current.key"
                :colleague-name="current.name"
                :max-characters="max_characters"
            />
            <p
                v-else
                class="border-t border-border px-4 py-3 text-xs text-muted-foreground"
            >
                {{ send_blocked_reason }}
            </p>
        </section>
    </div>
</template>
