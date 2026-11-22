<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';
import { update } from '@/routes/practice-repository';
import type { SetupState } from '@/types/sprint';

const props = defineProps<{
    setup: SetupState;
    // The user has played a sprint before.
    started: boolean;
}>();

const installClicked = ref(false);

const repositoryForm = useForm({
    repository_id: props.setup.repository?.id ?? 0,
});

const name = computed(
    () => props.setup.repository?.full_name ?? '<your-account>/workkit',
);
const folder = computed(() => name.value.split('/').pop() ?? 'workkit');
const owner = computed(() => name.value.split('/')[0]);
const commands = computed(() =>
    [
        `git clone https://github.com/${owner.value}/${folder.value}.git`,
        `cd ${folder.value}`,
        'composer setup',
        'php artisan serve',
    ].join('\n'),
);

// The first step that is not done; the third step has no status of its own.
const current = computed<1 | 2 | 4 | null>(() => {
    if (props.setup.ready) {
        return props.started ? null : 4;
    }

    return props.setup.installed ? 2 : 1;
});

function saveRepository(): void {
    repositoryForm.put(update.url(), { preserveScroll: true });
}
</script>

<template>
    <ol class="divide-y divide-border">
        <li class="px-4 py-2 text-sm">
            <Link
                :href="home({ query: { intro: 1 } })"
                class="text-muted-foreground hover:text-foreground hover:underline"
            >
                Watch the intro video
            </Link>
        </li>

        <li class="flex gap-3 px-4 py-3">
            <span
                class="flex size-5 shrink-0 items-center justify-center rounded-sm border border-primary font-mono text-xs text-primary"
            >
                <Check class="size-3.5" />
            </span>
            <div class="flex flex-1 items-center justify-between gap-3">
                <span>Sign in with GitHub</span>
                <span class="font-mono text-xs text-muted-foreground"
                    >Done</span
                >
            </div>
        </li>

        <li class="flex gap-3 px-4 py-3">
            <span
                class="flex size-5 shrink-0 items-center justify-center rounded-sm border font-mono text-xs"
                :class="
                    setup.ready
                        ? 'border-primary text-primary'
                        : 'border-border'
                "
            >
                <Check v-if="setup.ready" class="size-3.5" />
                <template v-else>1</template>
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="font-medium">Create your practice repository</h3>
                    <span
                        v-if="setup.ready || current === 1"
                        class="font-mono text-xs"
                        :class="
                            setup.ready
                                ? 'text-muted-foreground'
                                : 'text-primary'
                        "
                    >
                        {{ setup.ready ? 'Done' : 'Next' }}
                    </span>
                </div>
                <template v-if="!setup.ready">
                    <p class="mt-1 text-sm text-muted-foreground">
                        Create your own copy of the Workkit codebase. Name it
                        workkit; public or private are both fine.
                    </p>
                    <Button as-child variant="secondary" size="sm" class="mt-3">
                        <a
                            :href="setup.template_url"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Create from template
                        </a>
                    </Button>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Done? Continue with step 2.
                    </p>
                </template>
            </div>
        </li>

        <li class="flex gap-3 px-4 py-3">
            <span
                class="flex size-5 shrink-0 items-center justify-center rounded-sm border font-mono text-xs"
                :class="
                    setup.ready
                        ? 'border-primary text-primary'
                        : 'border-border'
                "
            >
                <Check v-if="setup.ready" class="size-3.5" />
                <template v-else>2</template>
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="font-medium">Install the Sprintlab app on it</h3>
                    <span
                        v-if="setup.ready || current === 2"
                        class="font-mono text-xs"
                        :class="
                            setup.ready
                                ? 'text-muted-foreground'
                                : 'text-primary'
                        "
                    >
                        {{ setup.ready ? 'Done' : 'Next' }}
                    </span>
                </div>

                <p v-if="setup.suspended" class="mt-1 text-sm text-warning">
                    The app is suspended on GitHub. Unsuspend it in your GitHub
                    settings.
                </p>

                <template v-if="setup.ready && setup.repository">
                    <p class="mt-1 text-sm">
                        Connected:
                        <a
                            :href="setup.repository.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-mono text-primary hover:underline"
                            >{{ setup.repository.full_name }}</a
                        >
                    </p>
                </template>
                <template v-else-if="!setup.suspended">
                    <p class="mt-1 text-sm text-muted-foreground">
                        Choose Only select repositories and pick your new
                        repository. Sprintlab cannot see anything else.
                    </p>
                    <Button
                        v-if="setup.install_url"
                        as-child
                        variant="secondary"
                        size="sm"
                        class="mt-3"
                    >
                        <a
                            :href="setup.install_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            @click="installClicked = true"
                        >
                            Install the app
                            <span class="sr-only"
                                >(opens GitHub in a new tab)</span
                            >
                        </a>
                    </Button>
                    <p v-else class="mt-2 text-sm text-muted-foreground">
                        The GitHub App is not configured yet.
                    </p>
                    <p
                        v-if="installClicked"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Waiting for GitHub…
                    </p>
                </template>

                <form
                    v-if="setup.repositories.length > 1"
                    class="mt-3 flex flex-wrap items-end gap-2"
                    @submit.prevent="saveRepository"
                >
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="text-muted-foreground">
                            Which repository do you work in?
                        </span>
                        <select
                            v-model="repositoryForm.repository_id"
                            class="h-8 rounded-md border border-input bg-background px-2 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            <option
                                v-for="repository in setup.repositories"
                                :key="repository.id"
                                :value="repository.id"
                            >
                                {{ repository.full_name }}
                            </option>
                        </select>
                    </label>
                    <Button
                        type="submit"
                        size="sm"
                        variant="secondary"
                        :disabled="repositoryForm.processing"
                    >
                        Save
                    </Button>
                    <p
                        v-if="repositoryForm.errors.repository_id"
                        class="w-full text-sm text-destructive"
                    >
                        {{ repositoryForm.errors.repository_id }}
                    </p>
                </form>
            </div>
        </li>

        <li class="flex gap-3 px-4 py-3">
            <span
                class="flex size-5 shrink-0 items-center justify-center rounded-sm border border-border font-mono text-xs"
            >
                3
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="font-medium">Run it on your computer</h3>
                <pre
                    class="mt-2 overflow-x-auto rounded-md border border-border bg-background px-3 py-2 font-mono text-xs"
                ><code>{{ commands }}</code></pre>
                <p class="mt-2 text-sm text-muted-foreground">
                    You need PHP 8.3 and Composer. The README of the repository
                    has the rest.
                </p>
            </div>
        </li>

        <li class="flex gap-3 px-4 py-3">
            <span
                class="flex size-5 shrink-0 items-center justify-center rounded-sm border font-mono text-xs"
                :class="
                    started ? 'border-primary text-primary' : 'border-border'
                "
            >
                <Check v-if="started" class="size-3.5" />
                <template v-else>4</template>
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="font-medium">Start your first sprint</h3>
                    <span
                        v-if="started || current === 4"
                        class="font-mono text-xs"
                        :class="
                            started ? 'text-muted-foreground' : 'text-primary'
                        "
                    >
                        {{ started ? 'Done' : 'Next' }}
                    </span>
                </div>
                <p v-if="!started" class="mt-1 text-sm text-muted-foreground">
                    Press Start sprint on the card of your sprint.
                </p>
            </div>
        </li>
    </ol>
</template>
