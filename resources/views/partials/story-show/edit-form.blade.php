<div class="mt-3 flex flex-col gap-3">
    @php $delta = $this->acDelta; @endphp
    @if ($delta)
        <div data-banner="reset-approval" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200">
            <div class="font-medium">{{ __('Saving will reset this Story to Pending Approval.') }}</div>
            <div class="mt-1 text-xs">
                {{ __('Plan delta:') }}
                <span class="tabular-nums">+{{ $delta['added'] }}</span> {{ __('AC,') }}
                <span class="tabular-nums">~{{ $delta['edited'] }}</span> {{ __('edited,') }}
                <span class="tabular-nums">-{{ $delta['removed'] }}</span> {{ __('removed') }}
            </div>
        </div>
    @endif

    <flux:input wire:model="editName" :label="__('Name')" />
    <flux:textarea wire:model="editDescription" :label="__('Description (markdown supported)')" rows="8" />

    <div class="flex flex-col gap-2">
        <flux:label>{{ __('Acceptance criteria') }}</flux:label>
        @foreach ($editCriteria as $i => $row)
            <div wire:key="ac-{{ $i }}" class="flex items-start gap-2">
                <flux:badge class="mt-2" size="sm">AC{{ $i + 1 }}</flux:badge>
                <flux:textarea wire:model="editCriteria.{{ $i }}.statement" rows="2" class="flex-1" />
                <flux:button wire:click="removeCriterion({{ $i }})" variant="ghost" size="sm" class="mt-1">{{ __('Remove') }}</flux:button>
            </div>
        @endforeach
        <div>
            <flux:button wire:click="addCriterion" variant="ghost" size="sm">{{ __('+ Add criterion') }}</flux:button>
        </div>
        @error('editCriteria')
            <flux:text class="text-xs text-red-500">{{ $message }}</flux:text>
        @enderror
    </div>

    <div class="flex items-center gap-2">
        @php $saveLabel = $delta ? __('Save & request re-approval') : __('Save'); @endphp
        <flux:button wire:click="saveEdit" wire:target="saveEdit" wire:loading.attr="disabled" variant="primary">
            <span wire:loading.remove wire:target="saveEdit">{{ $saveLabel }}</span>
            <span wire:loading wire:target="saveEdit">{{ __('Saving…') }}</span>
        </flux:button>
        <flux:button wire:click="cancelEdit" variant="ghost">{{ __('Cancel') }}</flux:button>
    </div>
</div>
