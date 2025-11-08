<?php

namespace Ritechoice23\Saveable\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Ritechoice23\Saveable\Traits\IsSaveable;

class Comment extends Model
{
    use IsSaveable;

    protected $guarded = [];
}
