<script setup lang="ts">
// The image studio: balance and demo "Buy credits", the Generate form, and the
// user's history. While anything is still running, it polls our own server
// every 3 seconds for fresh statuses and balance (never Pixelforge directly).
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import GenerateForm from '@/components/generations/GenerateForm.vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { store as buyCredits } from '@/routes/credits';
import { index } from '@/routes/generations';
import { ACTIVE_STATUSES } from '@/types/generations';
import type { PaginatedGenerations } from '@/types/generations';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: index() }],
    },
});

const props = defineProps<{
    generations: PaginatedGenerations;
    sizes: string[];
    qualities: string[];
    pricingUnavailable: string | null; // set when Pixelforge's price list can't be loaded
}>();

const page = usePage();

const CREDIT_PACKS = [5, 10, 25];

const hasActiveGenerations = computed(() =>
    props.generations.data.some((generation) =>
        ACTIVE_STATUSES.includes(generation.status),
    ),
);

const { start, stop } = usePoll(
    3000,
    { only: ['generations', 'credits'] },
    { autoStart: false },
);

watch(hasActiveGenerations, (active) => (active ? start() : stop()), {
    immediate: true,
});

function buy(dollars: number): void {
    router.post(buyCredits(), { dollars }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Generate images" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-sm text-muted-foreground">Credit balance</div>
                <div class="text-2xl font-semibold" data-test="credit-balance">
                    {{ page.props.credits }}
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-muted-foreground">
                    Buy credits (demo, no payment):
                </span>
                <Button
                    v-for="dollars in CREDIT_PACKS"
                    :key="dollars"
                    variant="outline"
                    size="sm"
                    @click="buy(dollars)"
                >
                    +${{ dollars }}
                </Button>
            </div>
        </div>

        <Alert v-if="pricingUnavailable" variant="destructive">
            <AlertTitle>Generating is paused</AlertTitle>
            <AlertDescription>{{ pricingUnavailable }}</AlertDescription>
        </Alert>
        <GenerateForm v-else :sizes="sizes" :qualities="qualities" />

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">Your generations</h2>

            <p
                v-if="generations.data.length === 0"
                class="text-sm text-muted-foreground"
            >
                Nothing yet. Write a prompt above to start.
            </p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <GenerationCard
                    v-for="generation in generations.data"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>

            <div class="flex justify-between text-sm">
                <Link
                    v-if="generations.links.prev"
                    :href="generations.links.prev"
                    preserve-scroll
                    class="underline underline-offset-4"
                >
                    ← Newer
                </Link>
                <span v-else />
                <Link
                    v-if="generations.links.next"
                    :href="generations.links.next"
                    preserve-scroll
                    class="underline underline-offset-4"
                >
                    Older →
                </Link>
            </div>
        </section>
    </div>
</template>
