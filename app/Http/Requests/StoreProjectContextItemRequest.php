<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreProjectContextItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->canManageProject($project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $allowedExtensions = $this->allowedUploadExtensions();

        return [
            'type' => ['required', Rule::in(['file', 'link', 'text'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'file' => [
                'exclude_unless:type,file',
                'required',
                'file',
                'max:'.$this->maxUploadSizeInKilobytes(),
                'mimes:'.implode(',', $allowedExtensions),
                'extensions:'.implode(',', $allowedExtensions),
            ],
            'url' => ['exclude_unless:type,link', 'required', 'url', 'max:2048'],
            'body' => ['exclude_unless:type,text', 'required', 'string', 'max:200000'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'The context file must not be larger than '.$this->maxUploadSizeInKilobytes().' kilobytes.',
            'file.mimes' => 'The context file must use one of these file types: '.implode(', ', $this->allowedUploadExtensions()).'.',
            'file.extensions' => 'The context file must use one of these extensions: '.implode(', ', $this->allowedUploadExtensions()).'.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $failedFileRules = $validator->failed()['file'] ?? [];

        if ($failedFileRules !== []) {
            throw new HttpResponseException(response()->json([
                'message' => 'The context file failed upload validation.',
                'errors' => $validator->errors()->toArray(),
                'error' => [
                    'code' => 'context_item_upload_rejected',
                    'field' => 'file',
                    'violations' => $this->uploadViolationsFor($failedFileRules),
                ],
            ], 422));
        }

        parent::failedValidation($validator);
    }

    private function maxUploadSizeInKilobytes(): int
    {
        return max(1, (int) config('specify.context_items.uploads.max_file_size_kilobytes', 10240));
    }

    /**
     * @param  array<string, mixed>  $failedRules
     * @return list<array<string, mixed>>
     */
    private function uploadViolationsFor(array $failedRules): array
    {
        $violations = [];

        if (array_key_exists('Max', $failedRules)) {
            $violations[] = [
                'rule' => 'max_file_size',
                'limit' => [
                    'kilobytes' => $this->maxUploadSizeInKilobytes(),
                    'bytes' => $this->maxUploadSizeInKilobytes() * 1024,
                ],
                'actual' => [
                    'kilobytes' => $this->uploadedFileSizeInKilobytes(),
                    'bytes' => $this->file('file')?->getSize(),
                ],
            ];
        }

        if (array_key_exists('Mimes', $failedRules)) {
            $violations[] = [
                'rule' => 'allowed_file_type',
                'limit' => [
                    'allowed_extensions' => $this->allowedUploadExtensions(),
                ],
                'actual' => [
                    'mime_type' => $this->file('file')?->getMimeType(),
                    'client_mime_type' => $this->file('file')?->getClientMimeType(),
                ],
            ];
        }

        if (array_key_exists('Extensions', $failedRules)) {
            $violations[] = [
                'rule' => 'allowed_extension',
                'limit' => [
                    'allowed_extensions' => $this->allowedUploadExtensions(),
                ],
                'actual' => [
                    'extension' => strtolower($this->file('file')?->getClientOriginalExtension() ?? ''),
                ],
            ];
        }

        return $violations;
    }

    private function uploadedFileSizeInKilobytes(): ?int
    {
        $size = $this->file('file')?->getSize();

        if ($size === null) {
            return null;
        }

        return (int) ceil($size / 1024);
    }

    /**
     * @return list<string>
     */
    private function allowedUploadExtensions(): array
    {
        $extensions = config('specify.context_items.uploads.allowed_extensions', []);

        if (! is_array($extensions)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $extension): string => strtolower(trim((string) $extension)),
            $extensions,
        )));
    }
}
