<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    code: string;
    language: string;
    caption?: string;
}>();

const state = ref<'idle' | 'copied' | 'failed'>('idle');
let timer: ReturnType<typeof setTimeout> | undefined;

function show(next: 'copied' | 'failed'): void {
    state.value = next;
    clearTimeout(timer);
    timer = setTimeout(() => (state.value = 'idle'), 2000);
}

// The clipboard is only reachable from a click and only on a secure page, so
// the call stays in the handler and a refusal is shown, not thrown.
function copy(): void {
    try {
        navigator.clipboard
            .writeText(props.code)
            .then(() => show('copied'))
            .catch(() => show('failed'));
    } catch {
        show('failed');
    }
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <figure class="overflow-hidden rounded-md border border-border bg-card">
        <figcaption
            class="flex min-h-9 items-center justify-between gap-3 border-b border-border px-3 py-1 font-mono text-xs text-muted-foreground"
        >
            <span class="min-w-0 truncate">
                {{ caption ?? language }}
                <span v-if="caption" class="ml-2 opacity-70">{{
                    language
                }}</span>
            </span>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="h-7 shrink-0 px-2 font-mono text-xs"
                @click="copy"
            >
                <span aria-live="polite">{{
                    state === 'copied'
                        ? 'Copied'
                        : state === 'failed'
                          ? 'Copy failed'
                          : 'Copy'
                }}</span>
            </Button>
        </figcaption>
        <pre
            class="overflow-x-auto p-4 font-mono text-[13px] leading-6 text-foreground"
            tabindex="0"
        ><code>{{ code }}</code></pre>
    </figure>
</template>
