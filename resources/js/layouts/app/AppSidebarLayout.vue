<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { toast } from 'vue-sonner';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// A refused action (for example "This sprint is not active.") comes back as a
// flash error. The flash object is new on every response, so the same message
// shows again when it happens again.
const page = usePage();

// A plain #main link would also change the address; focus the element instead.
function focusMain(): void {
    const main = document.getElementById('main');

    main?.focus();
    main?.scrollIntoView();
}

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.error) {
            toast.error(flash.error);
        }
    },
);
</script>

<template>
    <AppShell>
        <!-- First focusable element: visible on focus only. -->
        <a
            href="#main"
            class="fixed top-2 left-2 z-50 -translate-y-20 rounded-md border border-border bg-card px-3 py-2 text-sm text-foreground focus:translate-y-0 focus:ring-2 focus:ring-ring focus:outline-none"
            @click.prevent="focusMain"
        >
            Skip to content
        </a>
        <AppSidebar />
        <AppContent
            id="main"
            tabindex="-1"
            class="min-w-0 overflow-x-clip focus:outline-none"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
