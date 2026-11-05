<?php

namespace App\Exceptions;

class IndustryChangeBlockedException extends \RuntimeException
{
    public function __construct(public array $counts = [])
    {
        parent::__construct('Industry change is blocked by existing operational data.', 422);
    }
}
