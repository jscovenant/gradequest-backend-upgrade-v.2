<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\StoreCategory;
use App\Models\StoreItem;
use App\Models\StoreSale;
use App\Models\StoreSaleItem;
use App\Models\StoreStockLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SchoolStoreController extends Controller
{
    private function getSchoolId(Request $request)
    {
        $user = $request->user();
        return $user->school_id ?? $user->school?->id ?? 1;
    }

    /* -------------------------------------------------------------
     * CATEGORIES
     * ------------------------------------------------------------- */
    public function getCategories(Request $request)
    {
        $schoolId = $this->getSchoolId($request);
        $categories = StoreCategory::where('school_id', $schoolId)
            ->withCount('items')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $categories
        ]);
    }

    public function saveCategory(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validator = Validator::make($request->all(), [
            'id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        if ($request->filled('id')) {
            $category = StoreCategory::where('school_id', $schoolId)->findOrFail($request->id);
            $category->update([
                'name' => $request->name,
                'slug' => Str::slug($request->name),
                'description' => $request->description,
                'is_active' => $request->boolean('is_active', $category->is_active),
            ]);
            $message = 'Category updated successfully';
        } else {
            $category = StoreCategory::create([
                'school_id' => $schoolId,
                'name' => $request->name,
                'slug' => Str::slug($request->name),
                'description' => $request->description,
                'is_active' => $request->boolean('is_active', true),
            ]);
            $message = 'Category created successfully';
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $category
        ]);
    }

    public function deleteCategory(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        $category = StoreCategory::where('school_id', $schoolId)->findOrFail($id);
        $category->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Category deleted successfully'
        ]);
    }

    /* -------------------------------------------------------------
     * ITEMS & INVENTORY
     * ------------------------------------------------------------- */
    public function getItems(Request $request)
    {
        $schoolId = $this->getSchoolId($request);
        $query = StoreItem::where('school_id', $schoolId)->with('category');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('item_type')) {
            $query->where('item_type', $request->item_type);
        }

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('item_code', 'like', "%{$search}%")
                  ->orWhere('class_target', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('low_stock_only')) {
            $query->whereRaw('current_stock <= reorder_level');
        }

        $items = $query->orderBy('name')->get();

        // Calculate inventory summary
        $totalItems = $items->count();
        $totalStockUnits = $items->sum('current_stock');
        $totalValuationCost = $items->sum(function ($item) {
            return $item->current_stock * $item->cost_price;
        });
        $totalValuationRetail = $items->sum(function ($item) {
            return $item->current_stock * $item->selling_price;
        });
        $lowStockCount = $items->filter(fn($i) => $i->current_stock <= $i->reorder_level)->count();

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'summary' => [
                'total_items' => $totalItems,
                'total_units' => $totalStockUnits,
                'valuation_cost' => $totalValuationCost,
                'valuation_retail' => $totalValuationRetail,
                'potential_profit' => $totalValuationRetail - $totalValuationCost,
                'low_stock_count' => $lowStockCount,
            ]
        ]);
    }

    public function saveItem(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validator = Validator::make($request->all(), [
            'id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'item_code' => 'nullable|string|max:100',
            'category_id' => 'nullable|exists:store_categories,id',
            'item_type' => 'required|in:uniform,book,stationery,crest,accessory,other',
            'cost_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'current_stock' => 'nullable|integer|min:0',
            'reorder_level' => 'nullable|integer|min:0',
            'unit' => 'nullable|string|max:50',
            'size' => 'nullable|string|max:50',
            'class_target' => 'nullable|string|max:100',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        if ($request->filled('id')) {
            $item = StoreItem::where('school_id', $schoolId)->findOrFail($request->id);
            $item->update([
                'name' => $request->name,
                'item_code' => $request->item_code ?: $item->item_code,
                'category_id' => $request->category_id,
                'item_type' => $request->item_type,
                'cost_price' => $request->cost_price,
                'selling_price' => $request->selling_price,
                'reorder_level' => $request->integer('reorder_level', $item->reorder_level),
                'unit' => $request->input('unit', $item->unit),
                'size' => $request->size,
                'class_target' => $request->class_target,
                'image_url' => $request->has('image_url') ? $request->image_url : $item->image_url,
                'is_active' => $request->boolean('is_active', $item->is_active),
                'description' => $request->description,
            ]);
            $message = 'Item updated successfully';
        } else {
            $initialStock = $request->integer('current_stock', 0);

            $item = DB::transaction(function () use ($request, $schoolId, $initialStock) {
                // Auto generate item code if empty
                $itemCode = $request->item_code;
                if (empty($itemCode)) {
                    $prefix = strtoupper(substr($request->item_type, 0, 3));
                    $itemCode = $prefix . '-' . strtoupper(Str::random(6));
                }

                $item = StoreItem::create([
                    'school_id' => $schoolId,
                    'category_id' => $request->category_id,
                    'name' => $request->name,
                    'item_code' => $itemCode,
                    'item_type' => $request->item_type,
                    'description' => $request->description,
                    'cost_price' => $request->cost_price,
                    'selling_price' => $request->selling_price,
                    'current_stock' => $initialStock,
                    'reorder_level' => $request->integer('reorder_level', 5),
                    'unit' => $request->input('unit', 'pcs'),
                    'size' => $request->size,
                    'class_target' => $request->class_target,
                    'image_url' => $request->image_url,
                    'is_active' => $request->boolean('is_active', true),
                ]);

                // Log initial stock if > 0
                if ($initialStock > 0) {
                    StoreStockLog::create([
                        'school_id' => $schoolId,
                        'store_item_id' => $item->id,
                        'type' => 'restock',
                        'quantity_change' => $initialStock,
                        'stock_before' => 0,
                        'stock_after' => $initialStock,
                        'unit_cost' => $request->cost_price,
                        'reference' => 'INITIAL-STOCK',
                        'reason' => 'Initial inventory opening balance',
                        'performed_by_user_id' => $request->user()?->id,
                    ]);
                }

                return $item;
            });
            $message = 'Inventory item created successfully';
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $item->load('category')
        ]);
    }

    public function restockItem(Request $request, $id = null)
    {
        $schoolId = $this->getSchoolId($request);
        $itemId = $id ?? $request->input('id') ?? $request->input('item_id');
        $item = StoreItem::where('school_id', $schoolId)->findOrFail($itemId);

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|not_in:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'reason' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:100',
            'type' => 'nullable|in:restock,adjustment,return,damaged',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $qtyChange = $request->integer('quantity');
        $type = $request->input('type', $qtyChange > 0 ? 'restock' : 'adjustment');

        $stockBefore = $item->current_stock;
        $stockAfter = max(0, $stockBefore + $qtyChange);

        DB::transaction(function () use ($item, $schoolId, $qtyChange, $stockBefore, $stockAfter, $type, $request) {
            $item->current_stock = $stockAfter;
            if ($request->filled('unit_cost')) {
                $item->cost_price = $request->unit_cost;
            }
            $item->save();

            StoreStockLog::create([
                'school_id' => $schoolId,
                'store_item_id' => $item->id,
                'type' => $type,
                'quantity_change' => $qtyChange,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'unit_cost' => $request->unit_cost ?? $item->cost_price,
                'reference' => $request->reference ?? 'MANUAL-RESTOCK',
                'reason' => $request->reason ?? ($qtyChange > 0 ? 'Added stock' : 'Stock adjustment'),
                'performed_by_user_id' => $request->user()?->id,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Stock adjusted successfully',
            'data' => $item->fresh()->load('category')
        ]);
    }

    public function deleteItem(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        $item = StoreItem::where('school_id', $schoolId)->findOrFail($id);
        $item->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Item deleted successfully'
        ]);
    }

    /* -------------------------------------------------------------
     * POS CHECKOUT & SALES
     * ------------------------------------------------------------- */
    public function processPosSale(Request $request)
    {
        return $this->checkout($request);
    }

    public function checkout(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:store_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'student_id' => 'nullable|exists:users,id',
            'buyer_name' => 'nullable|string|max:255',
            'buyer_phone' => 'nullable|string|max:50',
            'payment_method' => 'required|in:cash,pos_card,bank_transfer,wallet,split',
            'amount_tendered' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $sale = DB::transaction(function () use ($request, $schoolId) {
                $itemsData = $request->input('items');
                $discount = (float) $request->input('discount', 0);

                $receiptNo = 'SP-REC-' . date('Ymd') . '-' . strtoupper(Str::random(5));
                $subtotal = 0;
                $totalCost = 0;

                // Lock and fetch items
                $itemIds = array_column($itemsData, 'item_id');
                $storeItems = StoreItem::where('school_id', $schoolId)
                    ->whereIn('id', $itemIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // Validate stock availability
                foreach ($itemsData as $row) {
                    $item = $storeItems->get($row['item_id']);
                    if (!$item) {
                        throw new \Exception("Item #{$row['item_id']} not found or does not belong to your school.");
                    }
                    if ($item->current_stock < $row['quantity']) {
                        throw new \Exception("Insufficient stock for '{$item->name}'. Available: {$item->current_stock}, Requested: {$row['quantity']}");
                    }
                }

                // Calculate totals
                $preparedSaleItems = [];
                foreach ($itemsData as $row) {
                    $item = $storeItems->get($row['item_id']);
                    $qty = (int) $row['quantity'];
                    $unitPrice = isset($row['unit_price']) ? (float) $row['unit_price'] : (float) $item->selling_price;
                    $unitCost = (float) $item->cost_price;
                    $lineTotal = $qty * $unitPrice;
                    $lineCost = $qty * $unitCost;
                    $lineProfit = $lineTotal - $lineCost;

                    $subtotal += $lineTotal;
                    $totalCost += $lineCost;

                    $preparedSaleItems[] = [
                        'school_id' => $schoolId,
                        'store_item_id' => $item->id,
                        'item_name' => $item->name,
                        'item_code' => $item->item_code,
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                        'unit_price' => $unitPrice,
                        'total_price' => $lineTotal,
                        'profit' => $lineProfit,
                    ];
                }

                $totalAmount = max(0, $subtotal - $discount);
                $tendered = (float) $request->input('amount_tendered', $totalAmount);
                $changeDue = max(0, $tendered - $totalAmount);

                // Create Sale Record
                $sale = StoreSale::create([
                    'school_id' => $schoolId,
                    'receipt_number' => $receiptNo,
                    'student_id' => $request->student_id,
                    'buyer_name' => $request->buyer_name ?: ($request->student_id ? 'Student Purchase' : 'Walk-in Customer'),
                    'buyer_phone' => $request->buyer_phone,
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'tax' => 0.00,
                    'total_amount' => $totalAmount,
                    'total_cost' => $totalCost,
                    'amount_tendered' => $tendered,
                    'change_due' => $changeDue,
                    'payment_method' => $request->payment_method,
                    'payment_status' => 'paid',
                    'served_by_user_id' => $request->user()?->id,
                    'notes' => $request->notes,
                ]);

                // Create Sale Items & Deduct Stock
                foreach ($preparedSaleItems as $line) {
                    $line['sale_id'] = $sale->id;
                    StoreSaleItem::create($line);

                    $item = $storeItems->get($line['store_item_id']);
                    $stockBefore = $item->current_stock;
                    $stockAfter = $stockBefore - $line['quantity'];

                    $item->current_stock = $stockAfter;
                    $item->save();

                    // Stock audit log
                    StoreStockLog::create([
                        'school_id' => $schoolId,
                        'store_item_id' => $item->id,
                        'type' => 'sale',
                        'quantity_change' => -$line['quantity'],
                        'stock_before' => $stockBefore,
                        'stock_after' => $stockAfter,
                        'unit_cost' => $item->cost_price,
                        'reference' => $receiptNo,
                        'reason' => "POS Sale #{$receiptNo}",
                        'performed_by_user_id' => $request->user()?->id,
                    ]);
                }

                return $sale->load(['items', 'student', 'servedBy']);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Checkout completed successfully',
                'data' => $sale
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getSalesHistory(Request $request)
    {
        $schoolId = $this->getSchoolId($request);
        $query = StoreSale::where('school_id', $schoolId)
            ->with(['items', 'student', 'servedBy']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                  ->orWhere('buyer_name', 'like', "%{$search}%")
                  ->orWhere('buyer_phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date . ' 00:00:00', $request->end_date . ' 23:59:59']);
        }

        $sales = $query->orderBy('id', 'desc')->paginate($request->integer('per_page', 25));

        return response()->json([
            'status' => 'success',
            'data' => $sales,
        ]);
    }

    public function getStoreAnalytics(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $totalSalesAmount = (float) StoreSale::where('school_id', $schoolId)->sum('total_amount');
        $totalProfit = (float) StoreSale::where('school_id', $schoolId)->sum(DB::raw('total_amount - total_cost'));
        $totalTransactions = StoreSale::where('school_id', $schoolId)->count();

        // Today's stats
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $todaySales = (float) StoreSale::where('school_id', $schoolId)->whereBetween('created_at', [$todayStart, $todayEnd])->sum('total_amount');
        $todayProfit = (float) StoreSale::where('school_id', $schoolId)->whereBetween('created_at', [$todayStart, $todayEnd])->sum(DB::raw('total_amount - total_cost'));

        // Inventory values
        $items = StoreItem::where('school_id', $schoolId)->get();
        $totalStockUnits = $items->sum('current_stock');
        $inventoryCostValue = $items->sum(fn($i) => $i->current_stock * $i->cost_price);
        $inventoryRetailValue = $items->sum(fn($i) => $i->current_stock * $i->selling_price);
        $lowStockCount = $items->filter(fn($i) => $i->current_stock <= $i->reorder_level)->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_sales' => $totalSalesAmount,
                'total_profit' => $totalProfit,
                'total_transactions' => $totalTransactions,
                'avg_basket_value' => $totalTransactions > 0 ? round($totalSalesAmount / $totalTransactions, 2) : 0,
                'today_sales' => $todaySales,
                'today_profit' => $todayProfit,
                'total_stock_units' => $totalStockUnits,
                'inventory_cost_value' => $inventoryCostValue,
                'inventory_retail_value' => $inventoryRetailValue,
                'low_stock_count' => $lowStockCount,
            ]
        ]);
    }

        public function getSaleReceipt(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        $sale = StoreSale::where('school_id', $schoolId)
            ->with(['items.storeItem', 'student', 'servedBy', 'school'])
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('receipt_number', $id);
            })
            ->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => $sale
        ]);
    }
}
