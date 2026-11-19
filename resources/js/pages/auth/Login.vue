<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { redirect as githubRedirect } from '@/routes/auth/github';

defineOptions({
    layout: {
        title: 'Sign in to Sprintlab',
        description: 'Sign in with your GitHub account to continue',
    },
});

defineProps<{
    devLogin?: boolean;
}>();

// The dev login route only exists locally, so it is not imported from the
// generated routes (they would be missing in a production build).
const page = usePage();
const error = computed(() => page.props.flash.error);
</script>

<template>
    <Head title="Sign in" />

    <div class="flex flex-col gap-6">
        <div
            v-if="error"
            role="alert"
            class="text-center text-sm font-medium text-destructive"
            data-test="login-error"
        >
            {{ error }}
        </div>

        <Button as-child class="w-full" data-test="github-login-button">
            <a :href="githubRedirect.url()">Continue with GitHub</a>
        </Button>

        <p class="text-center text-sm text-muted-foreground">
            Sprintlab reviews your pull requests on GitHub, so we need you to
            sign in with your GitHub account.
        </p>

        <div v-if="devLogin" class="text-center text-sm">
            <a
                href="/dev/login"
                class="text-muted-foreground underline underline-offset-4 hover:text-foreground"
                data-test="dev-login-link"
                >Dev login (local only)</a
            >
        </div>
    </div>
</template>
