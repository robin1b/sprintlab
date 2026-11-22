<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import SiteFooter from '@/components/site/SiteFooter.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center bg-background p-6 text-foreground md:p-10"
    >
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-6">
                <Link
                    :href="home()"
                    class="flex items-center gap-2 self-center font-mono text-sm font-medium"
                >
                    <span class="text-primary">▸</span>
                    sprintlab
                </Link>
                <div
                    class="flex flex-col gap-6 rounded-md border border-border bg-card p-6"
                >
                    <div v-if="title || description" class="space-y-1">
                        <h1 v-if="title" class="text-lg font-semibold">
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="text-sm text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </div>
            </div>
        </div>
        <SiteFooter class="mt-6" />
    </div>
</template>
