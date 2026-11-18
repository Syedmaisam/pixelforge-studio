<script setup lang="ts">
// Admin report: per UTC day and per user, what users were charged, what
// Pixelforge charged us, and the profit. Each day's provider cost is compared
// exactly with Pixelforge's own GET /v1/usage. All amounts arrive formatted.
import { Head, Link } from '@inertiajs/vue3';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { report } from '@/routes/admin';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: report() }],
    },
});

type Totals = {
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
};
type DayRow = Totals & {
    date: string;
    pixelforge_cost: string | null;
    matches_pixelforge: boolean | null;
};
type UserRow = Totals & { date: string; user: string };

defineProps<{
    days: DayRow[];
    by_user: UserRow[];
    pixelforge_available: boolean;
    range: { from: string; to: string; days: number };
}>();

const RANGES = [7, 14, 30, 92];
</script>

<template>
    <Head title="Admin report" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-8 p-4">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">
                    Spend, provider cost and profit
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ range.from }} to {{ range.to }}, UTC. A generation counts
                    on the day it completed, the same way Pixelforge's /v1/usage
                    does.
                </p>
            </div>
            <nav class="flex gap-2 text-sm" aria-label="Report range">
                <Link
                    v-for="days in RANGES"
                    :key="days"
                    :href="report({ query: { days } })"
                    class="rounded-md border px-2 py-1"
                    :class="{
                        'bg-primary text-primary-foreground':
                            days === range.days,
                    }"
                >
                    {{ days }} days
                </Link>
            </nav>
        </header>

        <Alert v-if="!pixelforge_available">
            <AlertDescription>
                Pixelforge's usage report couldn't be loaded right now, so the
                comparison column is empty. Refresh to try again.
            </AlertDescription>
        </Alert>

        <section class="flex flex-col gap-2">
            <h2 class="font-semibold">Per day</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-4">Date</th>
                            <th class="pr-4">Generations</th>
                            <th class="pr-4">Charged to users</th>
                            <th class="pr-4">Provider cost (ours)</th>
                            <th class="pr-4">Provider cost (Pixelforge)</th>
                            <th>Profit</th>
                        </tr>
                    </thead>
                    <tbody class="font-mono">
                        <tr
                            v-for="day in days"
                            :key="day.date"
                            class="border-b"
                        >
                            <td class="py-2 pr-4">{{ day.date }}</td>
                            <td class="pr-4">{{ day.generations }}</td>
                            <td class="pr-4">{{ day.charged }}</td>
                            <td class="pr-4">{{ day.provider_cost }}</td>
                            <td class="pr-4">
                                {{ day.pixelforge_cost ?? 'n/a' }}
                                <span
                                    v-if="day.matches_pixelforge === true"
                                    class="font-sans text-green-700 dark:text-green-400"
                                >
                                    ✓ match
                                </span>
                                <span
                                    v-else-if="day.matches_pixelforge === false"
                                    class="font-sans text-red-600 dark:text-red-400"
                                >
                                    ✗ mismatch
                                </span>
                            </td>
                            <td>{{ day.profit }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="font-semibold">Per day and user</h2>
            <p
                v-if="by_user.length === 0"
                class="text-sm text-muted-foreground"
            >
                No billed generations in this range.
            </p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-4">Date</th>
                            <th class="pr-4">User</th>
                            <th class="pr-4">Generations</th>
                            <th class="pr-4">Charged</th>
                            <th class="pr-4">Provider cost</th>
                            <th>Profit</th>
                        </tr>
                    </thead>
                    <tbody class="font-mono">
                        <tr
                            v-for="row in by_user"
                            :key="row.date + row.user"
                            class="border-b"
                        >
                            <td class="py-2 pr-4">{{ row.date }}</td>
                            <td class="pr-4 font-sans">{{ row.user }}</td>
                            <td class="pr-4">{{ row.generations }}</td>
                            <td class="pr-4">{{ row.charged }}</td>
                            <td class="pr-4">{{ row.provider_cost }}</td>
                            <td>{{ row.profit }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
