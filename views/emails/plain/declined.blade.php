<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-testimonials.email.declined.plain.body', [
'{reason}' => $blueprint->testimonial->decline_reason ?? '',
'{url}' => $url->to('forum')->route('testimonials'),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
