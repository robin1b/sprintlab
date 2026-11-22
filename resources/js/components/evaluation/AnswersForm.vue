<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { answers as answersRoute } from '@/routes/sprints/evaluation';

const props = defineProps<{
    sprintId: number;
    questions: string[];
    maxCharacters: number;
}>();

// The form lives as long as this component, so a draft survives a failed submit.
const form = useForm({ answers: props.questions.map(() => '') });

const errors = (): Record<string, string | undefined> => form.errors;

// The counter stays out of the way until the limit comes close.
function showCounter(index: number): boolean {
    return form.answers[index].length >= props.maxCharacters * 0.8;
}

function submit(): void {
    form.post(answersRoute.url(props.sprintId), { preserveScroll: true });
}
</script>

<template>
    <form class="flex flex-col gap-6" @submit.prevent="submit">
        <p class="text-muted-foreground">
            I have three questions about your sprint. Answer in your own words;
            a few sentences per question is enough.
        </p>

        <div v-for="(question, index) in questions" :key="index">
            <label
                :for="`answer-${index}`"
                class="mb-2 flex gap-3 text-sm font-medium"
            >
                <span class="font-mono text-muted-foreground">
                    {{ index + 1 }}
                </span>
                <span>{{ question }}</span>
            </label>
            <textarea
                :id="`answer-${index}`"
                v-model="form.answers[index]"
                rows="5"
                :aria-invalid="
                    errors()[`answers.${index}`] ? 'true' : undefined
                "
                class="min-h-28 w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-base outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm"
            ></textarea>
            <div class="mt-1 flex items-start justify-between gap-3">
                <InputError :message="errors()[`answers.${index}`]" />
                <p
                    v-if="showCounter(index)"
                    class="ml-auto font-mono text-xs"
                    :class="
                        form.answers[index].length > maxCharacters
                            ? 'text-destructive'
                            : 'text-muted-foreground'
                    "
                >
                    {{ form.answers[index].length }} / {{ maxCharacters }}
                </p>
            </div>
        </div>

        <InputError :message="errors().answers" />

        <div>
            <Button type="submit" :disabled="form.processing">Submit</Button>
        </div>
    </form>
</template>
