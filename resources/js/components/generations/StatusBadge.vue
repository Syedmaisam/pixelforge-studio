<script setup lang="ts">
// A coloured status pill, with a pulsing dot while Pixelforge is still working.
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { ACTIVE_STATUSES } from '@/types/generations';
import type { GenerationStatus } from '@/types/generations';

const props = defineProps<{ status: GenerationStatus }>();

const styles: Record<GenerationStatus, { label: string; classes: string }> = {
    pending: {
        label: 'Sending',
        classes: 'bg-muted text-muted-foreground',
    },
    queued: {
        label: 'Queued',
        classes:
            'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    },
    processing: {
        label: 'Processing',
        classes:
            'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    },
    succeeded: {
        label: 'Succeeded',
        classes:
            'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300',
    },
    failed: {
        label: 'Failed',
        classes: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    },
};

const style = computed(() => styles[props.status]);
const isActive = computed(() => ACTIVE_STATUSES.includes(props.status));
</script>

<template>
    <Badge class="border-transparent" :class="style.classes">
        <span
            v-if="isActive"
            class="size-1.5 animate-pulse rounded-full bg-current"
        />
        {{ style.label }}
    </Badge>
</template>
