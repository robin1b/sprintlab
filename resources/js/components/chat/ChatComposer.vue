<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { store } from '@/routes/chat/messages';

const props = defineProps<{
    colleague: string;
    colleagueName: string;
    maxCharacters: number;
}>();

const form = useForm({ body: '' });
const field = ref<HTMLTextAreaElement | null>(null);

const length = computed(() => form.body.length);
const canSubmit = computed(
    () =>
        !form.processing &&
        form.body.trim() !== '' &&
        length.value <= props.maxCharacters,
);
// The counter stays out of the way until the limit comes close.
const showCounter = computed(() => length.value >= props.maxCharacters * 0.8);

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    form.post(store(props.colleague).url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            field.value?.focus();
        },
    });
}

function onEnter(event: KeyboardEvent): void {
    // Shift+Enter is a new line. Enter while composing (IME) confirms a character.
    if (event.shiftKey || event.isComposing) {
        return;
    }

    event.preventDefault();
    submit();
}
</script>

<template>
    <form class="border-t border-border px-4 py-3" @submit.prevent="submit">
        <label :for="`chat-body-${colleague}`" class="sr-only">
            Message to {{ colleagueName }}
        </label>
        <div class="flex items-end gap-2">
            <textarea
                :id="`chat-body-${colleague}`"
                ref="field"
                v-model="form.body"
                rows="1"
                :placeholder="`Message ${colleagueName}`"
                :aria-invalid="form.errors.body ? 'true' : undefined"
                class="[field-sizing:content] max-h-40 min-h-9 w-full resize-none rounded-md border border-input bg-transparent px-3 py-1.5 text-base outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm"
                @keydown.enter="onEnter"
            ></textarea>
            <Button type="submit" :disabled="!canSubmit">Send</Button>
        </div>
        <div class="mt-1 flex items-start justify-between gap-3">
            <InputError :message="form.errors.body" />
            <p
                v-if="showCounter"
                class="ml-auto font-mono text-xs"
                :class="
                    length > maxCharacters
                        ? 'text-destructive'
                        : 'text-muted-foreground'
                "
            >
                {{ length }}/{{ maxCharacters }}
            </p>
        </div>
    </form>
</template>
