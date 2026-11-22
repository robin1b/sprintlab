<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SiteFooter from '@/components/site/SiteFooter.vue';
import { home } from '@/routes';

const props = defineProps<{
    updated_on: string;
    contact_email: string | null;
    contact_url: string;
    session_minutes: number;
    webhook_retention_days: number;
    log_retention_days: number;
}>();

const date = computed(() => {
    const [year, month, day] = props.updated_on.split('-').map(Number);

    return new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, day)));
});

const sessionDuration = computed(() => {
    const minutes = props.session_minutes;

    if (minutes % 60 === 0) {
        const hours = minutes / 60;

        return hours === 1 ? '1 hour' : `${hours} hours`;
    }

    return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
});

const mailto = computed(() => `mailto:${props.contact_email}`);

const link = 'underline underline-offset-4 hover:text-primary';
</script>

<template>
    <Head title="Privacy" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="flex h-12 items-center justify-between border-b border-border px-6"
        >
            <Link
                :href="home()"
                class="flex items-center gap-2 font-mono text-sm font-medium"
            >
                <span class="text-primary">▸</span>
                sprintlab
            </Link>
        </header>

        <main class="flex-1 px-6 py-12">
            <article
                class="mx-auto w-full max-w-2xl leading-7 text-foreground/80 [&_h2]:mt-10 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-foreground [&_li]:pl-1 [&_p]:mt-3 [&_strong]:font-medium [&_strong]:text-foreground [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:pl-5"
            >
                <h1
                    class="text-3xl font-semibold tracking-tight text-foreground"
                >
                    Privacy
                </h1>
                <p>
                    Sprintlab is a practice platform. This page explains in
                    plain language which data it keeps about you, why, and what
                    you can do about it. Last updated on {{ date }}.
                </p>

                <h2>Who is responsible?</h2>
                <p>
                    Robin Broos, web developer from Kontich (Belgium), runs
                    Sprintlab and is responsible for the data described here.
                    Questions about privacy?
                    <template v-if="contact_email">
                        Mail
                        <a :href="mailto" :class="link">{{ contact_email }}</a
                        >.
                    </template>
                    <template v-else>
                        Use the
                        <a :href="contact_url" :class="link"
                            >contact page on robinbroos.com</a
                        >.
                    </template>
                </p>

                <h2>What Sprintlab keeps</h2>
                <ul>
                    <li>
                        <strong>Your account.</strong> You sign in with GitHub.
                        Sprintlab receives and stores your GitHub id, username,
                        name, email address and the address of your avatar. It
                        never sees your GitHub password and does not keep a
                        GitHub access token for your account.
                    </li>
                    <li>
                        <strong>Your practice repository.</strong> When you
                        install the Sprintlab GitHub App on a repository,
                        Sprintlab stores the name of that repository and reads
                        its pull requests: titles, descriptions, the changed
                        code and the review comments. The app only has access to
                        the repositories you choose, and you can remove it at
                        any time in your GitHub settings. Sprintlab only looks
                        at your practice repository. If the app can see more
                        repositories, Sprintlab ignores what happens in the
                        others and stores nothing about them.
                    </li>
                    <li>
                        <strong>Your work in a sprint.</strong> The tickets you
                        moved, the chat messages you sent and received, the
                        reviews on your pull requests, the reviews you wrote
                        yourself, your answers to the evaluation questions, and
                        the scores and evaluation that came out of it.
                    </li>
                    <li>
                        <strong>Technical data.</strong> While you are signed
                        in, your session holds your IP address and browser type.
                        The server keeps log files (time, requested page,
                        errors) to keep the site safe and working. Messages that
                        GitHub sends to Sprintlab about your repository are kept
                        for {{ webhook_retention_days }} days to trace problems.
                    </li>
                </ul>

                <h2>Why Sprintlab uses this data</h2>
                <ul>
                    <li>
                        To run the simulation: without your pull requests and
                        messages there is nothing to review and nobody to reply
                        to.
                    </li>
                    <li>
                        To show your progress: scores, evaluations and your
                        level.
                    </li>
                    <li>
                        To keep the platform safe and to keep the cost of the AI
                        under control (Sprintlab counts how many AI replies an
                        account uses).
                    </li>
                </ul>
                <p>
                    Sprintlab does this because you ask for the service when you
                    sign in, and because there is a legitimate interest in a
                    site that works safely. Your data is not used for
                    newsletters or advertising, and it is never sold.
                </p>

                <h2>AI colleagues</h2>
                <p>
                    Every colleague in Sprintlab is AI (Claude, made by
                    Anthropic). None of them is a real person. To write a
                    review, a chat reply or an evaluation, Sprintlab sends the
                    text that is needed to Anthropic: the title, branch name,
                    description and changed code of your pull request, the
                    messages in that chat, your review comments, your evaluation
                    answers and your first name. Your email address and your
                    GitHub username are not sent.
                </p>
                <p>
                    Anthropic is based in the United States. The transfer is
                    covered by the standard contractual clauses of the European
                    Commission, and Anthropic does not use this text to train
                    its models.
                </p>
                <p>
                    Scores are calculated by Sprintlab itself, with fixed rules,
                    not by the AI. Nothing here is a decision with legal
                    consequences: it is practice.
                </p>

                <h2>Who else receives data</h2>
                <ul>
                    <li>
                        <strong>Hostinger</strong>, the hosting provider: the
                        site and the database run on its servers.
                    </li>
                    <li>
                        <strong>GitHub</strong>: you sign in through GitHub, and
                        reviews are posted in your repository as comments by the
                        Sprintlab app. Those comments live on GitHub, under
                        GitHub's own terms. If your repository is public, they
                        are public too.
                    </li>
                    <li><strong>Anthropic</strong>, as described above.</li>
                </ul>
                <p>They may only use the data to deliver their service.</p>

                <h2>Cookies</h2>
                <p>
                    Sprintlab places three cookies, and all three are needed for
                    the site to work: the session cookie (keeps you signed in),
                    a security cookie (XSRF-TOKEN, protects forms against abuse)
                    and sidebar_state (remembers whether the menu is open or
                    closed, for 7 days). The first two expire
                    {{ sessionDuration }} after your last visit.
                </p>
                <p>
                    There are no advertising or tracking cookies, and no outside
                    services such as Google Analytics or Google Fonts. That is
                    why you see no cookie banner.
                </p>
                <p>
                    When you finish or skip the intro video on the home page,
                    your browser remembers that with one entry in its local
                    storage, so the video does not start again. The entry says
                    nothing about you and is never sent to Sprintlab.
                </p>

                <h2>How long data is kept</h2>
                <ul>
                    <li>
                        Your account and everything in your sprints: until you
                        delete your account. In Settings you can delete your
                        account yourself. That removes your account, your
                        sprints, messages, reviews and evaluations at once, and
                        removes the Sprintlab app from your repository.
                    </li>
                    <li>
                        Reviews that were already posted on GitHub stay in your
                        repository on GitHub. You can delete them there.
                    </li>
                    <li>
                        Messages from GitHub to Sprintlab:
                        {{ webhook_retention_days }} days. Log files of the
                        application: {{ log_retention_days }} days.
                    </li>
                    <li>
                        Counters of AI use (number of calls, tokens and cost,
                        never the text) are kept for the bookkeeping, without a
                        link to you once your account is gone.
                    </li>
                </ul>

                <h2>Your rights</h2>
                <p>
                    You can ask to see your data, to have it corrected or
                    deleted, to receive a copy, or object to its use.
                    <template v-if="contact_email">
                        Mail
                        <a :href="mailto" :class="link">{{ contact_email }}</a>
                    </template>
                    <template v-else>
                        Use the
                        <a :href="contact_url" :class="link"
                            >contact page on robinbroos.com</a
                        >
                    </template>
                    and you get an answer within a month. Not happy with the
                    answer? You can file a complaint with the Belgian Data
                    Protection Authority (<a
                        href="https://www.dataprotectionauthority.be"
                        :class="link"
                        rel="noopener noreferrer"
                        >dataprotectionauthority.be</a
                    >).
                </p>

                <h2>Changes</h2>
                <p>
                    When Sprintlab changes, this page changes with it. The date
                    at the top shows the last update.
                </p>
            </article>
        </main>

        <SiteFooter />
    </div>
</template>
