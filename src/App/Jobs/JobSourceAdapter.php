<?php

namespace App\Jobs;

interface JobSourceAdapter
{
    public function discover(string $listingUrl, callable $progress, callable $isCancelled): array;
}
