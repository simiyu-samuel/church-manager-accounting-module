<?php

namespace App\Http\Controllers;

use App\Models\JournalTxn;
use Illuminate\Http\Request;
use App\Models\PaymentAccount;

class AccountsController extends Controller
{

     public function journal(Request $request)
    {
        $query = JournalTxn::with(['debitAccount', 'creditAccount', 'income', 'expense']);

        if ($request->filled('start_date')) {
            $query->whereDate('date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('date', '<=', $request->end_date);
        }

        if ($request->filled('account')) {
            $query->where(function ($q) use ($request) {
                $q->where('debit_account_id', $request->account)
                ->orWhere('credit_account_id', $request->account);
            });
        }

        $journalTxns = $query->get();

        $totalAmount = $journalTxns->sum('amount'); // Total transactions

        // $accounts = PaymentAccount::all();

        $totalDebit = $totalAmount;  
        $totalCredit = $totalAmount;  

        $accounts = PaymentAccount::with(['debitTxns', 'creditTxns'])->get();

        foreach ($accounts as $account) {
            $debit = $account->debitTxns->sum('amount');
            $credit = $account->creditTxns->sum('amount');

            switch ($account->type) {
                case 'asset':
                case 'expense':
                    $account->balance = $debit - $credit;
                    break;

                case 'income':
                case 'liability':
                    $account->balance = $credit - $debit;
                    break;

                default:
                    $account->balance = 0;
            }
        }
        return view('accounts.journal_entries', compact('journalTxns', 'totalDebit', 'totalCredit', 'accounts'));
    }
        public function trialBalance(Request $request)
{
    $startDate = $request->start_date;
    $endDate = $request->end_date;

    $accounts = PaymentAccount::with([
        'debitTxns' => function ($q) use ($startDate, $endDate) {
            if ($startDate) $q->whereDate('date', '>=', $startDate);
            if ($endDate) $q->whereDate('date', '<=', $endDate);
        },
        'creditTxns' => function ($q) use ($startDate, $endDate) {
            if ($startDate) $q->whereDate('date', '>=', $startDate);
            if ($endDate) $q->whereDate('date', '<=', $endDate);
        }
    ])->get();

    $trialData = $accounts->map(function ($account) {
        $totalDebit = $account->debitTxns->sum('amount');
        $totalCredit = $account->creditTxns->sum('amount');

        switch ($account->type) {
            case 'asset':
            case 'expense':
                $balance = $totalDebit - $totalCredit;
                $debit = $balance >= 0 ? $balance : 0;
                $credit = $balance < 0 ? abs($balance) : 0;
                break;
            case 'liability':
            case 'equity':
            case 'income':
                $balance = $totalCredit - $totalDebit;
                $debit = $balance < 0 ? abs($balance) : 0;
                $credit = $balance >= 0 ? $balance : 0;
                break;
            default:
                $debit = 0;
                $credit = 0;
        }

        return [
            'account' => $account->account,
            'code' => $account->code,
            'description' => $account->description,
            'debit' => $debit,
            'credit' => $credit,
        ];
    });

    $totalDebit = $trialData->sum('debit');
    $totalCredit = $trialData->sum('credit');

    return view('accounts.trial_balance', compact('trialData', 'totalDebit', 'totalCredit', 'startDate', 'endDate'));
}


    public function ledger(Request $request)
{
    $accountId = $request->account;
    $startDate = $request->start_date;
    $endDate = $request->end_date;

    $accounts = PaymentAccount::all();

    $ledgerTxns = collect();
    $account = null;
    $runningBalance = 0;

    if ($accountId) {
        $account = PaymentAccount::findOrFail($accountId);

        $query = JournalTxn::where(function ($q) use ($accountId) {
            $q->where('debit_account_id', $accountId)
              ->orWhere('credit_account_id', $accountId);
        });

        if ($startDate) {
            $query->whereDate('date', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('date', '<=', $endDate);
        }

        $query->orderBy('date')->orderBy('id');

        $ledgerTxns = $query->get()->map(function ($txn) use (&$runningBalance, $accountId) {
            $isDebit = $txn->debit_account_id == $accountId;
            $amount = (float) $txn->amount;

            if ($isDebit) {
                $runningBalance += $amount;
            } else {
                $runningBalance -= $amount;
            }

            return [
                'id' => $txn->id,
                'date' => $txn->date,
                'description' => $txn->description ?? ($txn->generateDescription() ?? ''),
                'debit' => $isDebit ? $amount : null,
                'credit' => !$isDebit ? $amount : null,
                'balance' => $runningBalance,
            ];
        });
    }

    return view('accounts.ledger', compact('accounts', 'ledgerTxns', 'account', 'startDate', 'endDate'));
}
}