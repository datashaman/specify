@props(['content' => null])

@if (filled($content))
    <div {{ $attributes->class([
        'text-sm text-zinc-700 dark:text-zinc-300',
        '[&_p]:my-2 [&_p:first-child]:mt-0 [&_p:last-child]:mb-0',
        '[&_ul]:my-2 [&_ul]:list-disc [&_ul]:pl-5',
        '[&_ol]:my-2 [&_ol]:list-decimal [&_ol]:pl-5',
        '[&_li]:my-0.5',
        '[&_h1]:mt-3 [&_h1]:mb-2 [&_h1]:text-lg [&_h1]:font-semibold',
        '[&_h2]:mt-3 [&_h2]:mb-1 [&_h2]:text-base [&_h2]:font-semibold',
        '[&_h3]:mt-2 [&_h3]:mb-1 [&_h3]:text-sm [&_h3]:font-semibold',
        '[&_a]:text-blue-600 [&_a]:underline dark:[&_a]:text-blue-400',
        '[&_code]:rounded [&_code]:bg-zinc-100 [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-xs dark:[&_code]:bg-zinc-800',
        '[&_pre]:my-2 [&_pre]:overflow-x-auto [&_pre]:rounded [&_pre]:bg-zinc-100 [&_pre]:p-3 [&_pre]:text-xs dark:[&_pre]:bg-zinc-800',
        '[&_pre_code]:bg-transparent [&_pre_code]:p-0',
        '[&_blockquote]:my-2 [&_blockquote]:border-l-4 [&_blockquote]:border-zinc-300 [&_blockquote]:pl-3 [&_blockquote]:text-zinc-600 dark:[&_blockquote]:border-zinc-600 dark:[&_blockquote]:text-zinc-400',
        '[&_strong]:font-semibold',
        '[&_em]:italic',
    ]) }}>
        {!! \Illuminate\Support\Str::markdown($content, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
    </div>
@endif
