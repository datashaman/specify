import Sortable from 'sortablejs';

document.addEventListener('alpine:init', () => {
    window.Alpine.directive('sortable', (el, { expression }, { evaluate }) => {
        Sortable.create(el, {
            handle: '[data-sortable-handle]',
            animation: 150,
            ghostClass: 'opacity-50',
            onEnd: () => {
                const ids = Array.from(el.querySelectorAll('[data-sortable-id]'))
                    .map((node) => parseInt(node.dataset.sortableId, 10))
                    .filter((id) => !Number.isNaN(id));
                evaluate(`${expression}(${JSON.stringify(ids)})`);
            },
        });
    });
});
