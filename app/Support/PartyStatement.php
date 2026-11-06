<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Payment;

/**
 * SEAMAN Phase 5 — the month-end party statement: opening due, every bill
 * and payment in date order with a running balance, closing due.
 *
 * The opening balance is BACK-CALCULATED from the one source of truth
 * (customers.due): opening = current due − (the net effect of every event
 * dated on/after `from`). That way history the ledger never recorded — an
 * initial due typed in at customer creation, a pre-ledger adjustment —
 * lands in the opening figure instead of silently corrupting the running
 * balance, and the statement always reconciles to the live due.
 *
 * Events: a non-voided sale contributes +due_at_checkout (its total minus
 * what was tendered at the till — the credit part); a payment contributes
 * −amount. A voided payment and its compensating reversal row net to zero,
 * so both are left out of the statement entirely. NEVER include supplier
 * or purchase-cost data here — this document goes to the party.
 */
class PartyStatement
{
    /** @return array{opening: float, closing: float, rows: array, total_billed: float, total_paid: float} */
    public static function build(Customer $customer, string $from, string $to): array
    {
        $sales = $customer->sales()
            ->whereNull('voided_at')
            ->where('date', '>=', $from)
            ->with('payments')
            ->orderBy('date')->orderBy('id')
            ->get();

        $payments = Payment::where('customer_id', $customer->id)
            ->whereNull('voided_at')
            ->whereNull('void_of_payment_id')
            ->where('date', '>=', $from)
            ->orderBy('date')->orderBy('id')
            ->get();

        $events = [];
        foreach ($sales as $sale) {
            $tendered = (float) $sale->payments->sum('amount');
            $credit = max(0.0, round((float) $sale->total - $tendered, 2));
            $events[] = [
                'date' => (string) $sale->date,
                'sort' => [(string) $sale->date, 0, $sale->id],
                'type' => 'bill',
                'label' => $sale->invoice_no,
                'bill_total' => (float) $sale->total,
                'paid' => $tendered,
                'effect' => $credit,
            ];
        }
        foreach ($payments as $payment) {
            $events[] = [
                'date' => $payment->date->toDateString(),
                'sort' => [$payment->date->toDateString(), 1, $payment->id],
                'type' => 'payment',
                'label' => $payment->method,
                'bill_total' => null,
                'paid' => (float) $payment->amount,
                'effect' => -(float) $payment->amount,
            ];
        }

        usort($events, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        $effectFromOnwards = array_sum(array_column($events, 'effect'));
        $opening = round((float) $customer->due - $effectFromOnwards, 2);

        $rows = [];
        $running = $opening;
        $totalBilled = 0.0;
        $totalPaid = 0.0;
        foreach ($events as $event) {
            if ($event['date'] > $to) {
                break;
            }
            $running = round($running + $event['effect'], 2);
            unset($event['sort']);
            $event['balance'] = $running;
            $rows[] = $event;
            if ($event['type'] === 'bill') {
                $totalBilled += $event['bill_total'];
                $totalPaid += $event['paid'];
            } else {
                $totalPaid += $event['paid'];
            }
        }

        return [
            'opening' => $opening,
            'closing' => $running,
            'rows' => $rows,
            'total_billed' => round($totalBilled, 2),
            'total_paid' => round($totalPaid, 2),
        ];
    }
}
