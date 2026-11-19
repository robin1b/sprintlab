<script setup lang="ts">
import { formatDollars, formatNumber } from '@/lib/money';

export type UsageTableRow = {
    key: string;
    label: string;
    note?: string;
    calls: number;
    input_tokens: number;
    output_tokens: number;
    cost_micros: number;
};

defineProps<{
    title: string;
    firstColumn: string;
    rows: UsageTableRow[];
}>();
</script>

<template>
    <section class="rounded-md border border-border bg-card">
        <h2 class="border-b border-border px-4 py-3 font-medium">
            {{ title }}
        </h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border text-muted-foreground">
                        <th class="px-4 py-2 text-left font-normal">
                            {{ firstColumn }}
                        </th>
                        <th class="px-4 py-2 text-right font-mono font-normal">
                            Calls
                        </th>
                        <th class="px-4 py-2 text-right font-mono font-normal">
                            Input tokens
                        </th>
                        <th class="px-4 py-2 text-right font-mono font-normal">
                            Output tokens
                        </th>
                        <th class="px-4 py-2 text-right font-mono font-normal">
                            Cost
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in rows" :key="row.key">
                        <td class="px-4 py-2">
                            <span
                                :class="row.note ? 'font-mono text-xs' : ''"
                                >{{ row.label }}</span
                            >
                            <p
                                v-if="row.note"
                                class="mt-0.5 text-xs text-warning"
                            >
                                {{ row.note }}
                            </p>
                        </td>
                        <td class="px-4 py-2 text-right font-mono">
                            {{ formatNumber(row.calls) }}
                        </td>
                        <td class="px-4 py-2 text-right font-mono">
                            {{ formatNumber(row.input_tokens) }}
                        </td>
                        <td class="px-4 py-2 text-right font-mono">
                            {{ formatNumber(row.output_tokens) }}
                        </td>
                        <td class="px-4 py-2 text-right font-mono">
                            {{ formatDollars(row.cost_micros) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
