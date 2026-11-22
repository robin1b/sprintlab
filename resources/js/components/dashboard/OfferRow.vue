<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { ScenarioOffer } from '@/types/sprint';
import { offerFacts, offerResult } from './offer';

defineProps<{
    offer: ScenarioOffer;
    disabled: boolean;
    hint?: string | null;
}>();

defineEmits<{
    start: [id: number];
}>();
</script>

<template>
    <li class="flex flex-wrap items-center gap-3 px-4 py-3">
        <div class="min-w-0 flex-1">
            <p class="truncate">{{ offer.title }}</p>
            <p class="mt-0.5 font-mono text-xs text-muted-foreground">
                {{ offerFacts(offer) }}
                <template v-if="offerResult(offer)">
                    · {{ offerResult(offer) }}
                </template>
            </p>
        </div>
        <span v-if="hint" class="text-xs text-muted-foreground">{{
            hint
        }}</span>
        <Button
            variant="secondary"
            size="sm"
            :disabled="disabled"
            @click="$emit('start', offer.id)"
        >
            {{ offer.result ? 'Play again' : 'Start' }}
        </Button>
    </li>
</template>
