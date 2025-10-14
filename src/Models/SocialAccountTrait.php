<?php

namespace GP247\Core\Models;
/**
 * Trait Model.
 */
trait SocialAccountTrait
{
    function socialAccount()
    {
        if (class_exists(\App\GP247\Plugins\LoginSocial\Models\SocialAccount::class)) {
            return $this->morphOne(
                \App\GP247\Plugins\LoginSocial\Models\SocialAccount::class,
                    'user'
                );
            }
        return null;
    }
}
