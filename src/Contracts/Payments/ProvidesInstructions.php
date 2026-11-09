<?php

namespace KraftInk\Plugins\Shop\Contracts\Payments;

interface ProvidesInstructions
{
    public function instructions(): string;
}
