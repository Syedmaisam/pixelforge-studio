<?php

namespace App\Http\Requests;

use App\Pixelforge\PricingCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the "Generate" form with the same limits Pixelforge uses, so users
 * get a clear message from us instead of a 422 from Pixelforge later.
 */
class StoreGenerationRequest extends FormRequest
{
    /**
     * Trim the prompt and drop empty reference-image inputs before validating.
     */
    protected function prepareForValidation(): void
    {
        $urls = array_map(
            fn (mixed $url): mixed => is_string($url) ? trim($url) : $url,
            (array) $this->input('reference_images', []),
        );

        $this->merge([
            'prompt' => trim((string) $this->input('prompt')),
            'reference_images' => array_values(array_filter($urls, fn (mixed $url): bool => $url !== '' && $url !== null)),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(PricingCatalog $pricing): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in($pricing->sizes())],
            'quality' => ['required', Rule::in($pricing->qualities())],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add at most 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
        ];
    }

    /**
     * The validated input in the shape StartGeneration expects.
     *
     * @return array{prompt: string, size: string, quality: string, reference_images: list<string>}
     */
    public function generationInput(): array
    {
        return [
            'prompt' => $this->string('prompt')->toString(),
            'size' => $this->string('size')->toString(),
            'quality' => $this->string('quality')->toString(),
            'reference_images' => array_values(array_map(strval(...), $this->array('reference_images'))),
        ];
    }
}
