<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import TicketTypeTag from '@/components/TicketTypeTag.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BoardTicket } from '@/types/sprint';

defineProps<{ ticket: BoardTicket | null }>();

const emit = defineEmits<{ close: [] }>();

const copied = ref(false);

async function copyBranch(branch: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(branch);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        toast.error('Could not copy. Select the branch name instead.');
    }
}
</script>

<template>
    <Dialog
        :open="ticket !== null"
        @update:open="(open: boolean) => !open && emit('close')"
    >
        <DialogContent
            v-if="ticket"
            class="max-h-[85svh] gap-5 overflow-y-auto shadow-none sm:max-w-xl"
        >
            <DialogHeader class="pr-6">
                <div class="flex items-center gap-2">
                    <span class="font-mono text-xs text-muted-foreground">
                        {{ ticket.key }}
                    </span>
                    <TicketTypeTag :type="ticket.type" />
                </div>
                <DialogTitle>{{ ticket.title }}</DialogTitle>
                <DialogDescription>
                    Reported by {{ ticket.reporter.name }},
                    {{ ticket.reporter.role.toLowerCase() }}
                </DialogDescription>
            </DialogHeader>

            <section>
                <h3 class="mb-1 font-mono text-xs text-muted-foreground">
                    description
                </h3>
                <p class="text-sm whitespace-pre-line">
                    {{ ticket.description }}
                </p>
            </section>

            <section>
                <h3 class="mb-1 font-mono text-xs text-muted-foreground">
                    acceptance criteria
                </h3>
                <ul class="list-disc space-y-1 pl-5 text-sm">
                    <li
                        v-for="criterion in ticket.acceptance_criteria"
                        :key="criterion"
                    >
                        {{ criterion }}
                    </li>
                </ul>
            </section>

            <section>
                <h3 class="mb-1 font-mono text-xs text-muted-foreground">
                    Suggested branch
                </h3>
                <div class="flex items-center gap-2">
                    <code
                        class="min-w-0 flex-1 truncate rounded-md border border-border bg-background px-2 py-1 font-mono text-xs"
                    >
                        {{ ticket.suggested_branch }}
                    </code>
                    <Button
                        variant="outline"
                        size="icon"
                        class="size-7 shrink-0"
                        aria-label="Copy branch name"
                        @click="copyBranch(ticket.suggested_branch)"
                    >
                        <Check
                            v-if="copied"
                            class="size-4"
                            aria-hidden="true"
                        />
                        <Copy v-else class="size-4" aria-hidden="true" />
                    </Button>
                </div>
                <p class="mt-2 text-xs text-muted-foreground">
                    A pull request from a branch with the ticket key in its name
                    links itself to the ticket.
                </p>
            </section>
        </DialogContent>
    </Dialog>
</template>
