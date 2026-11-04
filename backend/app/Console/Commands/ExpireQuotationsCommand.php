<?php

namespace App\Console\Commands;

use App\Services\Crm\QuotationService;
use Illuminate\Console\Command;

class ExpireQuotationsCommand extends Command
{
    protected $signature = 'promofy:expire-quotations';

    protected $description = 'Mark sent quotations past their validity date as expired';

    public function handle(QuotationService $quotes): int
    {
        $this->info('Expired: '.$quotes->expireOverdue());

        return self::SUCCESS;
    }
}
