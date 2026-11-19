<?php

namespace ErnestDefoe\Testimonials;

use Flarum\User\User;

/**
 * A member's Trust Levels level, read whichever way the installed Trust Levels keeps it: its own
 * `trust_states` table (newer releases, through its TrustState model and the User relation
 * `trustState`) or the old `users.trust_level` column. Null when Trust Levels is off; a member
 * with no level yet is 0 (New).
 */
class TrustLevel
{
    /** Named as a string: Trust Levels is optional, so the class may not exist. */
    public const STATE = 'ErnestDefoe\\TrustLevels\\TrustState';

    public function __construct(private readonly Extensions $extensions)
    {
    }

    public function of(User $user): ?int
    {
        if (! $this->extensions->trustLevels()) {
            return null;
        }
        if ($this->stateTable()) {
            /** @var callable(User): object{level: int|null} $of */
            $of = [self::STATE, 'of'];

            return (int) $of($user)->level;
        }

        return (int) $user->getAttribute('trust_level');
    }

    /** The user relation to eager-load on a list that reads levels, or null when there's none. */
    public function relation(): ?string
    {
        return $this->extensions->trustLevels() && $this->stateTable() ? 'trustState' : null;
    }

    private function stateTable(): bool
    {
        return class_exists(self::STATE);
    }
}
