<?php
namespace GP247\Core\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $connection = GP247_DB_CONNECTION;
    
}
