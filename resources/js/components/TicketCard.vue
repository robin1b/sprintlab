<script setup lang="ts">
import { MoreHorizontal } from '@lucide/vue';
import TicketTypeTag from '@/components/TicketTypeTag.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { BoardTicket, StatusOption, TicketStatus } from '@/types/sprint';

const props = defineProps<{
    ticket: BoardTicket;
    statuses: StatusOption[];
    // True when the sprint is paused: the card can be read, not moved.
    locked: boolean;
}>();

const emit = defineEmits<{
    open: [];
    move: [status: TicketStatus];
    dragStart: [];
    dragEnd: [];
}>();

function onDragStart(event: DragEvent): void {
    event.dataTransfer?.setData('text/plain', String(props.ticket.id));

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }

    emit('dragStart');
}
</script>

<template>
    <article
        class="relative flex flex-col gap-2 rounded-md border border-border bg-background p-3 focus-within:border-ring"
        :class="locked ? '' : 'cursor-grab active:cursor-grabbing'"
        :draggable="!locked"
        @dragstart="onDragStart"
        @dragend="emit('dragEnd')"
    >
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <span class="font-mono text-xs text-muted-foreground">
                    {{ ticket.key }}
                </span>
                <TicketTypeTag :type="ticket.type" />
            </div>

            <DropdownMenu v-if="!locked">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="relative z-10 -my-1 -mr-1 size-7"
                        :aria-label="`Move ${ticket.key}`"
                    >
                        <MoreHorizontal class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuLabel class="font-mono text-xs">
                        Move to
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="status in statuses"
                        :key="status.value"
                        :disabled="status.value === ticket.status"
                        @select="emit('move', status.value)"
                    >
                        {{ status.label }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <!-- The ::after layer makes the whole card clickable. -->
        <button
            type="button"
            class="text-left text-sm font-medium outline-none after:absolute after:inset-0 after:content-['']"
            @click="emit('open')"
        >
            {{ ticket.title }}
        </button>

        <div class="flex items-center justify-between gap-2">
            <span
                class="flex size-6 items-center justify-center rounded-md border border-border bg-secondary font-mono text-[10px]"
                :title="`${ticket.reporter.name}, ${ticket.reporter.role}`"
            >
                {{ ticket.reporter.name.slice(0, 2).toUpperCase() }}
            </span>
            <a
                v-if="ticket.pull_request"
                :href="ticket.pull_request.url"
                target="_blank"
                rel="noopener noreferrer"
                class="relative z-10 font-mono text-xs text-primary hover:underline"
            >
                PR #{{ ticket.pull_request.number }}
            </a>
        </div>
    </article>
</template>
