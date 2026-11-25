<script setup lang="ts">
import CodeSample from '@/components/courses/CodeSample.vue';
import InlineText from '@/components/courses/InlineText.vue';
import type { CourseBlock } from '@/types';

defineProps<{ block: CourseBlock }>();
</script>

<template>
    <p v-if="block.type === 'text'" class="leading-7 text-foreground/80">
        <InlineText :text="block.text" />
    </p>

    <h3
        v-else-if="block.type === 'heading'"
        class="pt-2 text-base font-semibold text-foreground"
    >
        <InlineText :text="block.text" />
    </h3>

    <ul
        v-else-if="block.type === 'list'"
        class="list-disc space-y-2 pl-5 leading-7 text-foreground/80"
    >
        <li v-for="(item, index) in block.items" :key="index" class="pl-1">
            <InlineText :text="item" />
        </li>
    </ul>

    <ol
        v-else-if="block.type === 'steps'"
        class="list-decimal space-y-2 pl-5 leading-7 text-foreground/80 marker:font-mono marker:text-muted-foreground"
    >
        <li v-for="(item, index) in block.items" :key="index" class="pl-1">
            <InlineText :text="item" />
        </li>
    </ol>

    <CodeSample
        v-else-if="block.type === 'code'"
        :code="block.code"
        :language="block.language"
        :caption="block.caption"
    />

    <div
        v-else-if="block.type === 'table'"
        class="overflow-x-auto rounded-md border border-border"
    >
        <table class="w-full min-w-[28rem] border-collapse text-left text-sm">
            <thead class="bg-card">
                <tr>
                    <th
                        v-for="(header, index) in block.headers"
                        :key="index"
                        scope="col"
                        class="border-b border-border px-3 py-2 font-mono text-xs font-medium text-muted-foreground"
                    >
                        <InlineText :text="header" />
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(row, rowIndex) in block.rows"
                    :key="rowIndex"
                    class="border-b border-border last:border-b-0"
                >
                    <td
                        v-for="(cell, cellIndex) in row"
                        :key="cellIndex"
                        class="px-3 py-2 align-top leading-6 text-foreground/80"
                    >
                        <InlineText :text="cell" />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <aside
        v-else-if="block.type === 'note'"
        class="rounded-md border border-l-2 border-border border-l-primary bg-card p-4"
    >
        <p class="font-mono text-xs text-primary">{{ block.label }}</p>
        <p class="mt-2 leading-7 text-foreground/80">
            <InlineText :text="block.text" />
        </p>
    </aside>
</template>
