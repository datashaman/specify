<?php

use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Project context')] class extends Component {
    public int $project_id;

    public function mount(int $project): void
    {
        $this->project_id = $project;

        abort_unless($this->project, 404);
    }

    #[Computed]
    public function project(): ?Project
    {
        return Project::query()
            ->whereIn('id', Auth::user()->accessibleProjectIds())
            ->with('team.workspace')
            ->find($this->project_id);
    }

    #[Computed]
    public function canManage(): bool
    {
        return $this->project && Auth::user()->canManageProject($this->project);
    }

};
?>

<div class="flex flex-col gap-6 p-6">
    @if (! $this->project)
        <flux:text class="text-zinc-500">{{ __('Project not found.') }}</flux:text>
    @else
        @php($canManage = $this->canManage)
        @php($allowedUploadExtensions = array_values(array_filter(array_map(
            fn (mixed $extension): string => strtolower(trim((string) $extension)),
            config('specify.context_items.uploads.allowed_extensions', []),
        ))))
        @php($maxUploadSizeInKilobytes = max(1, (int) config('specify.context_items.uploads.max_file_size_kilobytes', 10240)))

        <div class="flex flex-col gap-3">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('projects.show', $this->project)" wire:navigate>
                    {{ $this->project->name }}
                </flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Context') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ __('Project context') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-500">
                    {{ $this->project->team->workspace->name }} / {{ $this->project->team->name }}
                </flux:text>
            </div>
        </div>

        <section
            class="flex flex-col gap-3"
            x-data="{
                endpoint: {{ \Illuminate\Support\Js::from(route('projects.context-items.index', $this->project)) }},
                csrfToken: {{ \Illuminate\Support\Js::from(csrf_token()) }},
                errorMessage: {{ \Illuminate\Support\Js::from(__('Context items could not be loaded.')) }},
                createErrorMessage: {{ \Illuminate\Support\Js::from(__('Context item could not be added.')) }},
                updateErrorMessage: {{ \Illuminate\Support\Js::from(__('Context item could not be updated.')) }},
                deleteErrorMessage: {{ \Illuminate\Support\Js::from(__('Context item could not be deleted.')) }},
                uploadTooLargeMessage: {{ \Illuminate\Support\Js::from(__('The context file is too large.')) }},
                uploadTypeMessage: {{ \Illuminate\Support\Js::from(__('This file type is not allowed.')) }},
                uploadChooseAnotherMessage: {{ \Illuminate\Support\Js::from(__('Choose another file and try again.')) }},
                uploadAllowedExtensionsMessage: {{ \Illuminate\Support\Js::from(__('Allowed file types')) }},
                uploadMaxSizeMessage: {{ \Illuminate\Support\Js::from(__('Maximum size')) }},
                defaultType: {{ \Illuminate\Support\Js::from(__('Context')) }},
                canManage: {{ \Illuminate\Support\Js::from($canManage) }},
                uploadLimits: {
                    maxFileSizeKilobytes: {{ \Illuminate\Support\Js::from($maxUploadSizeInKilobytes) }},
                    maxFileSizeBytes: {{ \Illuminate\Support\Js::from($maxUploadSizeInKilobytes * 1024) }},
                    allowedExtensions: {{ \Illuminate\Support\Js::from($allowedUploadExtensions) }},
                },
                contextItems: Array(),
                error: null,
                loading: true,
                saving: false,
                updating: false,
                deleting: false,
                form: {
                    type: 'file',
                    title: '',
                    description: '',
                    url: '',
                    body: '',
                },
                editForm: {
                    id: null,
                    title: '',
                    description: '',
                },
                deleteForm: {
                    id: null,
                    title: '',
                },
                fieldErrors: {},
                editFieldErrors: {},
                createError: null,
                editError: null,
                deleteError: null,
                async load() {
                    this.loading = true;
                    this.error = null;

                    try {
                        const response = await fetch(this.endpoint, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });

                        if (! response.ok) {
                            throw new Error(`${this.errorMessage} (${response.status})`);
                        }

                        const payload = await response.json();
                        this.contextItems = Array.isArray(payload.data) ? payload.data : Array();
                        this.canManage = Boolean(payload.meta?.can_manage_project);
                    } catch (error) {
                        this.contextItems = Array();
                        this.error = error.message || this.errorMessage;
                    } finally {
                        this.loading = false;
                    }
                },
                resetForm() {
                    this.form = {
                        type: 'file',
                        title: '',
                        description: '',
                        url: '',
                        body: '',
                    };
                    this.fieldErrors = {};
                    this.createError = null;

                    if (this.$refs.file) {
                        this.$refs.file.value = '';
                    }
                },
                firstError(field) {
                    return this.fieldErrors[field]?.[0] || '';
                },
                formatFileSize(bytes) {
                    if (! Number.isFinite(Number(bytes)) || Number(bytes) <= 0) {
                        return '';
                    }

                    if (Number(bytes) >= 1024 * 1024) {
                        return `${(Number(bytes) / 1024 / 1024).toFixed(1).replace(/\.0$/, '')} MB`;
                    }

                    return `${Math.ceil(Number(bytes) / 1024)} KB`;
                },
                allowedExtensionsText() {
                    return this.uploadLimits.allowedExtensions.map((extension) => `.${extension}`).join(', ');
                },
                fileExtension(fileName) {
                    const parts = String(fileName || '').toLowerCase().split('.');

                    return parts.length > 1 ? parts.pop() : '';
                },
                fileTypeErrorMessage() {
                    const allowedExtensions = this.allowedExtensionsText();

                    return allowedExtensions
                        ? `${this.uploadTypeMessage} ${this.uploadAllowedExtensionsMessage}: ${allowedExtensions}. ${this.uploadChooseAnotherMessage}`
                        : `${this.uploadTypeMessage} ${this.uploadChooseAnotherMessage}`;
                },
                fileTooLargeErrorMessage(limitBytes = this.uploadLimits.maxFileSizeBytes) {
                    const formattedLimit = this.formatFileSize(limitBytes);

                    return formattedLimit
                        ? `${this.uploadTooLargeMessage} ${this.uploadMaxSizeMessage}: ${formattedLimit}. ${this.uploadChooseAnotherMessage}`
                        : `${this.uploadTooLargeMessage} ${this.uploadChooseAnotherMessage}`;
                },
                selectedFileError(file) {
                    if (! file) {
                        return '';
                    }

                    if (file.size > this.uploadLimits.maxFileSizeBytes) {
                        return this.fileTooLargeErrorMessage();
                    }

                    if (
                        this.uploadLimits.allowedExtensions.length > 0
                        && ! this.uploadLimits.allowedExtensions.includes(this.fileExtension(file.name))
                    ) {
                        return this.fileTypeErrorMessage();
                    }

                    return '';
                },
                validateSelectedFile() {
                    if (this.form.type !== 'file') {
                        return true;
                    }

                    const error = this.selectedFileError(this.$refs.file?.files?.[0]);

                    if (! error) {
                        return true;
                    }

                    this.fieldErrors.file = [error];
                    this.createError = error;

                    return false;
                },
                createValidationMessage(payload) {
                    if (payload?.error?.code === 'context_item_upload_rejected' || payload?.errors?.file?.length) {
                        return this.uploadRejectionMessage(payload);
                    }

                    return payload?.message || this.createErrorMessage;
                },
                uploadRejectionMessage(payload) {
                    const violations = Array.isArray(payload?.error?.violations) ? payload.error.violations : [];
                    const messages = [];

                    violations.forEach((violation) => {
                        if (violation.rule === 'max_file_size') {
                            messages.push(this.fileTooLargeErrorMessage(violation.limit?.bytes));
                        }

                        if (['allowed_file_type', 'allowed_extension'].includes(violation.rule)) {
                            messages.push(this.fileTypeErrorMessage());
                        }
                    });

                    if (messages.length > 0) {
                        return [...new Set(messages)].join(' ');
                    }

                    return payload?.errors?.file?.[0] || payload?.message || this.createErrorMessage;
                },
                firstEditError(field) {
                    return this.editFieldErrors[field]?.[0] || '';
                },
                updateEndpoint(id) {
                    return `${this.endpoint}/${id}`;
                },
                async create() {
                    this.fieldErrors = {};
                    this.createError = null;

                    if (! this.validateSelectedFile()) {
                        return;
                    }

                    this.saving = true;

                    const data = new FormData();
                    data.append('type', this.form.type);
                    data.append('title', this.form.title);
                    data.append('description', this.form.description);

                    if (this.form.type === 'file' && this.$refs.file?.files?.[0]) {
                        data.append('file', this.$refs.file.files[0]);
                    }

                    if (this.form.type === 'link') {
                        data.append('url', this.form.url);
                    }

                    if (this.form.type === 'text') {
                        data.append('body', this.form.body);
                    }

                    try {
                        const response = await fetch(this.endpoint, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': this.csrfToken,
                            },
                            credentials: 'same-origin',
                            body: data,
                        });

                        if (response.status === 422) {
                            const payload = await response.json();
                            this.fieldErrors = payload.errors || {};
                            this.createError = this.createValidationMessage(payload);

                            return;
                        }

                        if (! response.ok) {
                            throw new Error(`${this.createErrorMessage} (${response.status})`);
                        }

                        this.resetForm();
                        this.$flux.modal('add-context-item-modal').close();
                        await this.load();
                    } catch (error) {
                        this.createError = error.message || this.createErrorMessage;
                    } finally {
                        this.saving = false;
                    }
                },
                openEdit(contextItem) {
                    this.editForm = {
                        id: contextItem.id,
                        title: contextItem.title || '',
                        description: contextItem.description || '',
                    };
                    this.editFieldErrors = {};
                    this.editError = null;
                    this.$flux.modal('edit-context-item-modal').show();
                },
                openDelete(contextItem) {
                    this.deleteForm = {
                        id: contextItem.id,
                        title: contextItem.title || '',
                    };
                    this.deleteError = null;
                    this.$flux.modal('delete-context-item-modal').show();
                },
                resetEditForm() {
                    this.editForm = {
                        id: null,
                        title: '',
                        description: '',
                    };
                    this.editFieldErrors = {};
                    this.editError = null;
                },
                resetDeleteForm() {
                    this.deleteForm = {
                        id: null,
                        title: '',
                    };
                    this.deleteError = null;
                },
                async update() {
                    if (! this.editForm.id) {
                        return;
                    }

                    this.updating = true;
                    this.editFieldErrors = {};
                    this.editError = null;

                    try {
                        const response = await fetch(this.updateEndpoint(this.editForm.id), {
                            method: 'PATCH',
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': this.csrfToken,
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({
                                title: this.editForm.title,
                                description: this.editForm.description || null,
                            }),
                        });

                        if (response.status === 422) {
                            const payload = await response.json();
                            this.editFieldErrors = payload.errors || {};
                            this.editError = payload.message || this.updateErrorMessage;

                            return;
                        }

                        if (! response.ok) {
                            throw new Error(`${this.updateErrorMessage} (${response.status})`);
                        }

                        const payload = await response.json();
                        const updatedContextItem = payload.data;

                        this.contextItems = this.contextItems.map((contextItem) => (
                            contextItem.id === updatedContextItem.id ? updatedContextItem : contextItem
                        ));

                        this.$flux.modal('edit-context-item-modal').close();
                        this.resetEditForm();
                    } catch (error) {
                        this.editError = error.message || this.updateErrorMessage;
                    } finally {
                        this.updating = false;
                    }
                },
                async destroy() {
                    if (! this.deleteForm.id) {
                        return;
                    }

                    this.deleting = true;
                    this.deleteError = null;

                    try {
                        const response = await fetch(this.updateEndpoint(this.deleteForm.id), {
                            method: 'DELETE',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': this.csrfToken,
                            },
                            credentials: 'same-origin',
                        });

                        if (! response.ok) {
                            throw new Error(`${this.deleteErrorMessage} (${response.status})`);
                        }

                        const deletedContextItemId = this.deleteForm.id;

                        this.contextItems = this.contextItems.filter((contextItem) => (
                            contextItem.id !== deletedContextItemId
                        ));

                        this.$flux.modal('delete-context-item-modal').close();
                        this.resetDeleteForm();
                    } catch (error) {
                        this.deleteError = error.message || this.deleteErrorMessage;
                    } finally {
                        this.deleting = false;
                    }
                },
                formatType(type) {
                    return String(type || this.defaultType)
                        .replace(/[-_]+/g, ' ')
                        .replace(/\w\S*/g, (word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase());
                },
                metadataEntries(metadata) {
                    return Object.entries(metadata || {});
                },
                metadataValue(value) {
                    if (value === null) {
                        return 'null';
                    }

                    if (typeof value === 'object') {
                        return JSON.stringify(value);
                    }

                    return String(value);
                },
            }"
            x-init="load()"
        >
            @if ($canManage)
                <div class="flex justify-end">
                    <flux:modal.trigger name="add-context-item-modal">
                        <flux:button variant="primary" icon="plus">
                            {{ __('Add context item') }}
                        </flux:button>
                    </flux:modal.trigger>
                </div>
            @endif

            @if ($canManage)
                <flux:modal name="add-context-item-modal" class="md:w-[34rem]">
                    <form class="flex flex-col gap-5" x-on:submit.prevent="create()">
                    <div class="flex flex-col gap-1">
                        <flux:heading>{{ __('Add context item') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">
                            {{ __('Attach a file, link, or text snippet to this project.') }}
                        </flux:text>
                    </div>

                    <template x-if="createError">
                        <flux:callout variant="danger" icon="exclamation-triangle">
                            <flux:callout.heading>{{ __('Unable to add context item') }}</flux:callout.heading>
                            <flux:callout.text x-text="createError"></flux:callout.text>
                        </flux:callout>
                    </template>

                    <flux:field>
                        <flux:label>{{ __('Type') }}</flux:label>
                        <flux:select x-model="form.type" x-on:change="fieldErrors = {}; createError = null">
                            <flux:select.option value="file">{{ __('File') }}</flux:select.option>
                            <flux:select.option value="link">{{ __('Link') }}</flux:select.option>
                            <flux:select.option value="text">{{ __('Text snippet') }}</flux:select.option>
                        </flux:select>
                        <flux:error name="type" x-text="firstError('type')" />
                    </flux:field>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Title') }}</flux:label>
                            <flux:input x-model="form.title" required />
                            <flux:error name="title" x-text="firstError('title')" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Description') }}</flux:label>
                            <flux:input x-model="form.description" />
                            <flux:error name="description" x-text="firstError('description')" />
                        </flux:field>
                    </div>

                    <flux:field x-show="form.type === 'file'">
                        <flux:label>{{ __('File') }}</flux:label>
                        <flux:input
                            type="file"
                            x-ref="file"
                            x-bind:accept="uploadLimits.allowedExtensions.map((extension) => `.${extension}`).join(',')"
                            x-on:change="fieldErrors.file = []; createError = null; validateSelectedFile()"
                        />
                        <flux:error name="file" x-text="firstError('file')" />
                        <flux:text class="text-xs text-zinc-500">
                            {{ __('Allowed file types') }}:
                            <span x-text="allowedExtensionsText()"></span>.
                            {{ __('Maximum size') }}:
                            <span x-text="formatFileSize(uploadLimits.maxFileSizeBytes)"></span>.
                        </flux:text>
                    </flux:field>

                    <flux:field x-show="form.type === 'link'">
                        <flux:label>{{ __('URL') }}</flux:label>
                        <flux:input type="url" x-model="form.url" placeholder="https://example.com" />
                        <flux:error name="url" x-text="firstError('url')" />
                    </flux:field>

                    <flux:field x-show="form.type === 'text'">
                        <flux:label>{{ __('Text snippet') }}</flux:label>
                        <flux:textarea x-model="form.body" rows="6" />
                        <flux:error name="body" x-text="firstError('body')" />
                    </flux:field>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost" x-on:click="resetForm()">
                                {{ __('Cancel') }}
                            </flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" x-bind:disabled="saving">
                            <span x-show="! saving">{{ __('Add item') }}</span>
                            <span x-show="saving">{{ __('Adding...') }}</span>
                        </flux:button>
                    </div>
                    </form>
                </flux:modal>
            @endif

            @if ($canManage)
                <flux:modal name="edit-context-item-modal" class="md:w-[34rem]">
                    <form class="flex flex-col gap-5" x-on:submit.prevent="update()">
                    <div class="flex flex-col gap-1">
                        <flux:heading>{{ __('Edit context item') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">
                            {{ __('Update the title and description shown in the project context list.') }}
                        </flux:text>
                    </div>

                    <template x-if="editError">
                        <flux:callout variant="danger" icon="exclamation-triangle">
                            <flux:callout.heading>{{ __('Unable to update context item') }}</flux:callout.heading>
                            <flux:callout.text x-text="editError"></flux:callout.text>
                        </flux:callout>
                    </template>

                    <flux:field>
                        <flux:label>{{ __('Title') }}</flux:label>
                        <flux:input x-model="editForm.title" required />
                        <flux:error name="edit-title" x-text="firstEditError('title')" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Description') }}</flux:label>
                        <flux:textarea x-model="editForm.description" rows="4" />
                        <flux:error name="edit-description" x-text="firstEditError('description')" />
                    </flux:field>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost" x-on:click="resetEditForm()">
                                {{ __('Cancel') }}
                            </flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" x-bind:disabled="updating">
                            <span x-show="! updating">{{ __('Save changes') }}</span>
                            <span x-show="updating">{{ __('Saving...') }}</span>
                        </flux:button>
                    </div>
                    </form>
                </flux:modal>
            @endif

            @if ($canManage)
                <flux:modal name="delete-context-item-modal" class="min-w-[22rem]">
                    <form class="flex flex-col gap-5" x-on:submit.prevent="destroy()">
                    <div class="flex flex-col gap-1">
                        <flux:heading>{{ __('Delete context item?') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">
                            {{ __('This will remove the item from this project context.') }}
                        </flux:text>
                    </div>

                    <div class="rounded border border-zinc-200 bg-zinc-50 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900">
                        <flux:text class="text-sm font-medium text-zinc-900 dark:text-zinc-100" x-text="deleteForm.title"></flux:text>
                    </div>

                    <template x-if="deleteError">
                        <flux:callout variant="danger" icon="exclamation-triangle">
                            <flux:callout.heading>{{ __('Unable to delete context item') }}</flux:callout.heading>
                            <flux:callout.text x-text="deleteError"></flux:callout.text>
                        </flux:callout>
                    </template>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost" x-on:click="resetDeleteForm()">
                                {{ __('Cancel') }}
                            </flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="danger" x-bind:disabled="deleting">
                            <span x-show="! deleting">{{ __('Delete item') }}</span>
                            <span x-show="deleting">{{ __('Deleting...') }}</span>
                        </flux:button>
                    </div>
                    </form>
                </flux:modal>
            @endif

            <template x-if="loading">
                <div class="flex flex-col gap-3" aria-live="polite">
                    <flux:card>
                        <div class="flex flex-col gap-3">
                            <flux:skeleton class="h-5 w-28" />
                            <flux:skeleton class="h-6 w-2/3" />
                            <flux:skeleton class="h-4 w-full" />
                            <flux:skeleton class="h-4 w-3/4" />
                        </div>
                    </flux:card>
                    <flux:text class="text-sm text-zinc-500">{{ __('Loading context items...') }}</flux:text>
                </div>
            </template>

            <template x-if="! loading && error">
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.heading>{{ __('Unable to load context items') }}</flux:callout.heading>
                    <flux:callout.text x-text="error"></flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" x-on:click="load()">{{ __('Retry') }}</flux:button>
                    </x-slot>
                </flux:callout>
            </template>

            <template x-if="! loading && ! error && contextItems.length === 0">
                <flux:card>
                    <div class="flex flex-col gap-1">
                        <flux:heading>{{ __('No context items yet.') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">{{ __('Context attached to this project will appear here.') }}</flux:text>
                    </div>
                </flux:card>
            </template>

            <template x-if="! loading && ! error && contextItems.length > 0">
                <div class="flex flex-col gap-3">
                    <template x-for="contextItem in contextItems" :key="`context-item-${contextItem.id}`">
                        <flux:card>
                            <div class="flex flex-col gap-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:badge variant="solid" x-text="formatType(contextItem.type)"></flux:badge>
                                    @if ($canManage)
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            icon="pencil-square"
                                            x-on:click="openEdit(contextItem)"
                                        >
                                            {{ __('Edit') }}
                                        </flux:button>
                                        <flux:button
                                            size="sm"
                                            variant="danger"
                                            icon="trash"
                                            x-on:click="openDelete(contextItem)"
                                        >
                                            {{ __('Delete') }}
                                        </flux:button>
                                    @endif
                                    <flux:text class="ml-auto text-xs text-zinc-500" x-text="`#${contextItem.id}`"></flux:text>
                                </div>

                                <div class="flex flex-col gap-1">
                                    <flux:heading x-text="contextItem.title"></flux:heading>
                                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400" x-show="contextItem.description" x-text="contextItem.description"></flux:text>
                                </div>

                                <div class="flex flex-wrap gap-2" x-show="metadataEntries(contextItem.metadata).length > 0">
                                    <template x-for="[key, value] in metadataEntries(contextItem.metadata)" :key="key">
                                        <span class="inline-flex max-w-full items-center gap-1 rounded border border-zinc-200 px-2 py-1 text-xs text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
                                            <span class="font-medium" x-text="formatType(key)"></span>
                                            <span class="truncate text-zinc-500 dark:text-zinc-400" x-text="metadataValue(value)"></span>
                                        </span>
                                    </template>
                                </div>
                            </div>
                        </flux:card>
                    </template>
                </div>
            </template>
        </section>
    @endif
</div>
