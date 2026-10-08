<?php

namespace App\Queries;

use App\Accounting\DecimalAmount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

final class DashboardSummary
{
    public function read(User $owner): array
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() === 0) {
            // Applies only to the next transaction on this connection, not the session default.
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }

        return $connection->transaction(fn (): array => $this->readSnapshot($owner, $connection));
    }

    private function readSnapshot(User $owner, Connection $connection): array
    {
        $corruptOwnerLink = $connection->table('transactions as dashboard_transactions')
            ->leftJoin('accounts as dashboard_accounts', 'dashboard_accounts.id', '=', 'dashboard_transactions.account_id')
            ->leftJoin('categories as dashboard_categories', 'dashboard_categories.id', '=', 'dashboard_transactions.category_id')
            ->where('dashboard_transactions.user_id', $owner->getKey())
            ->where(function ($query) use ($owner): void {
                $query->whereNull('dashboard_accounts.user_id')
                    ->orWhere('dashboard_accounts.user_id', '<>', $owner->getKey())
                    ->orWhereNull('dashboard_categories.user_id')
                    ->orWhere('dashboard_categories.user_id', '<>', $owner->getKey());
            })->exists();

        if ($corruptOwnerLink) {
            return ['mixedCurrencies' => false, 'corruptOwnerLink' => true];
        }

        $accounts = $connection->table('accounts')->where('user_id', $owner->getKey())
            ->selectRaw('COUNT(*) AS account_count, COUNT(DISTINCT CAST(currency AS BINARY)) AS currency_count, '
                .'MIN(currency) AS actual_currency, COALESCE(SUM(balance), 0.00) AS total_balance')
            ->first();

        if ((int) $accounts->currency_count > 1) {
            return ['mixedCurrencies' => true, 'corruptOwnerLink' => false];
        }

        $transactions = Transaction::on($connection->getName())->ownedBy($owner);
        $totalIncome = (clone $transactions)->where('type', 'income')->sum('amount');
        $totalExpense = (clone $transactions)->where('type', 'expense')->sum('amount');
        $income = DecimalAmount::fromAggregateString((string) $totalIncome);
        $expense = DecimalAmount::fromAggregateString((string) $totalExpense);
        $netNegative = $income->compare($expense) < 0;
        $net = $netNegative ? $expense->subtract($income) : $income->subtract($expense);

        return [
            'mixedCurrencies' => false,
            'corruptOwnerLink' => false,
            'currency' => (int) $accounts->account_count === 0 ? config('accounting.currency') : $accounts->actual_currency,
            'totalBalance' => (int) $accounts->account_count === 0 ? 0 : $accounts->total_balance,
            'totalBalanceNegative' => str_starts_with((string) $accounts->total_balance, '-'),
            'totalIncome' => $totalIncome,
            'totalExpense' => $totalExpense,
            'netIncome' => ($netNegative ? '-' : '').$net->toDecimal(),
            'netIncomeNegative' => $netNegative,
            'recentTransactions' => (clone $transactions)->with(['account', 'category'])
                ->orderByDesc('date')->take(10)->get(),
            'incomeByCategory' => (clone $transactions)->where('type', 'income')
                ->selectRaw('category_id, SUM(amount) AS total')->groupBy('category_id')->get(),
            'expenseByCategory' => (clone $transactions)->where('type', 'expense')
                ->selectRaw('category_id, SUM(amount) AS total')->groupBy('category_id')->get(),
        ];
    }
}
