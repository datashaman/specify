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
                        <x-user.menu-content :user="$this->user" />
                    </div>
                </flux:menu.radio.group>
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
                <x-user.menu-content :user="$this->user" />
            </flux:menu>
        </flux:dropdown>
    @endif
</div>
