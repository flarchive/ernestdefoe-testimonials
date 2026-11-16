{{-- The decline reason is a moderator's own words: it never goes through Markdown, it's swapped in escaped. --}}
<x-mail::html.notification>
    <x-slot:body>
        {!! strtr($formatter->convert($translator->trans('ernestdefoe-testimonials.email.featured.html.body', [
            '{reason}' => 'TESTIMONIALREASONX',
            '{url}' => $url->to('forum')->route('testimonials'),
        ])), [
            'TESTIMONIALREASONX' => e($blueprint->testimonial->decline_reason ?? ''),
        ]) !!}
    </x-slot:body>
</x-mail::html.notification>
