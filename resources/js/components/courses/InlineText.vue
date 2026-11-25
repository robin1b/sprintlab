<script setup lang="ts">
import { computed } from 'vue';
import { parseInline } from '@/lib/inlineText';

const props = defineProps<{ text: string }>();

const segments = computed(() => parseInline(props.text));
</script>

<template>
    <template v-for="(segment, index) in segments" :key="index">
        <code
            v-if="segment.type === 'code'"
            class="rounded-sm bg-muted px-1.5 py-0.5 font-mono text-[0.875em] text-foreground"
            >{{ segment.text }}</code
        >
        <strong
            v-else-if="segment.type === 'strong'"
            class="font-semibold text-foreground"
            >{{ segment.text }}</strong
        >
        <a
            v-else-if="segment.type === 'link'"
            :href="segment.href"
            target="_blank"
            rel="noopener noreferrer"
            class="text-primary underline underline-offset-4"
            >{{ segment.text }}</a
        >
        <template v-else>{{ segment.text }}</template>
    </template>
</template>
