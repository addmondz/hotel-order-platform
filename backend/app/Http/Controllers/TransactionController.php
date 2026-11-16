<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    private const TIME_ZONE = 'Asia/Kuala_Lumpur';

    public function staff(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user() && $request->user()->customer_id === null, 403, 'Staff access is required.');
        abort_unless(DB::table('customers')->where('id', $id)->exists(), 404, 'Customer not found.');

        return $this->transactions($request, (int) $id, true);
    }

    public function portal(Request $request): JsonResponse
    {
        $hotelId = $request->user()?->customer_id;
        abort_unless($hotelId, 403, 'A hotel account is required.');

        return $this->transactions($request, (int) $hotelId, false);
    }

    private function transactions(Request $request, int $customerId, bool $isStaff): JsonResponse
    {
        $month = $request->query('month', now(self::TIME_ZONE)->format('Y-m'));
        abort_unless(is_string($month) && preg_match('/\A[1-9][0-9]{3}-(0[1-9]|1[0-2])\z/', $month) && $month < '9999-12',
            422, 'Choose a month in YYYY-MM format.');
        $start = CarbonImmutable::parse($month.'-01', self::TIME_ZONE)->startOfDay();
        $end = $start->addMonth();
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $startUtc = $start->utc()->toDateTimeString();
        $endUtc = $end->utc()->toDateTimeString();

        $payload = DB::transaction(function () use ($customerId, $isStaff, $month, $startDate, $endDate, $startUtc, $endUtc) {
            $invoices = DB::table('orders')->where('customer_id', $customerId)->where('status', 'invoiced');
            $payments = DB::table('invoice_payments as payments')->join('orders', 'orders.id', '=', 'payments.order_id')
                ->where('orders.customer_id', $customerId)->where('orders.status', 'invoiced');

            $opening = (int) (clone $invoices)->where('issued_at', '<', $startUtc)->sum('total')
                - (int) (clone $payments)->where('payments.paid_on', '<', $startDate)->sum('payments.amount');
            $invoices = $invoices->where('issued_at', '>=', $startUtc)->where('issued_at', '<', $endUtc)
                ->get(['id', 'invoice_number', 'issued_at', 'total']);
            $paymentColumns = ['payments.id', 'payments.order_id', 'payments.paid_on', 'payments.amount', 'orders.invoice_number'];
            if ($isStaff) {
                $paymentColumns = [...$paymentColumns, 'payments.reference', 'payments.receipt_path'];
            }
            $payments = $payments->where('payments.paid_on', '>=', $startDate)->where('payments.paid_on', '<', $endDate)
                ->get($paymentColumns);

            $entries = [];
            $invoiced = 0;
            $paid = 0;
            foreach ($invoices as $row) {
                $invoiced += (int) $row->total;
                $entries[] = [
                    'type' => 'invoice',
                    'date' => CarbonImmutable::parse($row->issued_at, 'UTC')->tz(self::TIME_ZONE)->toDateString(),
                    'id' => (int) $row->id, 'orderId' => (int) $row->id, 'invoiceNumber' => $row->invoice_number,
                    'amount' => (int) $row->total,
                ];
            }
            foreach ($payments as $row) {
                $paid += (int) $row->amount;
                $entry = [
                    'type' => 'payment',
                    'date' => $row->paid_on,
                    'id' => (int) $row->id, 'orderId' => (int) $row->order_id, 'invoiceNumber' => $row->invoice_number,
                    'amount' => (int) $row->amount,
                ];
                if ($isStaff) {
                    $entry['reference'] = $row->reference;
                    $entry['receiptUrl'] = $row->receipt_path
                        ? route('invoice-payments.receipt', ['id' => $row->order_id, 'payment' => $row->id], false)
                        : null;
                }
                $entries[] = $entry;
            }

            // On a single business date invoices are listed before payments.
            usort($entries, static fn (array $a, array $b) => [$a['date'], $a['type'], $a['id']] <=> [$b['date'], $b['type'], $b['id']]);
            $running = $opening;
            foreach ($entries as &$entry) {
                $running += $entry['type'] === 'invoice' ? $entry['amount'] : -$entry['amount'];
                $entry['amount'] /= 100;
                $entry['balance'] = $running / 100;
            }
            unset($entry);

            return [
                'month' => $month,
                'openingBalance' => $opening / 100,
                'invoicedTotal' => $invoiced / 100,
                'paidTotal' => $paid / 100,
                'closingBalance' => $running / 100,
                'entries' => $entries,
            ];
        });

        return response()->json($payload, 200, ['Cache-Control' => 'private, no-store']);
    }
}
