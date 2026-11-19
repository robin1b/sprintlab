<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import UsageTable from '@/components/admin/UsageTable.vue';
import { formatDollars, formatNumber } from '@/lib/money';
import { usage } from '@/routes/admin';
import type {
    DayUsage,
    DeploymentStep,
    FeatureUsage,
    ModelUsage,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'AI usage', href: usage() }],
    },
});

const props = defineProps<{
    month: string;
    spent_micros: number;
    limit_micros: number | null;
    remaining_micros: number | null;
    features: FeatureUsage[];
    models: ModelUsage[];
    days: DayUsage[];
    has_calls: boolean;
    prices_checked_on: string;
    deployment?: DeploymentStep[];
}>();

const steps = computed(() => props.deployment ?? []);

function statusOf(ok: boolean | null) {
    if (ok === true) {
        return { text: 'OK', tone: 'text-primary' };
    }

    if (ok === false) {
        return { text: 'Failed', tone: 'text-destructive' };
    }

    return { text: 'Not run yet', tone: 'text-muted-foreground' };
}

function formatAt(value: string) {
    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleString('en-GB', {
              dateStyle: 'medium',
              timeStyle: 'short',
          });
}

const share = computed(() =>
    props.limit_micros === null
        ? 0
        : Math.min(1, props.spent_micros / props.limit_micros),
);

const state = computed(() => {
    if (props.limit_micros === null) {
        return { text: 'No monthly cap is set', tone: 'text-muted-foreground' };
    }

    if (props.spent_micros >= props.limit_micros) {
        return {
            text: 'Budget reached: the AI colleagues are paused until next month',
            tone: 'text-danger',
        };
    }

    if (share.value >= 0.8) {
        return { text: 'Above 80% of the budget', tone: 'text-warning' };
    }

    return { text: 'Within budget', tone: 'text-muted-foreground' };
});

const barColour = computed(() => {
    if (
        props.limit_micros !== null &&
        props.spent_micros >= props.limit_micros
    ) {
        return 'bg-danger';
    }

    return share.value >= 0.8 ? 'bg-warning' : 'bg-primary';
});

const modelRows = computed(() =>
    props.models.map((model) => ({
        ...model,
        key: model.model,
        label: model.model,
        note: model.priced ? undefined : 'no price configured: counted as $0',
    })),
);
</script>

<template>
    <Head title="AI usage" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-6">
        <div>
            <p class="font-mono text-xs text-muted-foreground">admin</p>
            <h1 class="mt-1 text-xl font-semibold">AI usage</h1>
            <p class="mt-1 text-muted-foreground">
                What the AI colleagues cost in {{ month }}.
            </p>
        </div>

        <section class="rounded-md border border-border bg-card">
            <div class="border-b border-border px-4 py-3">
                <h2 class="font-medium">This month</h2>
            </div>
            <dl
                class="grid grid-cols-1 divide-y divide-border sm:grid-cols-3 sm:divide-x sm:divide-y-0"
            >
                <div class="px-4 py-3">
                    <dd class="font-mono text-xl">
                        {{ formatDollars(spent_micros) }}
                    </dd>
                    <dt class="text-xs text-muted-foreground">Spent</dt>
                </div>
                <div class="px-4 py-3">
                    <dd class="font-mono text-xl">
                        {{
                            limit_micros === null
                                ? 'no cap'
                                : formatDollars(limit_micros)
                        }}
                    </dd>
                    <dt class="text-xs text-muted-foreground">Budget</dt>
                </div>
                <div class="px-4 py-3">
                    <dd class="font-mono text-xl">
                        {{
                            remaining_micros === null
                                ? '-'
                                : formatDollars(remaining_micros)
                        }}
                    </dd>
                    <dt class="text-xs text-muted-foreground">Left</dt>
                </div>
            </dl>
            <div class="border-t border-border px-4 py-3">
                <div
                    v-if="limit_micros !== null"
                    class="mb-3 h-1 w-full overflow-hidden rounded-sm bg-muted"
                    role="progressbar"
                    aria-label="Share of the monthly budget spent"
                    :aria-valuenow="Math.round(share * 100)"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full"
                        :class="barColour"
                        :style="{ width: `${share * 100}%` }"
                    />
                </div>
                <p class="text-sm" :class="state.tone">{{ state.text }}</p>
            </div>
        </section>

        <p
            v-if="!has_calls"
            class="rounded-md border border-border bg-card px-4 py-3 text-muted-foreground"
        >
            No AI calls yet. They show up here as soon as a colleague answers.
        </p>

        <template v-else>
            <UsageTable
                title="Per feature, this month"
                first-column="Feature"
                :rows="features"
            />

            <UsageTable
                title="Per model, this month"
                first-column="Model"
                :rows="modelRows"
            />

            <section class="rounded-md border border-border bg-card">
                <h2 class="border-b border-border px-4 py-3 font-medium">
                    Per day, last 30 days
                </h2>
                <p v-if="!days.length" class="px-4 py-3 text-muted-foreground">
                    No calls in the last 30 days.
                </p>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b border-border text-muted-foreground"
                        >
                            <th class="px-4 py-2 text-left font-normal">
                                Date
                            </th>
                            <th
                                class="px-4 py-2 text-right font-mono font-normal"
                            >
                                Calls
                            </th>
                            <th
                                class="px-4 py-2 text-right font-mono font-normal"
                            >
                                Cost
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="day in days" :key="day.date">
                            <td class="px-4 py-2 font-mono text-xs">
                                {{ day.date }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono">
                                {{ formatNumber(day.calls) }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono">
                                {{ formatDollars(day.cost_micros) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>

        <section
            v-if="steps.length"
            class="rounded-md border border-border bg-card"
            data-test="deployment-card"
        >
            <h2 class="border-b border-border px-4 py-3 font-medium">
                Deployment
            </h2>
            <ul class="divide-y divide-border">
                <li v-for="step in steps" :key="step.key" class="px-4 py-3">
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"
                    >
                        <span class="font-medium">{{ step.label }}</span>
                        <span class="flex items-baseline gap-3 text-sm">
                            <span
                                v-if="step.at"
                                class="font-mono text-xs text-muted-foreground"
                                >{{ formatAt(step.at) }}</span
                            >
                            <span :class="statusOf(step.ok).tone">{{
                                statusOf(step.ok).text
                            }}</span>
                        </span>
                    </div>
                    <p
                        v-if="step.message"
                        class="mt-1 text-xs break-words text-muted-foreground"
                    >
                        {{ step.message }}
                    </p>
                </li>
            </ul>
        </section>

        <p class="text-xs text-muted-foreground">
            Costs are calculated from the token counts and the prices in the
            config, last checked on
            <span class="font-mono">{{ prices_checked_on }}</span
            >. The real balance is only visible in the Claude Console.
        </p>
    </div>
</template>
