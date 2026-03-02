@props(['code', 'lang' => 'php'])

<div {{ $attributes->class(['rounded-lg overflow-hidden border border-gray-700 bg-gray-900']) }}>
    <pre class="language-{{ $lang }} p-4"><code class="language-{{ $lang }}">{{ $code }}</code></pre>
</div>
