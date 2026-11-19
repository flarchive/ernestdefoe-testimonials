<?php

namespace ErnestDefoe\TrustLevels;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * A stand-in for Trust Levels' TrustState (its public API: `of()` and the `trustState` user
 * relation), loaded only by the test that checks the newer way of reading levels.
 */
class TrustState extends AbstractModel
{
    protected $table = 'trust_states';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $attributes = ['level' => 0];

    protected $casts = ['user_id' => 'integer', 'level' => 'integer'];

    public static function of(User $user): self
    {
        $state = $user->exists ? $user->getRelationValue('trustState') : null;
        if (! $state) {
            $state = new self();
            $state->user_id = (int) $user->id;
            if ($user->exists) {
                $user->setRelation('trustState', $state);
            }
        }

        return $state;
    }
}
