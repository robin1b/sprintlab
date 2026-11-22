<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import type { User } from '@/types';

const props = defineProps<{
    user: Pick<User, 'name' | 'avatar'> & Partial<Pick<User, 'level_label'>>;
}>();

const { getInitials } = useInitials();

// Compute whether we should show the avatar image
const showAvatar = computed(
    () => props.user.avatar && props.user.avatar !== '',
);
</script>

<template>
    <Avatar class="h-8 w-8 overflow-hidden rounded-md">
        <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="user.name" />
        <AvatarFallback
            class="rounded-md bg-secondary font-mono text-xs text-foreground"
        >
            {{ getInitials(user.name) }}
        </AvatarFallback>
    </Avatar>

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="flex items-center gap-2">
            <span class="truncate font-medium">{{ user.name }}</span>
            <span
                v-if="user.level_label"
                class="shrink-0 rounded-sm border border-border px-1.5 font-mono text-xs text-muted-foreground"
                data-test="level-chip"
            >
                {{ user.level_label }}
            </span>
        </span>
    </div>
</template>
