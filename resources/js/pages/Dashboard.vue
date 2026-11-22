<script setup lang="ts">
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ColleagueList from '@/components/ColleagueList.vue';
import { offerFacts, offerResult } from '@/components/dashboard/offer';
import OfferRow from '@/components/dashboard/OfferRow.vue';
import SetupSteps from '@/components/dashboard/SetupSteps.vue';
import EvaluationCard from '@/components/evaluation/EvaluationCard.vue';
import LevelProgress from '@/components/evaluation/LevelProgress.vue';
import StatusChip from '@/components/StatusChip.vue';
import { Button } from '@/components/ui/button';
import { useNow } from '@/composables/useNow';
import { deadlineInWords, formatMessageTime } from '@/lib/time';
import { board, chat, dashboard } from '@/routes';
import { pause, resume, store } from '@/routes/sprints';
import type { EvaluationState, LevelProgressData } from '@/types/evaluation';
import type {
    Colleague,
    ScenarioOffer,
    SetupState,
    SprintOverview,
    StatusOption,
} from '@/types/sprint';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Overview', href: dashboard() }],
    },
});

const props = defineProps<{
    scenarios: ScenarioOffer[];
    recommended_id: number | null;
    completed: {
        scenario_title: string;
        completed_at: string | null;
        evaluation: EvaluationState | null;
    } | null;
    setup: SetupState;
    progress: LevelProgressData;
    overview: SprintOverview | null;
    team: Colleague[];
}>();

const page = usePage();

// Follow the evaluation until it is done; the card shows what Sarah is doing.
const { start, stop } = usePoll(
    5_000,
    { only: ['completed', 'progress', 'setup'] },
    { autoStart: false },
);
const evaluationPending = computed(
    () =>
        props.completed !== null &&
        props.completed.evaluation?.status !== 'completed',
);

// While the app is not connected the page follows what GitHub reports.
const setupPending = computed(
    () => page.props.sprint === null && !props.setup.ready,
);
const polling = computed(() => evaluationPending.value || setupPending.value);

watch(polling, (value) => (value ? start() : stop()), {
    immediate: true,
});

const now = useNow(60_000);

const firstName = computed(
    () => page.props.auth.user.name.trim().split(/\s+/u)[0],
);
const sprint = computed(() => page.props.sprint);
const paused = computed(() => sprint.value?.status === 'paused');

const overdue = computed(
    () =>
        sprint.value !== null &&
        (sprint.value.is_overdue ||
            new Date(sprint.value.deadline_at).getTime() < now.value),
);

const recommended = computed(
    () =>
        props.scenarios.find((offer) => offer.id === props.recommended_id) ??
        null,
);
const others = computed(() =>
    props.scenarios.filter((offer) => offer.id !== recommended.value?.id),
);

const startForm = useForm({ scenario_id: 0 });

function startSprint(id: number): void {
    if (startForm.processing) {
        return;
    }

    startForm.scenario_id = id;
    startForm.post(store.url());
}

function togglePause(): void {
    if (sprint.value === null) {
        return;
    }

    const target = paused.value ? resume : pause;

    router.post(target.url(sprint.value.id), {}, { preserveScroll: true });
}

const connectHint = 'Connect your practice repository first.';
const showSteps = ref(false);

function blocked(offer: ScenarioOffer): boolean {
    return !offer.is_demo && !props.setup.ready;
}

const statusLabels = computed(() => {
    const labels = {} as Record<string, string>;

    props.overview?.ticket_counts.forEach((item: StatusOption) => {
        labels[item.value] = item.label;
    });

    return labels;
});
</script>

<template>
    <Head title="Overview" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-6">
        <div>
            <p class="font-mono text-xs text-muted-foreground">overview</p>
            <h1 class="mt-1 text-xl font-semibold">Hi {{ firstName }}</h1>
            <p v-if="sprint === null" class="mt-1 text-muted-foreground">
                <template v-if="completed">
                    You finished {{ completed.scenario_title }}. Nice work.
                    Start it again whenever you like.
                </template>
                <template v-else>
                    Welcome to Workkit. Here is how to get your first sprint
                    going.
                </template>
            </p>
            <LevelProgress :progress="progress" class="mt-2" />
        </div>

        <EvaluationCard
            v-if="sprint === null && completed"
            :evaluation="completed.evaluation"
        />

        <template v-if="sprint !== null && overview !== null">
            <section class="rounded-md border border-border bg-card">
                <div
                    class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-4 py-3"
                >
                    <div class="min-w-0">
                        <h2 class="font-medium">{{ sprint.scenario_title }}</h2>
                        <p class="mt-1 font-mono text-xs text-muted-foreground">
                            Day {{ sprint.current_day }} of
                            {{ sprint.duration_days }}
                            <template v-if="!paused">
                                ·
                                <span :class="overdue ? 'text-warning' : ''">
                                    {{
                                        deadlineInWords(sprint.deadline_at, now)
                                    }}
                                </span>
                            </template>
                        </p>
                        <p v-if="paused" class="mt-1 text-sm text-warning">
                            Paused. The clock is stopped until you resume.
                        </p>
                    </div>
                    <Button variant="outline" size="sm" @click="togglePause">
                        {{ paused ? 'Resume sprint' : 'Pause sprint' }}
                    </Button>
                </div>
                <dl
                    class="grid grid-cols-2 divide-x divide-border sm:grid-cols-4"
                >
                    <div
                        v-for="item in overview.ticket_counts"
                        :key="item.value"
                        class="px-4 py-3"
                    >
                        <dd class="font-mono text-xl">{{ item.count }}</dd>
                        <dt class="text-xs text-muted-foreground">
                            {{ item.label }}
                        </dt>
                    </div>
                </dl>
            </section>

            <section class="rounded-md border border-border bg-card">
                <div
                    class="flex items-center justify-between border-b border-border px-4 py-3"
                >
                    <h2 class="font-medium">Today's tickets</h2>
                    <Link
                        :href="board()"
                        class="rounded-sm text-sm text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        Open board
                    </Link>
                </div>
                <ul
                    v-if="overview.todays_tickets.length"
                    class="divide-y divide-border"
                >
                    <li
                        v-for="ticket in overview.todays_tickets"
                        :key="ticket.key"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <span class="font-mono text-xs text-muted-foreground">
                            {{ ticket.key }}
                        </span>
                        <span class="min-w-0 flex-1 truncate">
                            {{ ticket.title }}
                        </span>
                        <StatusChip
                            :status="ticket.status"
                            :label="statusLabels[ticket.status]"
                        />
                    </li>
                </ul>
                <p v-else class="px-4 py-3 text-muted-foreground">
                    No tickets released today.
                </p>
            </section>

            <section class="rounded-md border border-border bg-card">
                <div
                    class="flex items-center justify-between border-b border-border px-4 py-3"
                >
                    <h2 class="font-medium">Latest messages</h2>
                    <Link
                        :href="chat()"
                        class="rounded-sm text-sm text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        Open chat
                    </Link>
                </div>
                <ul
                    v-if="overview.latest_messages.length"
                    class="divide-y divide-border"
                >
                    <li
                        v-for="(message, index) in overview.latest_messages"
                        :key="index"
                        class="px-4 py-3"
                    >
                        <p class="flex items-baseline gap-2 text-sm">
                            <span class="font-medium">{{
                                message.colleague
                            }}</span>
                            <span
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ formatMessageTime(message.created_at) }}
                            </span>
                        </p>
                        <p class="mt-1 text-muted-foreground">
                            {{ message.body }}
                        </p>
                    </li>
                </ul>
                <p v-else class="px-4 py-3 text-muted-foreground">
                    No messages yet.
                </p>
            </section>
        </template>

        <template v-else>
            <section
                v-if="!setup.ready"
                class="rounded-md border border-border bg-card"
            >
                <h2 class="border-b border-border px-4 py-3 font-medium">
                    Getting started
                </h2>
                <SetupSteps :setup="setup" :started="completed !== null" />
            </section>

            <section
                v-if="recommended"
                class="rounded-md border border-border bg-card"
            >
                <div class="px-4 py-3">
                    <h2 class="font-medium">{{ recommended.title }}</h2>
                    <p class="mt-1 font-mono text-xs text-muted-foreground">
                        {{ offerFacts(recommended) }}
                        <template v-if="offerResult(recommended)">
                            · {{ offerResult(recommended) }}
                        </template>
                    </p>
                    <p
                        v-if="recommended.summary"
                        class="mt-2 text-muted-foreground"
                    >
                        {{ recommended.summary }}
                    </p>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <Button
                            :disabled="
                                startForm.processing || blocked(recommended)
                            "
                            @click="startSprint(recommended.id)"
                        >
                            Start sprint
                        </Button>
                        <span
                            v-if="blocked(recommended)"
                            class="text-sm text-muted-foreground"
                        >
                            {{ connectHint }}
                        </span>
                    </div>
                    <p
                        v-if="startForm.errors.scenario_id"
                        class="mt-2 text-sm text-destructive"
                    >
                        {{ startForm.errors.scenario_id }}
                    </p>
                </div>
            </section>

            <section
                v-if="others.length"
                class="rounded-md border border-border bg-card"
            >
                <h2 class="border-b border-border px-4 py-3 font-medium">
                    Other sprints
                </h2>
                <ul class="divide-y divide-border">
                    <OfferRow
                        v-for="offer in others"
                        :key="offer.id"
                        :offer="offer"
                        :disabled="startForm.processing || blocked(offer)"
                        :hint="blocked(offer) ? connectHint : null"
                        @start="startSprint"
                    />
                </ul>
            </section>

            <section
                v-if="setup.ready && showSteps"
                class="rounded-md border border-border bg-card"
            >
                <h2 class="border-b border-border px-4 py-3 font-medium">
                    Getting started
                </h2>
                <SetupSteps :setup="setup" :started="completed !== null" />
            </section>
            <p
                v-if="setup.ready && setup.repository && !showSteps"
                class="text-sm text-muted-foreground"
            >
                Repository:
                <span class="font-mono">{{ setup.repository.full_name }}</span>
                ·
                <button
                    type="button"
                    class="rounded-sm text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @click="showSteps = true"
                >
                    change
                </button>
            </p>
        </template>

        <section class="rounded-md border border-border bg-card">
            <h2 class="border-b border-border px-4 py-3 font-medium">
                Your team
            </h2>
            <ColleagueList :colleagues="team" />
        </section>
    </div>
</template>
