<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Gauge, KanbanSquare, LayoutGrid, MessageSquare } from '@lucide/vue';
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
import { board, chat, dashboard } from '@/routes';
import { usage } from '@/routes/admin';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Overview', href: dashboard(), icon: LayoutGrid },
    { title: 'Board', href: board(), icon: KanbanSquare },
    {
        title: 'Chat',
        href: chat(),
        icon: MessageSquare,
        badge: page.props.unreadMessages,
    },
]);

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
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
