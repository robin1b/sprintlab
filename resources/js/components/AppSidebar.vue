<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Gauge,
    GraduationCap,
    KanbanSquare,
    LayoutGrid,
    MessageSquare,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { board, chat, dashboard, privacy } from '@/routes';
import { usage } from '@/routes/admin';
import { index as courses } from '@/routes/courses';
import { restart } from '@/routes/tour';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Overview', href: dashboard(), icon: LayoutGrid },
    { title: 'Board', href: board(), icon: KanbanSquare, tour: 'nav-board' },
    {
        title: 'Chat',
        href: chat(),
        icon: MessageSquare,
        badge: page.props.unreadMessages,
        tour: 'nav-chat',
    },
    { title: 'Courses', href: courses(), icon: GraduationCap },
]);

function replayTour(): void {
    router.delete(restart.url(), {
        onSuccess: () => router.visit(dashboard({ query: { tour: 1 } }).url),
    });
}

const adminNavItems = computed<NavItem[]>(() => [
    { title: 'AI usage', href: usage(), icon: Gauge },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="page.props.auth.is_admin"
                :items="adminNavItems"
                label="admin"
                class="mt-4"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
            <Link
                :href="privacy()"
                class="px-2 pb-1 font-mono text-xs text-muted-foreground group-data-[collapsible=icon]:hidden hover:text-foreground"
            >
                Privacy
            </Link>
            <button
                type="button"
                class="px-2 pb-1 text-left font-mono text-xs text-muted-foreground group-data-[collapsible=icon]:hidden hover:text-foreground"
                @click="replayTour"
            >
                Replay the tour
            </button>
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
