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
        abort_unless(Auth::user()->canApproveInProject($this->project), 403);
    }

    #[Computed]
    public function project(): ?Project
    {
        return Project::query()
            ->whereIn('id', Auth::user()->accessibleProjectIds())
            ->with('team.workspace')
            ->find($this->project_id);
    }

};
?>

<div class="flex flex-col gap-6 p-6">
    @if (! $this->project)
        <flux:text class="text-zinc-500">{{ __('Project not found.') }}</flux:text>
    @else
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
                defaultType: {{ \Illuminate\Support\Js::from(__('Context')) }},
                contextItems: Array(),
                error: null,
                loading: true,
                saving: false,
                form: {
                    type: 'file',
                    title: '',
                    description: '',
                    url: '',
                    body: '',
                },
                fieldErrors: {},
                createError: null,
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
                async create() {
                    this.saving = true;
                    this.fieldErrors = {};
                    this.createError = null;

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
                            this.createError = payload.message || this.createErrorMessage;

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
            <div class="flex justify-end">
                <flux:modal.trigger name="add-context-item-modal">
                    <flux:button variant="primary" icon="plus">
                        {{ __('Add context item') }}
                    </flux:button>
                </flux:modal.trigger>
            </div>

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
                        <flux:input type="file" x-ref="file" />
                        <flux:error name="file" x-text="firstError('file')" />
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
