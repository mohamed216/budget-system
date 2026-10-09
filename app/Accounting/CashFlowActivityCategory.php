<?php

namespace App\Accounting;

enum CashFlowActivityCategory: string
{
    case Operating = 'operating';
    case Investing = 'investing';
    case Financing = 'financing';
}
