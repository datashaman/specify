<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header class="!pb-0 !mb-0">
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav class="!pt-0 !mt-0">
                @auth
                    @php $currentProjectId = auth()->user()->current_project_id; @endphp

                    <livewire:app-switcher />

                    <flux:sidebar.group :heading="__('Workspace')" class="grid">
                        <flux:sidebar.item icon="inbox" :href="route('triage')" :current="request()->routeIs('triage')" wire:navigate>
                            {{ __('Triage') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="signal" :href="route('activity.index')" :current="request()->routeIs('activity.*')" wire:navigate>
                            {{ __('Activity') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    @if ($currentProjectId)
                        <flux:sidebar.group :heading="__('Project')" class="grid">
                            <flux:sidebar.item icon="rectangle-stack" :href="route('projects.show', $currentProjectId)" :current="request()->routeIs('projects.show') || request()->routeIs('features.show')" wire:navigate>
                                {{ __('Features') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="bookmark" :href="route('stories.index', ['project' => $currentProjectId])" :current="request()->routeIs('stories.*')" wire:navigate>
                                {{ __('Stories') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="document-duplicate" :href="route('plans.index', ['project' => $currentProjectId])" :current="request()->routeIs('plans.*')" wire:navigate>
                                {{ __('Plans') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="check-badge" :href="route('approvals.index', ['project' => $currentProjectId])" :current="request()->routeIs('approvals.*') || request()->routeIs('triage')" wire:navigate>
                                {{ __('Approvals') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="clipboard-document-list" :href="route('runs.index', ['project' => $currentProjectId])" :current="request()->routeIs('runs.*')" wire:navigate>
                                {{ __('Runs') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="folder-open" :href="route('repos.index', ['project' => $currentProjectId])" :current="request()->routeIs('repos.*')" wire:navigate>
                                {{ __('Repos') }}
                            </flux:sidebar.item>
                        </flux:sidebar.group>
                    @else
                        <flux:sidebar.group :heading="__('Project')" class="grid">
                            <flux:sidebar.item icon="folder-plus" :href="route('projects.index')" :current="request()->routeIs('projects.index')" wire:navigate>
                                {{ __('Pick a project') }}
                            </flux:sidebar.item>
                        </flux:sidebar.group>
                    @endif
                @endauth
            </flux:sidebar.nav>

            <flux:spacer />


            <div class="hidden lg:block">
                <livewire:user-menu :compact="false" :key="'user-menu-desktop'" />
            </div>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <livewire:user-menu :compact="true" :key="'user-menu-mobile'" />
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
