<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A location is a bank by having a row here. */
#[Fillable(['location_id'])]
class BankBranch extends Model {}
