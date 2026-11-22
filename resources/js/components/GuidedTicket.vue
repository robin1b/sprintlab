<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import type { BoardTicket } from '@/types/sprint';

const props = defineProps<{
    ticket: BoardTicket;
    repository: { full_name: string; url: string } | null;
    compact?: boolean;
}>();

const emit = defineEmits<{ hide: [] }>();

const page = usePage();
const userName = computed(() => page.props.auth.user.name);

const pr = computed(() => props.ticket.pull_request);
const review = computed(() => pr.value?.review ?? null);
const key = computed(() => props.ticket.key);
const branch = computed(() => props.ticket.suggested_branch);
const prTitle = computed(() => `${key.value} Add myself to the team`);

const pushed = computed(
    () => props.ticket.status !== 'todo' || pr.value !== null,
);
const opened = computed(() => pr.value !== null);
const reviewed = computed(() => review.value?.status === 'posted');
const merged = computed(() => props.ticket.status === 'done');
const allDone = computed(() => merged.value);

const compareUrl = computed(() =>
    props.repository
        ? `https://github.com/${props.repository.full_name}/compare/main...${branch.value}?expand=1&title=${encodeURIComponent(prTitle.value)}`
        : null,
);

const changesRequested = computed(
    () =>
        reviewed.value &&
        (review.value?.verdict === 'request_changes' ||
            review.value?.verdict === 'comment'),
);
const reviewSkipped = computed(
    () =>
        review.value?.status === 'skipped' || review.value?.status === 'failed',
);

const done = computed(() => [
    pushed.value,
    pushed.value,
    opened.value,
    reviewed.value,
    merged.value,
]);
const current = computed(() => done.value.findIndex((d) => !d));

const copiedCommand = ref<string | null>(null);

async function copy(command: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(command);
        copiedCommand.value = command;
        setTimeout(() => (copiedCommand.value = null), 1500);
    } catch {
        toast.error('Could not copy. Select the command instead.');
    }
}

type Block =
    | { type: 'text' | 'hint' | 'code' | 'cmd'; text: string; href?: undefined }
    | { type: 'link'; text: string; href: string }
    | { type: 'button'; text: string; href: string };

const cmds = (...list: string[]): Block[] =>
    list.map((text) => ({ type: 'cmd', text }));

const titles = [
    'Make a branch and your change',
    'Commit and push',
    'Open a pull request',
    'Wait for the review',
    'Merge',
];

const contents = computed<Block[][]>(() => {
    const step3: Block[] = [];

    if (compareUrl.value) {
        step3.push({
            type: 'button',
            text: 'Open a pull request on GitHub',
            href: compareUrl.value,
        });
    } else {
        step3.push({
            type: 'text',
            text: 'Connect your practice repository on the overview first.',
        });
    }

    step3.push(
        {
            type: 'text',
            text: 'GitHub fills in the title for you. Keep the ticket key at the start, then click Create pull request.',
        },
        { type: 'hint', text: 'The card moves to In review.' },
    );

    const step4: Block[] = [];

    if (reviewSkipped.value) {
        step4.push({
            type: 'text',
            text: 'The review did not run (for example the daily limit or the AI budget). You can still merge.',
        });
    } else if (!reviewed.value) {
        step4.push({
            type: 'text',
            text: 'Sarah reads your change and leaves a review on GitHub, usually within two minutes. This page updates by itself.',
        });
    } else if (changesRequested.value) {
        step4.push(
            {
                type: 'text',
                text: 'Sarah asks for a change. Read her comments on GitHub, fix it on the same branch, then:',
            },
            ...cmds(
                `git commit -am "${key.value} Fix the review remarks"`,
                'git push',
            ),
            { type: 'text', text: 'She reviews it again.' },
        );
    } else {
        step4.push({ type: 'text', text: 'Sarah approved it.' });
    }

    if (reviewed.value && pr.value) {
        step4.push({
            type: 'link',
            text: 'Read the review on GitHub',
            href: pr.value.url,
        });
    }

    return [
        [
            {
                type: 'text',
                text: 'In the folder of your practice repository:',
            },
            ...cmds(
                'git checkout main',
                'git pull',
                `git checkout -b ${branch.value}`,
            ),
            {
                type: 'text',
                text: 'Open README.md and add this at the very end:',
            },
            { type: 'code', text: `## Team\n\n- ${userName.value}, developer` },
        ],
        [
            ...cmds(
                'git add README.md',
                `git commit -m "${key.value} Add myself to the team"`,
                `git push -u origin ${branch.value}`,
            ),
            {
                type: 'hint',
                text: `Within a minute, ${key.value} moves to In progress on the board.`,
            },
        ],
        step3,
        step4,
        [
            {
                type: 'text',
                text: 'On GitHub, click Merge pull request, then Confirm merge. The card moves to Done.',
            },
            {
                type: 'text',
                text: 'After the merge, back on your computer, so your next branch starts from the latest code:',
            },
            ...cmds('git checkout main', 'git pull'),
        ],
    ];
});

// The current step shows its content. Step 4 keeps its message once the
// review is in, and step 5 keeps the follow-up commands after the merge.
const steps = computed(() =>
    titles.map((title, index) => ({
        title,
        done: done.value[index],
        current: index === current.value,
        open:
            index === current.value ||
            (index === 3 &&
                (reviewed.value || reviewSkipped.value) &&
                !merged.value) ||
            (index === 4 && merged.value),
        blocks: contents.value[index],
    })),
);
</script>

<template>
    <div
        :data-tour="compact ? undefined : 'guided-ticket'"
        :class="compact ? '' : 'rounded-md border border-border bg-card p-4'"
    >
        <template v-if="allDone && !compact">
            <p class="text-sm">
                <span class="font-mono text-xs text-muted-foreground">{{
                    key
                }}</span>
                is done. That is the whole flow: branch, push, pull request,
                review, merge. Every other ticket works the same way, without
                the guide.
            </p>
            <button
                type="button"
                class="mt-2 rounded-sm text-xs text-muted-foreground underline hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="emit('hide')"
            >
                Hide
            </button>
        </template>
        <template v-else>
            <div v-if="!compact" class="mb-4">
                <div class="mb-1 flex items-center gap-2">
                    <span class="font-mono text-xs text-muted-foreground">{{
                        ticket.key
                    }}</span>
                    <h2 class="text-sm font-medium">
                        Your first ticket, step by step
                    </h2>
                </div>
                <p class="max-w-3xl text-sm text-muted-foreground">
                    This warm-up shows how every ticket works here. Follow the
                    steps from top to bottom: each one ticks itself off when
                    GitHub tells Sprintlab it happened. WK stands for Workkit:
                    every ticket has a key like this one, and the key in your
                    branch name and pull request title is how the board follows
                    your work.
                </p>
            </div>

            <ol class="flex flex-col gap-3">
                <li
                    v-for="(step, index) in steps"
                    :key="step.title"
                    :aria-current="step.current ? 'step' : undefined"
                    class="border-l-2 pl-3"
                    :class="
                        step.current ? 'border-primary' : 'border-transparent'
                    "
                >
                    <div
                        class="flex items-center gap-2 text-sm"
                        :class="
                            step.done
                                ? 'text-muted-foreground'
                                : step.current
                                  ? ''
                                  : 'text-muted-foreground opacity-60'
                        "
                    >
                        <span
                            class="flex size-5 shrink-0 items-center justify-center font-mono text-xs"
                            :class="step.done ? 'text-primary' : ''"
                        >
                            <Check
                                v-if="step.done"
                                class="size-4"
                                aria-hidden="true"
                            />
                            <template v-else>{{ index + 1 }}</template>
                        </span>
                        <span class="font-medium">{{ step.title }}</span>
                        <span v-if="step.done" class="sr-only">(done)</span>
                    </div>

                    <div
                        v-if="step.open"
                        class="mt-2 flex flex-col gap-2 pl-7 text-sm"
                    >
                        <template v-for="(block, i) in step.blocks" :key="i">
                            <p v-if="block.type === 'text'">{{ block.text }}</p>
                            <p
                                v-else-if="block.type === 'hint'"
                                class="text-xs text-muted-foreground"
                            >
                                {{ block.text }}
                            </p>
                            <pre
                                v-else-if="block.type === 'code'"
                                class="overflow-x-auto rounded-md border border-border bg-background px-2 py-1 font-mono text-xs"
                                >{{ block.text }}</pre>
                            <div
                                v-else-if="block.type === 'cmd'"
                                class="flex items-center gap-2"
                            >
                                <code
                                    class="min-w-0 flex-1 overflow-x-auto rounded-md border border-border bg-background px-2 py-1 font-mono text-xs whitespace-nowrap"
                                    >{{ block.text }}</code
                                >
                                <button
                                    type="button"
                                    class="flex size-7 shrink-0 items-center justify-center rounded-md border border-border hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label="Copy command"
                                    @click="copy(block.text)"
                                >
                                    <Check
                                        v-if="copiedCommand === block.text"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    <Copy
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </button>
                            </div>
                            <a
                                v-else-if="block.type === 'button'"
                                :href="block.href"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex h-8 w-fit items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                                >{{ block.text }}</a
                            >
                            <a
                                v-else
                                :href="block.href"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="w-fit rounded-sm text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >{{ block.text }}</a
                            >
                        </template>
                    </div>
                </li>
            </ol>
        </template>
    </div>
</template>
