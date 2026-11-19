<?php

namespace ErnestDefoe\Testimonials\Api;

use Tobyz\JsonApiServer\Context;
use Tobyz\JsonApiServer\Pagination\OffsetPagination;

/**
 * Offset paging that knows whether there's a next page without counting every match: it fetches
 * one row past the page, TestimonialResource::results() trims it and says whether it was there, and
 * links.next is left out when it wasn't (a full last page would otherwise link to an empty one).
 */
class PeekPagination extends OffsetPagination
{
    public ?bool $more = null;

    /** The request this page belongs to (the resource keeps the latest one and checks it's this). */
    public readonly object $request;

    public function __construct(Context $context, int $defaultLimit = 20, int $maxLimit = 50)
    {
        parent::__construct($context, $defaultLimit, $maxLimit);
        $this->request = $context->request;
    }

    /** @param \Illuminate\Database\Eloquent\Builder<\ErnestDefoe\Testimonials\Testimonial> $query */
    public function apply($query): void
    {
        $query->skip($this->offset)->take($this->limit + 1);
    }

    public function links(int $count, ?int $total): array
    {
        $links = parent::links($count, $total);
        if ($this->more === false) {
            unset($links['next']);
        }

        return $links;
    }
}
