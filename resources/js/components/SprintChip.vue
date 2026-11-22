<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useNow } from '@/composables/useNow';
import { compactDuration } from '@/lib/time';

const page = usePage();
const now = useNow(60_000);

const sprint = computed(() => page.props.sprint);

const overdue = computed(
    () =>
        sprint.value?.status === 'active' &&
        (sprint.value.is_overdue ||
            now.value > new Date(sprint.value.deadline_at).getTime()),
);

const label = computed(() => {
    const current = sprint.value;

    if (current === null) {
        return 'No active sprint';
    }

    if (current.status === 'paused') {
        return 'Paused';
    }

    if (overdue.value) {
        return 'Overdue';
    }

    const left = new Date(current.deadline_at).getTime() - now.value;

    return `Day ${current.current_day} of ${current.duration_days} · ${compactDuration(left)} left`;
});
</script>

<template>
    <span
        class="rounded-md border px-2 py-0.5 font-mono text-xs"
        :class="
            overdue
                ? 'border-warning text-warning'
                : 'border-border text-muted-foreground'
        "
    >
        {{ label }}
    </span>
</template>
