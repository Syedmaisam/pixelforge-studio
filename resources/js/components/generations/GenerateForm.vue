<script setup lang="ts">
// The "Generate" form. It shows the price range BEFORE submitting, using a
// debounced call to the server's estimate endpoint; the browser does no money math.
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { estimate as estimateRoute, store } from '@/routes/generations';
import type { Estimate } from '@/types/generations';

const MAX_REFERENCE_IMAGES = 4;
const MAX_PROMPT_CHARACTERS = 4000;

// Bound with :placeholder on purpose: vue-tsc 3.3 mis-reads a plain "https://..."
// attribute on a component inside v-for (the "//" breaks its generated code).
const REFERENCE_IMAGE_PLACEHOLDER = 'https://example.com/style.png';

const props = defineProps<{ sizes: string[]; qualities: string[] }>();
const page = usePage();

const form = useForm({
    prompt: '',
    size: props.sizes[0] ?? '1024x1024',
    quality: props.qualities.includes('medium') ? 'medium' : props.qualities[0],
    reference_images: [] as string[],
});

const estimate = ref<Estimate | null>(null);
const estimateFailed = ref(false);

// Count Unicode characters the same way PHP's mb_strlen does
// (Array.from splits by code point, so an emoji counts as 1).
const characters = computed(() => Array.from(form.prompt.trim()).length);
const filledReferenceImages = computed(
    () => form.reference_images.filter((url) => url.trim() !== '').length,
);

// The server checks the balance again on submit; this just avoids a pointless click.
const canSubmit = computed(
    () =>
        !form.processing &&
        characters.value > 0 &&
        estimate.value?.can_afford !== false,
);

// "credits" (balance check) and "reference_images.0" (URL checks) are not
// form fields of their own, so read them from a plain record.
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);
const referenceImageErrors = computed(() =>
    Object.entries(errors.value)
        .filter(([field]) => field.startsWith('reference_images'))
        .map(([, message]) => message),
);

// Ask the server for a fresh estimate 300 ms after the last change.
// The balance is watched too, so "can afford" updates right after buying credits.
let timer: ReturnType<typeof setTimeout> | undefined;
let latestRequest = 0;

watch(
    [
        characters,
        () => form.size,
        () => form.quality,
        filledReferenceImages,
        () => page.props.credits,
    ],
    () => {
        clearTimeout(timer);
        timer = setTimeout(loadEstimate, 300);
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(timer));

async function loadEstimate(): Promise<void> {
    const request = ++latestRequest;
    const url = estimateRoute.url({
        query: {
            characters: characters.value,
            size: form.size,
            quality: form.quality,
            reference_images: filledReferenceImages.value,
        },
    });

    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
        });

        if (request !== latestRequest) {
            return; // an answer to an older question; a newer one is on its way
        }

        estimateFailed.value = !response.ok;
        estimate.value = response.ok ? await response.json() : null;
    } catch {
        if (request === latestRequest) {
            estimateFailed.value = true;
        }
    }
}

function addReferenceImage(): void {
    if (form.reference_images.length < MAX_REFERENCE_IMAGES) {
        form.reference_images.push('');
    }
}

function removeReferenceImage(index: number): void {
    form.reference_images.splice(index, 1);
}

function submit(): void {
    form.submit(store(), {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt', 'reference_images'),
    });
}
</script>

<template>
    <form
        class="flex flex-col gap-5 rounded-xl border bg-card p-5 shadow-sm"
        @submit.prevent="submit"
    >
        <div class="grid gap-2">
            <Label for="prompt">Prompt</Label>
            <textarea
                id="prompt"
                v-model="form.prompt"
                rows="3"
                :maxlength="MAX_PROMPT_CHARACTERS"
                placeholder="A lighthouse on a cliff at dawn, oil painting"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
            />
            <div
                class="flex justify-between gap-4 text-xs text-muted-foreground"
            >
                <InputError v-if="errors.prompt" :message="errors.prompt" />
                <span v-else>
                    Tip: add [fail], [slow] or [duplicate] to test edge cases.
                </span>
                <span class="shrink-0">
                    {{ characters }} / {{ MAX_PROMPT_CHARACTERS }}
                </span>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="size">Size</Label>
                <Select v-model="form.size">
                    <SelectTrigger id="size" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="size in sizes"
                            :key="size"
                            :value="size"
                        >
                            {{ size }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="errors.size" />
            </div>

            <div class="grid gap-2">
                <Label>Quality</Label>
                <div class="flex gap-2">
                    <Button
                        v-for="quality in qualities"
                        :key="quality"
                        type="button"
                        class="flex-1 capitalize"
                        :variant="
                            form.quality === quality ? 'default' : 'outline'
                        "
                        :aria-pressed="form.quality === quality"
                        @click="form.quality = quality"
                    >
                        {{ quality }}
                    </Button>
                </div>
                <InputError :message="errors.quality" />
            </div>
        </div>

        <div class="grid gap-2">
            <div class="flex items-center justify-between">
                <Label>
                    Reference images
                    <span class="font-normal text-muted-foreground">
                        (optional, up to {{ MAX_REFERENCE_IMAGES }})
                    </span>
                </Label>
                <Button
                    v-if="form.reference_images.length < MAX_REFERENCE_IMAGES"
                    type="button"
                    variant="link"
                    size="sm"
                    @click="addReferenceImage"
                >
                    + Add URL
                </Button>
            </div>
            <div
                v-for="(_url, index) in form.reference_images"
                :key="index"
                class="flex gap-2"
            >
                <Input
                    v-model="form.reference_images[index]"
                    type="url"
                    :placeholder="REFERENCE_IMAGE_PLACEHOLDER"
                    :aria-label="`Reference image ${index + 1}`"
                />
                <Button
                    type="button"
                    variant="ghost"
                    @click="removeReferenceImage(index)"
                >
                    Remove
                </Button>
            </div>
            <InputError
                v-for="message in referenceImageErrors"
                :key="message"
                :message="message"
            />
        </div>

        <!-- Cost, shown before submitting -->
        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-muted p-4"
        >
            <div class="text-sm" aria-live="polite">
                <template v-if="estimate">
                    <div>
                        Estimated cost:
                        <strong
                            >{{ estimate.min }} to {{ estimate.max }}</strong
                        >
                    </div>
                    <div class="text-xs text-muted-foreground">
                        You pay the real cost once it finishes, never more than
                        {{ estimate.max }}. Failed generations are free.
                    </div>
                </template>
                <span
                    v-else-if="estimateFailed"
                    class="text-red-600 dark:text-red-400"
                >
                    Couldn't load the price right now.
                </span>
                <span v-else class="text-muted-foreground"
                    >Calculating price…</span
                >
            </div>

            <Button type="submit" :disabled="!canSubmit">
                {{ form.processing ? 'Starting…' : 'Generate' }}
            </Button>
        </div>

        <InputError
            v-if="estimate?.can_afford === false && !errors.credits"
            message="Not enough credits for this generation. Buy credits above."
        />
        <InputError :message="errors.credits" />
    </form>
</template>
