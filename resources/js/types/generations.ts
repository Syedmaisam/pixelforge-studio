// Shapes of the data the Laravel controllers send to the studio page.
// All money arrives already formatted by the server: the browser never does money math.

export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Generation = {
    id: number;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimate: string; // "$0.0800 to $0.4673"
    charged: string | null; // "$0.4204", null until finished
    charged_exact: string | null; // "$0.420393750"
    image_url: string | null;
    image_expired: boolean;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
};

export type PaginatedGenerations = {
    data: Generation[];
    links: { prev: string | null; next: string | null };
};

// The answer of GET /generations/estimate.
export type Estimate = {
    min: string;
    max: string;
    can_afford: boolean;
};

// Statuses where Pixelforge is still working, so the page keeps polling.
export const ACTIVE_STATUSES: GenerationStatus[] = [
    'pending',
    'queued',
    'processing',
];
