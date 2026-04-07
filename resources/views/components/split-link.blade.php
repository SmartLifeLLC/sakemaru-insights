@props(['href', 'title' => null])
<a href="{{ $href }}"
   @click.prevent="
       if (window.innerWidth < 1024) {
           window.location.href = '{{ $href }}';
       } else {
           $store.splitView.open('{{ $href }}', '{{ $title ?? '' }}' || $el.textContent.trim());
       }
   "
   {{ $attributes->merge(['class' => 'text-blue-600 hover:underline cursor-pointer']) }}>
    {{ $slot }}
</a>
