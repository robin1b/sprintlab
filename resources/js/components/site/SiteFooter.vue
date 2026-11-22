<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { privacy } from '@/routes';

const year = new Date().getFullYear();
</script>

<template>
    <footer
        class="flex items-center justify-center gap-3 px-6 py-4 font-mono text-xs text-muted-foreground"
    >
        <span>&copy; {{ year }} Sprintlab</span>
        <Link :href="privacy()" class="hover:text-foreground">Privacy</Link>
    </footer>
</template>
