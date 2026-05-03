<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public bool $compact = false;

    #[Computed(persist: false)]
    public function user()
    {
        return Auth::user();
    }

    #[On('profile-updated')]
    public function refreshProfile(): void
    {
        unset($this->user);
    }
}; ?>

<div>
    @if ($compact)
        <flux:dropdown position="top" align="end">
            <flux:profile :initials="$this->user->initials()" icon-trailing="chevron-down" />

            <flux:menu>
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                            <flux:avatar :name="$this->user->name" :initials="$this->user->initials()" />

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <flux:heading class="truncate">{{ $this->user->name }}</flux:heading>
                                <flux:text class="truncate">{{ $this->user->email }}</flux:text>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                        {{ __('Settings') }}
                    </flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item
                        as="button"
                        type="submit"
                        icon="arrow-right-start-on-rectangle"
                        class="w-full cursor-pointer"
                        data-test="logout-button"
                    >
                        {{ __('Log out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    @else
        <flux:dropdown position="bottom" align="start">
            <flux:sidebar.profile
                :name="$this->user->name"
                :initials="$this->user->initials()"
                icon:trailing="chevrons-up-down"
                data-test="sidebar-menu-button"
            />

            <flux:menu>
                <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                    <flux:avatar :name="$this->user->name" :initials="$this->user->initials()" />
                    <div class="grid flex-1 text-start text-sm leading-tight">
                        <flux:heading class="truncate">{{ $this->user->name }}</flux:heading>
                        <flux:text class="truncate">{{ $this->user->email }}</flux:text>
                    </div>
                </div>
                <flux:menu.separator />
                <flux:menu.radio.group>
                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                        {{ __('Settings') }}
                    </flux:menu.item>
                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu.radio.group>
            </flux:menu>
        </flux:dropdown>
    @endif
</div>
