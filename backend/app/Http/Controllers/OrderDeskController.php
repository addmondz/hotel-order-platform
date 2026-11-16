<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderDeskController extends Controller
{
    private function input(Request $request): array
    {
        $body = $request->getContent();
        abort_unless($request->isJson() && str_starts_with(ltrim($body), '{'), 400, 'A JSON object is required.');
        try {
            json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(400, 'Invalid JSON.');
        }

        return $request->json()->all();
    }

    private function field(mixed $value, string $label, int $max, bool $required = false): string
    {
        if ($value === null && !$required) {
            return '';
        }
        abort_unless(is_string($value), 400, "{$label} must be text.");
        $value = trim($value);
        abort_if(($required && $value === '') || mb_strlen($value) > $max, 400, "{$label} must be ".($required ? "between 1 and {$max}" : "at most {$max}").' characters.');

        return $value;
    }

    private function positiveId(mixed $value, string $label = 'ID'): int
    {
        abort_unless((is_int($value) || is_string($value)) && preg_match('/^\d{1,15}$/D', (string) $value) && (int) $value > 0, 400, "{$label} must be a positive integer.");

        return (int) $value;
    }

    private function scaled(mixed $value, int $places, int $maximum, string $label, bool $positive = false): int
    {
        abort_unless((is_int($value) || is_float($value) || is_string($value)) && preg_match('/^\d{1,8}(?:\.\d{1,'.$places.'})?$/D', (string) $value), 400, "{$label} must have up to {$places} decimal places.");
        $parts = explode('.', (string) $value, 2);
        $result = (int) $parts[0] * (10 ** $places) + (int) str_pad($parts[1] ?? '', $places, '0');
        abort_if($result > $maximum || ($positive && $result === 0), 400, "{$label} is out of range.");

        return $result;
    }

    private function cents(mixed $value): int
    {
        return $this->scaled($value, 2, 100_000_000, 'Price');
    }

    private function customer(int $id): object
    {
        return DB::table('customers')->find($id) ?? abort(404, 'Customer not found.');
    }

    private function customerData(array $input): array
    {
        return [
            'name' => $this->field($input['name'] ?? null, 'Customer name', 150, true),
            'phone' => $this->field($input['phone'] ?? null, 'Phone', 50),
            'notes' => $this->field($input['notes'] ?? null, 'Notes', 2000),
        ];
    }

    public function listCustomers()
    {
        return response()->json(DB::table('customers')->orderBy('name')->orderBy('id')->get());
    }

    public function createCustomer(Request $request)
    {
        $id = DB::table('customers')->insertGetId($this->customerData($this->input($request)));

        return response()->json($this->customer($id), 201);
    }

    public function updateCustomer(Request $request, string $id)
    {
        $id = $this->positiveId($id);
        $this->customer($id);
        DB::table('customers')->where('id', $id)->update($this->customerData($this->input($request)));

        return response()->json($this->customer($id));
    }

    private function productPayload(object $record, iterable $units): array
    {
        $rows = [];
        foreach ($units as $unit) {
            $rows[] = ['unit' => $unit->unit, 'price' => $unit->price / 100, 'stock' => $unit->stock === null ? null : $unit->stock / 1000];
        }

        return [
            'id' => (int) $record->id, 'name' => $record->name,
            'sku' => $record->sku ?? '', 'active' => (bool) $record->active, 'units' => $rows,
        ];
    }

    public function listProducts()
    {
        $units = DB::table('product_units')->orderBy('id')->get()->groupBy('product_id');

        return response()->json(DB::table('products')->orderBy('name')->orderBy('id')->get()->map(
            fn ($product) => $this->productPayload($product, $units->get($product->id, []))
        ));
    }

    public function createProduct(Request $request)
    {
        return response()->json($this->writeProduct($this->input($request)), 201);
    }

    public function updateProduct(Request $request, string $id)
    {
        return response()->json($this->writeProduct($this->input($request), $this->positiveId($id)));
    }

    private function writeProduct(array $input, ?int $id = null): array
    {
        $units = $input['units'] ?? [];
        abort_unless(is_array($units) && array_is_list($units) && count($units) >= 1 && count($units) <= 20, 400, 'A product needs 1–20 selling units.');
        abort_if(array_key_exists('active', $input) && !is_bool($input['active']), 400, 'Active must be true or false.');
        $newProduct = [
            'name' => $this->field($input['name'] ?? null, 'Product name', 150, true),
            'sku' => $this->field($input['sku'] ?? null, 'SKU', 64),
            'active' => $input['active'] ?? true,
        ];
        if ($newProduct['sku'] === '') {
            $newProduct['sku'] = null;
        }
        $newUnits = [];
        foreach ($units as $unit) {
            abort_unless(is_array($unit), 400, 'Invalid product unit.');
            $key = mb_strtolower($this->field($unit['unit'] ?? null, 'Unit', 32, true));
            abort_if(array_key_exists($key, $newUnits), 400, 'Selling units must be unique.');
            $stock = $unit['stock'] ?? null;
            $newUnits[$key] = [
                'unit' => $key,
                'price' => $this->cents($unit['price'] ?? null),
                'stock' => $stock === null || $stock === '' ? null : $this->scaled($stock, 3, 10_000_000, 'Stock'),
            ];
        }

        try {
            return DB::transaction(function () use ($id, $newProduct, $newUnits) {
                if ($id !== null) {
                    abort_unless(DB::table('products')->where('id', $id)->lockForUpdate()->first(), 404, 'Product not found.');
                }

                if ($newProduct['sku'] !== null) {
                    abort_if(DB::table('products')->whereRaw('LOWER(sku) = ?', [mb_strtolower($newProduct['sku'])])->where('id', '!=', $id ?? 0)->exists(), 409, 'This SKU is already in use.');
                }
                if ($id === null) {
                    $id = DB::table('products')->insertGetId($newProduct);
                } else {
                    DB::table('products')->where('id', $id)->update($newProduct);
                }
                $oldUnits = DB::table('product_units')->where('product_id', $id)->get();
                foreach ($oldUnits as $oldUnit) {
                    if (array_key_exists($oldUnit->unit, $newUnits)) {
                        continue;
                    }
                    abort_if(DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                        ->where('orders.status', 'open')->where('order_items.product_id', $id)
                        ->where('order_items.unit', $oldUnit->unit)->exists(), 409,
                        'This unit is used on an open order. Close or edit that order before removing the unit.');
                    DB::table('product_units')->where('id', $oldUnit->id)->delete();
                }
                foreach ($newUnits as $record) {
                    DB::table('product_units')->updateOrInsert(
                        ['product_id' => $id, 'unit' => $record['unit']], ['price' => $record['price'], 'stock' => $record['stock']]
                    );
                }

                return $this->productPayload(DB::table('products')->find($id), DB::table('product_units')->where('product_id', $id)->orderBy('id')->get());
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This SKU or selling unit is already in use.');
        }
    }

    public function listPrices(Request $request)
    {
        $customer = $this->positiveId($request->query('customerId'), 'Customer ID');
        $this->customer($customer);
        $rows = DB::table('customer_prices as prices')->join('product_units as units', 'units.id', '=', 'prices.product_unit_id')
            ->where('prices.customer_id', $customer)
            ->orderBy('units.product_id')->orderBy('units.unit')
            ->get(['prices.customer_id', 'units.product_id', 'units.unit', 'prices.price']);

        return response()->json($rows->map(fn ($r) => [
            'customerId' => (int) $r->customer_id, 'productId' => (int) $r->product_id,
            'unit' => $r->unit, 'price' => $r->price / 100,
        ]));
    }

    private function priceKey(array $input): array
    {
        return [
            $this->positiveId($input['customerId'] ?? null, 'Customer ID'),
            $this->positiveId($input['productId'] ?? null, 'Product ID'),
            mb_strtolower($this->field($input['unit'] ?? null, 'Unit', 32, true)),
        ];
    }

    public function savePrice(Request $request)
    {
        $input = $this->input($request);
        [$customer, $product, $unit] = $this->priceKey($input);
        $price = $this->cents($input['price'] ?? null);
        DB::transaction(function () use ($customer, $product, $unit, $price) {
            abort_unless(DB::table('products')->where('id', $product)->lockForUpdate()->first(), 404, 'Product not found.');
            $this->customer($customer);
            $productUnit = DB::table('product_units')->where('product_id', $product)->where('unit', $unit)->first();
            abort_unless($productUnit, 404, 'Product unit not found.');
            DB::table('customer_prices')->updateOrInsert(
                ['customer_id' => $customer, 'product_unit_id' => $productUnit->id], ['price' => $price]
            );
        }, 3);

        return response()->json(['customerId' => $customer, 'productId' => $product, 'unit' => $unit, 'price' => $price / 100]);
    }

    public function deletePrice(Request $request)
    {
        [$customer, $product, $unit] = $this->priceKey($request->getContent() === '' ? $request->query() : $this->input($request));
        DB::transaction(function () use ($customer, $product, $unit) {
            if (!DB::table('products')->where('id', $product)->lockForUpdate()->first()) {
                return;
            }
            $productUnit = DB::table('product_units')->where('product_id', $product)->where('unit', $unit)->first();
            if ($productUnit) {
                DB::table('customer_prices')->where('customer_id', $customer)->where('product_unit_id', $productUnit->id)->delete();
            }
        }, 3);

        return response()->json(['ok' => true]);
    }

    private function ordersQuery()
    {
        return DB::table('orders')
            ->join('users as creator', 'creator.id', '=', 'orders.created_by')
            ->join('users as editor', 'editor.id', '=', 'orders.updated_by')
            ->leftJoin('users as issuer', 'issuer.id', '=', 'orders.issued_by')
            ->select(['orders.*', 'creator.username as created_username', 'editor.username as updated_username', 'issuer.username as issued_username']);
    }

    private function isoDate(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->toISOString();
    }

    private function orderPayload(object $record, bool $includeItems): array
    {
        $result = [
            'id' => (int) $record->id,
            'customerId' => (int) $record->customer_id,
            'customerName' => $record->customer_name,
            'customerPhone' => $record->customer_phone,
            'status' => $record->status,
            'notes' => $record->notes,
            'total' => $record->total / 100,
            'invoiceNumber' => $record->invoice_number,
            'issuedAt' => $this->isoDate($record->issued_at),
            'createdAt' => $this->isoDate($record->created_at),
            'updatedAt' => $this->isoDate($record->updated_at),
            'revision' => (int) $record->revision,
            'createdBy' => $record->created_username,
            'updatedBy' => $record->updated_username,
            'issuedBy' => $record->issued_username,
        ];
        if ($includeItems) {
            $result['items'] = DB::table('order_items')->where('order_id', $record->id)->orderBy('position')->get()->map(fn ($line) => [
                'productId' => (int) $line->product_id,
                'productName' => $line->product_name,
                'unit' => $line->unit,
                'quantity' => $line->quantity / 1000,
                'unitPrice' => $line->unit_price / 100,
                'lineTotal' => $line->line_total / 100,
            ])->all();
        }

        return $result;
    }

    private function order(int $id, bool $includeItems = true): array
    {
        $record = $this->ordersQuery()->where('orders.id', $id)->first() ?? abort(404, 'Order not found.');

        return $this->orderPayload($record, $includeItems);
    }

    public function listOrders()
    {
        return response()->json($this->ordersQuery()->orderByDesc('orders.id')->get()->map(fn ($record) => $this->orderPayload($record, false)));
    }

    public function showOrder(string $id)
    {
        return response()->json($this->order($this->positiveId($id)));
    }

    public function orderHistory(string $id)
    {
        $id = $this->positiveId($id);
        $this->order($id, false);
        $events = DB::table('order_events')->join('users', 'users.id', '=', 'order_events.user_id')
            ->where('order_id', $id)->orderByDesc('order_events.id')->get(['username', 'action', 'happened_at']);

        return response()->json($events->map(fn ($log) => ['username' => $log->username, 'action' => $log->action, 'happenedAt' => $this->isoDate($log->happened_at)]));
    }

    private function expectRevision(array $input, object $order): void
    {
        if (isset($input['revision'])) {
            abort_if($this->positiveId($input['revision'], 'Order version') !== (int) $order->revision, 409, 'Another employee changed this order. Reload it before using your changes.');
        }
    }

    private function orderEvent(int $order, int $user, string $action, string $at): void
    {
        DB::table('order_events')->insert(['order_id' => $order, 'user_id' => $user, 'action' => $action, 'happened_at' => $at]);
    }

    public function createOrder(Request $request)
    {
        return response()->json($this->writeOrder($this->input($request), (int) $request->user()->id), 201);
    }

    public function updateOrder(Request $request, string $id)
    {
        return response()->json($this->writeOrder($this->input($request), (int) $request->user()->id, $this->positiveId($id)));
    }

    private function writeOrder(array $input, int $userId, ?int $orderId = null): array
    {
        $customerId = $this->positiveId($input['customerId'] ?? null, 'Customer ID');
        $notes = $this->field($input['notes'] ?? null, 'Notes', 4000);
        $items = $input['items'] ?? [];
        abort_unless(is_array($items) && array_is_list($items) && count($items) >= 1 && count($items) <= 150, 400, 'An order needs 1–150 items.');
        $validated = [];
        foreach ($items as $item) {
            abort_unless(is_array($item), 400, 'Invalid order item.');
            $validated[] = [
                'product_id' => $this->positiveId($item['productId'] ?? null, 'Product ID'),
                'unit' => mb_strtolower($this->field($item['unit'] ?? null, 'Unit', 32, true)),
                'quantity' => $this->scaled($item['quantity'] ?? null, 3, 10_000_000, 'Quantity', true),
                'unit_price' => isset($item['unitPrice']) ? $this->cents($item['unitPrice']) : null,
            ];
        }

        return DB::transaction(function () use ($input, $customerId, $notes, $validated, $userId, $orderId) {
            $old = null;
            if ($orderId !== null) {
                $old = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first() ?? abort(404, 'Order not found.');
                abort_unless($old->status === 'open', 409, 'Only open orders can be edited.');
                $this->expectRevision($input, $old);
            }
            // Serialize catalog changes with every order write that uses the affected products.
            $productIds = array_values(array_unique(array_column($validated, 'product_id')));
            $products = DB::table('products')->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $customer = $this->customer($customerId);
            $oldUnits = [];
            if ($old) {
                foreach (DB::table('order_items')->where('order_id', $orderId)->get() as $line) {
                    $oldUnits[$line->product_id][$line->unit] = true;
                }
            }
            $catalog = [];
            foreach (DB::table('product_units as units')->leftJoin('customer_prices as prices', function ($join) use ($customerId) {
                $join->on('units.id', '=', 'prices.product_unit_id')->where('prices.customer_id', $customerId);
            })->whereIn('units.product_id', $productIds)
                ->get(['units.product_id', 'units.unit', 'units.price', 'prices.price as customer_price']) as $row) {
                $catalog[$row->product_id][$row->unit] = $row;
            }
            $lines = [];
            $total = 0;
            foreach ($validated as $position => $line) {
                $p = $line['product_id'];
                $u = $line['unit'];
                $unit = $catalog[$p][$u] ?? null;
                $product = $products->get($p);
                abort_unless($unit && $product && ($product->active || isset($oldUnits[$p][$u])), 400, 'Product or selling unit is unavailable.');
                $line['unit_price'] ??= (int) ($unit->customer_price ?? $unit->price);
                $line['line_total'] = intdiv($line['quantity'] * $line['unit_price'] + 500, 1000);
                $line['product_name'] = $product->name;
                $line['position'] = $position;
                $total += $line['line_total'];
                $lines[] = $line;
            }
            $at = now('UTC')->toDateTimeString();
            $order = [
                'customer_id' => $customerId, 'customer_name' => $customer->name, 'customer_phone' => $customer->phone,
                'notes' => $notes, 'total' => $total, 'updated_at' => $at, 'updated_by' => $userId,
            ];
            if ($old) {
                $order['revision'] = (int) $old->revision + 1;
                DB::table('orders')->where('id', $orderId)->update($order);
                DB::table('order_items')->where('order_id', $orderId)->delete();
            } else {
                $orderId = DB::table('orders')->insertGetId($order + ['created_at' => $at, 'created_by' => $userId]);
            }
            DB::table('order_items')->insert(array_map(fn ($line) => $line + ['order_id' => $orderId], $lines));
            $this->orderEvent($orderId, $userId, $old ? 'edited' : 'created', $at);

            return $this->order($orderId);
        }, 3);
    }

    public function invoiceOrder(Request $request, string $id)
    {
        return response()->json($this->changeOrderStatus($request, $this->positiveId($id), 'invoiced'));
    }

    public function cancelOrder(Request $request, string $id)
    {
        return response()->json($this->changeOrderStatus($request, $this->positiveId($id), 'cancelled'));
    }

    private function changeOrderStatus(Request $request, int $id, string $status): array
    {
        $input = $request->getContent() === '' ? [] : $this->input($request);
        $userId = (int) $request->user()->id;

        return DB::transaction(function () use ($input, $userId, $id, $status) {
            $order = DB::table('orders')->where('id', $id)->lockForUpdate()->first() ?? abort(404, 'Order not found.');
            if ($order->status === $status) {
                return $this->order($id);
            }
            abort_unless($order->status === 'open', 409, 'Only open orders can change status.');
            $this->expectRevision($input, $order);
            $at = now('UTC')->toDateTimeString();
            $updates = [
                'status' => $status, 'updated_at' => $at, 'updated_by' => $userId, 'revision' => (int) $order->revision + 1,
            ];
            if ($status === 'invoiced') {
                $updates += ['invoice_number' => sprintf('INV-%06d', $id), 'issued_at' => $at, 'issued_by' => $userId];
            }
            DB::table('orders')->where('id', $id)->update($updates);
            $this->orderEvent($id, $userId, $status, $at);

            return $this->order($id);
        }, 3);
    }
}
