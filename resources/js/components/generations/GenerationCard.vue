<script setup lang="ts">
// One generation in the history grid: the image (or why there isn't one),
// its status, the prompt, and what it was estimated to cost vs. what was charged.
import { computed, ref } from 'vue';
import type { Generation } from '@/types/generations';
import StatusBadge from './StatusBadge.vue';

const props = defineProps<{ generation: Generation }>();

// Pixelforge links expire; if the browser can't load the image any more,
// treat it the same as an expired link.
const imageFailedToLoad = ref(false);

const showImage = computed(
    () =>
        props.generation.status === 'succeeded' &&
        props.generation.image_url !== null &&
        !imageFailedToLoad.value,
);

const imageExpired = computed(
    () =>
        props.generation.status === 'succeeded' &&
        (props.generation.image_expired || imageFailedToLoad.value),
);
</script>

<template>
    <article class="overflow-hidden rounded-xl border bg-card shadow-sm">
        <div
            class="flex aspect-square items-center justify-center bg-muted text-center text-sm text-muted-foreground"
        >
            <img
                v-if="showImage"
                :src="generation.image_url ?? undefined"
                :alt="generation.prompt"
                loading="lazy"
                class="size-full object-cover"
                @error="imageFailedToLoad = true"
            />
            <span v-else-if="imageExpired" class="px-4">Image expired</span>
            <span
                v-else-if="generation.status === 'failed'"
                class="px-4 text-red-600 dark:text-red-400"
            >
                {{ generation.error_message }}
            </span>
            <span v-else class="animate-pulse px-4">Generating…</span>
        </div>

        <div class="flex flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <StatusBadge :status="generation.status" />
                <span class="text-xs text-muted-foreground">
                    {{ generation.size }} · {{ generation.quality }}
                </span>
            </div>

            <p class="line-clamp-2 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <dl class="grid grid-cols-2 gap-2 text-xs">
                <div>
                    <dt class="text-muted-foreground">Estimated</dt>
                    <dd>{{ generation.estimate }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Charged</dt>
                    <dd
                        v-if="generation.charged !== null"
                        class="font-semibold"
                        :title="`Exact: ${generation.charged_exact}`"
                    >
                        {{ generation.charged }}
                        <span
                            v-if="generation.status === 'failed'"
                            class="font-normal text-muted-foreground"
                        >
                            (free)
                        </span>
                    </dd>
                    <dd v-else class="text-muted-foreground">when finished</dd>
                </div>
            </dl>
        </div>
    </article>
</template>
