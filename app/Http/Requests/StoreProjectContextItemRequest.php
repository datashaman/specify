<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
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
            'file.extensions' => 'The context file must use one of these extensions: '.implode(', ', $this->allowedUploadExtensions()).'.',
        ];
    }

    private function maxUploadSizeInKilobytes(): int
    {
        return max(1, (int) config('specify.context_items.uploads.max_file_size_kilobytes', 10240));
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
