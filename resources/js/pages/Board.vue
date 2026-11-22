<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import GuidedTicket from '@/components/GuidedTicket.vue';
import TicketCard from '@/components/TicketCard.vue';
import TicketDialog from '@/components/TicketDialog.vue';
import GuidedTour from '@/components/tour/GuidedTour.vue';
import { useTour } from '@/composables/useTour';
import { board, dashboard } from '@/routes';
import { update } from '@/routes/tickets';
import { tours } from '@/tours';
import type { BoardTicket, StatusOption, TicketStatus } from '@/types/sprint';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Board', href: board() }],
    },
});

const props = withDefaults(
    defineProps<{
        tickets: BoardTicket[];
        statuses: StatusOption[];
        repository?: { full_name: string; url: string } | null;
    }>(),
    { repository: null },
);

// Poll only the tickets. Polling keeps scroll position and local state, so an
// open dialog stays open.
usePoll(8_000, { only: ['tickets'] });

const page = usePage();
const hasSprint = computed(() => page.props.sprint !== null);
const paused = computed(() => page.props.sprint?.status === 'paused');

const columns = computed(() =>
    props.statuses.map((status) => ({
        ...status,
        tickets: props.tickets.filter((t) => t.status === status.value),
    })),
);

const firstTicketId = computed(
    () => columns.value.find((c) => c.tickets.length > 0)?.tickets[0].id,
);

const hiddenIds = ref<number[]>([]);

function hiddenKey(id: number): string {
    return `sprintlab.guided.hidden.${id}`;
}

function isHidden(id: number): boolean {
    try {
        return localStorage.getItem(hiddenKey(id)) === '1';
    } catch {
        return false;
    }
}

function hideGuide(id: number): void {
    hiddenIds.value.push(id);

    try {
        localStorage.setItem(hiddenKey(id), '1');
    } catch {
        // Hiding still works for this visit.
    }
}

// Read after mount: localStorage does not exist on the server.
onMounted(() => {
    hiddenIds.value = props.tickets
        .filter((t) => t.guided && isHidden(t.id))
        .map((t) => t.id);
});

const guidedTicket = computed(() => {
    const ticket = props.tickets.find((t) => t.guided);

    return ticket && !hiddenIds.value.includes(ticket.id) ? ticket : null;
});

const boardSteps = computed(() =>
    tours.board.filter(
        (step) => step.target !== 'guided-ticket' || guidedTicket.value,
    ),
);

const tour = useTour('board', {
    steps: tours.board,
    when: () => hasSprint.value && props.tickets.length > 0,
});

// The dialog shows the live ticket, so a poll can update it while it is open.
const selectedId = ref<number | null>(null);
const selected = computed(
    () => props.tickets.find((t) => t.id === selectedId.value) ?? null,
);

function move(ticket: BoardTicket, status: TicketStatus): void {
    if (paused.value || ticket.status === status) {
        return;
    }

    router.patch(
        update.url(ticket.id),
        { status },
        { preserveScroll: true, preserveState: true },
    );
}

const draggingId = ref<number | null>(null);
const dropTarget = ref<TicketStatus | null>(null);

function onDrop(status: TicketStatus): void {
    const ticket = props.tickets.find((t) => t.id === draggingId.value);

    draggingId.value = null;
    dropTarget.value = null;

    if (ticket) {
        move(ticket, status);
    }
}
</script>

<template>
    <Head title="Board" />

    <div class="flex flex-1 flex-col gap-4 p-6">
        <p
            v-if="paused"
            class="rounded-md border border-warning px-3 py-2 text-sm text-warning"
        >
            The sprint is paused, so tickets cannot be moved.
            <Link
                :href="dashboard()"
                class="rounded-sm underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >Resume it</Link
            >
            on the overview.
        </p>
        <p v-else-if="!hasSprint" class="text-muted-foreground">
            Tickets appear here when your first sprint starts.
            <Link
                :href="dashboard()"
                class="rounded-sm text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                Go to the overview
            </Link>
        </p>

        <GuidedTicket
            v-if="guidedTicket"
            :ticket="guidedTicket"
            :repository="repository"
            @hide="hideGuide(guidedTicket.id)"
        />

        <div
            data-tour="board-columns"
            class="grid flex-1 gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
            <section
                v-for="column in columns"
                :key="column.value"
                class="flex min-h-48 flex-col rounded-md border bg-card"
                :class="
                    dropTarget === column.value
                        ? 'border-primary'
                        : 'border-border'
                "
                @dragover.prevent="dropTarget = column.value"
                @dragleave="
                    (e: DragEvent) =>
                        !(e.currentTarget as HTMLElement).contains(
                            e.relatedTarget as Node,
                        ) && (dropTarget = null)
                "
                @drop.prevent="onDrop(column.value)"
            >
                <h2
                    class="flex items-center justify-between border-b border-border px-3 py-2 text-sm font-medium"
                >
                    {{ column.label }}
                    <span class="font-mono text-xs text-muted-foreground">
                        {{ column.tickets.length }}
                    </span>
                </h2>
                <div class="flex flex-1 flex-col gap-2 p-2">
                    <p
                        v-if="column.tickets.length === 0 && hasSprint"
                        class="px-1 py-2 text-xs text-muted-foreground"
                    >
                        No tickets in {{ column.label.toLowerCase() }}.
                    </p>
                    <TicketCard
                        v-for="ticket in column.tickets"
                        :key="ticket.id"
                        :ticket="ticket"
                        :statuses="statuses"
                        :locked="paused"
                        :first="ticket.id === firstTicketId"
                        @open="selectedId = ticket.id"
                        @move="(status) => move(ticket, status)"
                        @drag-start="draggingId = ticket.id"
                        @drag-end="
                            draggingId = null;
                            dropTarget = null;
                        "
                    />
                </div>
            </section>
        </div>

        <TicketDialog
            :ticket="selected"
            :repository="repository"
            @close="selectedId = null"
        />
        <GuidedTour
            :steps="boardSteps"
            :open="tour.open.value"
            @finish="tour.close('finish')"
            @skip="tour.close('skip')"
        />
    </div>
</template>
