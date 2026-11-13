<?php

namespace ErnestDefoe\Testimonials\Notification;

use ErnestDefoe\Testimonials\Testimonial;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * Told to the author: their testimonial is featured.
 * The subject is the testimonial (it has the `testimonials` API resource, so notification lists
 * can include it), which its author can always see.
 */
class FeaturedBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(public Testimonial $testimonial, public ?User $actor = null)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->testimonial;
    }

    public function getData(): mixed
    {
        return null;
    }

    public static function getType(): string
    {
        return 'testimonialFeatured';
    }

    public static function getSubjectModel(): string
    {
        return Testimonial::class;
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-testimonials::emails.plain.featured',
            'html' => 'ernestdefoe-testimonials::emails.html.featured',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-testimonials.email.featured.subject');
    }
}
