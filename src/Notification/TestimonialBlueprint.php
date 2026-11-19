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
 * Something that happened to a member's testimonial, told to its author (alert and email). The
 * subject is the testimonial (it has the `testimonials` API resource, so notification lists can
 * include it), which its author can always see. Each kind names itself with key(): the type is
 * `testimonial` + Key, the email views and subject are under that key.
 */
abstract class TestimonialBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(public Testimonial $testimonial)
    {
    }

    /** approved | declined | featured | edited */
    abstract protected static function key(): string;

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
        return 'testimonial'.ucfirst(static::key());
    }

    public static function getSubjectModel(): string
    {
        return Testimonial::class;
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-testimonials::emails.plain.'.static::key(),
            'html' => 'ernestdefoe-testimonials::emails.html.'.static::key(),
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-testimonials.email.'.static::key().'.subject');
    }
}
