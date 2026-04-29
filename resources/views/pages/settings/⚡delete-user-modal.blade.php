<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    public string $emailConfirmation = '';

    #[Computed]
    public function oauthOnly(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->github_id !== null;
    }

    public function deleteUser(Logout $logout): void
    {
        $user = Auth::user();

        if ($this->oauthOnly) {
            $this->validate([
                'emailConfirmation' => ['required', 'string'],
            ]);
            if ($this->emailConfirmation !== $user->email) {
                $this->addError('emailConfirmation', __('Email does not match.'));

                return;
            }
        } else {
            $this->validate([
                'password' => $this->currentPasswordRules(),
            ]);
        }

        tap($user, $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form method="POST" wire:submit="deleteUser" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

            <flux:subheading>
                @if ($this->oauthOnly)
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Type your email to confirm.') }}
                @else
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                @endif
            </flux:subheading>
        </div>

        @if ($this->oauthOnly)
            <flux:input wire:model="emailConfirmation" :label="__('Email')" :placeholder="auth()->user()->email" />
        @else
            <flux:input wire:model="password" :label="__('Password')" type="password" viewable />
        @endif

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                {{ __('Delete account') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
