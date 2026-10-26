<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;

abstract class Controller extends \Illuminate\Routing\Controller
{
    use ApiResponse;
}
