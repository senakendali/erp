<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\ProcurementLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PurchaseRequestController extends Controller
{
    /**
     * Display a listing of the purchase requests.
     */
    public function index(Request $request)
    {
        $query = PurchaseRequest::with('items');
    
        switch ($request->get('request_type')) {
            case 'material':
                $query->whereHas('items', function ($q) {
                    $q->where('request_type', 'material');
                });
                break;
            case 'non-material':
                $query->whereHas('items', function ($q) {
                    $q->where('request_type', 'non-material');
                });
                break;
            default:
                // All mode (default)
                break;
        }

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }
    
        return response()->json($query->paginate(10));
    }

    /**
     * Store a newly created purchase request in storage.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'request_type' => 'required|in:material,non-material', // Added validation for request_type
                'buyer' => 'nullable|string|max:255',
                'purchase_reason' => 'nullable|string|max:255',
                'purchase_reason_detail' => 'nullable|string|max:255',
                'department_id' => 'nullable|exists:departments,id',
                'notes' => 'nullable|string',
                'created_by' => 'required|string|max:255',
                'items' => 'required|array',
                'items.*.goods_id' => 'required|exists:goods,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.measurement_id' => 'required|exists:measurement_units,id', // Adjusted field
            ]);

            // Calculate total items
            $totalItems = count($validated['items']);
            $currentDate = now(); // Use the current date and time

            // Create purchase request
            $purchaseRequest = PurchaseRequest::create([
                'request_type' => $validated['request_type'], // Include request_type in the creation
                'request_date' => $currentDate,
                'buyer' => $validated['buyer'] ?? null,
                'purchase_reason' => $validated['purchase_reason'] ?? null,
                'purchase_reason_detail' => $validated['purchase_reason_detail'] ?? null,
                'department_id' => $validated['department_id'] ?? 1, // Default department ID
                'total_items' => $totalItems,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $validated['created_by'],
            ]);

            // Add items to the purchase request
            foreach ($validated['items'] as $item) {
                $purchaseRequest->items()->create([
                    'goods_id' => $item['goods_id'],
                    'quantity' => $item['quantity'],
                    'measurement_id' => $item['measurement_id'], // Add new measurement_id
                ]);
            }

            ProcurementLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'log_name' => 'Purchase Request Created',
                'log_description' => 'Permintaan Dibuat',
                //'user_id' => Auth::user()->id,
            ]);

            ProcurementLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'log_name' => 'Waiting for Approval',
                'log_description' => 'Menunggu Persetujuan',
                //'user_id' => Auth::user()->id,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Purchase request created successfully.',
                'data' => $purchaseRequest->load('items'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while creating the purchase request.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }




    /**
     * Display the specified purchase request.
     */
    public function show($id)
    {
        // Load the purchase request with related items, goods, categories, and measurement units
        $purchaseRequest = PurchaseRequest::with([
            'items.goods.category'  // Load goods, category
        ])->find($id);

        // Check if the purchase request exists
        if (!$purchaseRequest) {
            return response()->json(['message' => 'Purchase request not found.'], 404);
        }

        try {
            // Transform the response to include only the necessary details
            $purchaseRequestData = [
                'id' => $purchaseRequest->id,
                'request_date' => $purchaseRequest->request_date,
                'approval_date' => $purchaseRequest->approval_date,
                'status' => $purchaseRequest->status,
                'buyer' => $purchaseRequest->buyer,
                'hod' => $purchaseRequest->hod,
                'department_id' => $purchaseRequest->department_id,
                'purchase_reason' => $purchaseRequest->purchase_reason,
                'created_at' => $purchaseRequest->created_at,
                'updated_at' => $purchaseRequest->updated_at,
                'items' => $purchaseRequest->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'goods_id' => $item->goods->id,
                        'goods_name' => $item->goods->name ?? null, // Goods name
                        'goods_category_name' => $item->goods->category->name ?? null, // Category name
                        'quantity' => $item->quantity ?? null, // Quantity
                        'measurement_id' => $item->measurementUnit->id ?? null, // Measurement unit
                        'measurement' => $item->measurementUnit->name ?? null, // Measurement unit
                    ];
                }),
            ];

            return response()->json($purchaseRequestData);
        } catch (\Exception $e) {
            // Catch any errors and log them for debugging
            \Log::error('Error fetching purchase request data: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'An error occurred while processing your request.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }




    /**
     * Update the specified purchase request in storage.
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $purchaseRequest = PurchaseRequest::findOrFail($id);

            $validated = $request->validate([
                'request_type' => 'nullable|in:material,non-material', // Allow optional update for request_type
                'buyer' => 'nullable|string|max:255',
                'purchase_reason' => 'nullable|string|in:Pembelian Pertama,Restock,Sample',
                'purchase_reason_detail' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'updated_by' => 'nullable|string|max:255',
                'items' => 'nullable|array', // Items can be optional during update
                'items.*.goods_id' => 'required_with:items|exists:goods,id',
                'items.*.quantity' => 'required_with:items|integer|min:1',
                'items.*.measurement_id' => 'required_with:items|exists:measurement_units,id', // Adjusted field
            ]);
            
            // Update fields in purchase request
            $purchaseRequest->update([
                'request_type' => $validated['request_type'] ?? $purchaseRequest->request_type, // Handle request_type update
                'buyer' => $validated['buyer'] ?? $purchaseRequest->buyer,
                'purchase_reason' => $validated['purchase_reason'] ?? $purchaseRequest->purchase_reason,
                'purchase_reason_detail' => $validated['purchase_reason_detail'] ?? $purchaseRequest->purchase_reason_detail,
                'notes' => $validated['notes'] ?? $purchaseRequest->notes,
                'updated_by' => $validated['updated_by'] ?? 'System', // Default to 'System' if not provided
            ]);

            if (isset($validated['items'])) {
                // Remove existing items and add the updated items
                $purchaseRequest->items()->delete();

                foreach ($validated['items'] as $item) {
                    $purchaseRequest->items()->create([
                        'goods_id' => $item['goods_id'],
                        'quantity' => $item['quantity'],
                        'measurement_id' => $item['measurement_id'], // Add measurement_id
                    ]);
                }

                // Update total_items based on new items
                $purchaseRequest->update([
                    'total_items' => count($validated['items']),
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Purchase request updated successfully.',
                'data' => $purchaseRequest->load('items'),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while updating the purchase request.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    public function followUp(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            // Find the purchase request by ID
            $purchaseRequest = PurchaseRequest::findOrFail($id);

            // Check if the purchase request already has a buyer
            if ($purchaseRequest->buyer !== null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This purchase request already has a buyer.',
                ], 400);
            }

            // Validate the request data, including the new fields
            $validated = $request->validate([
                'buyer' => 'required|string|max:255',
                'purchase_reason' => 'nullable|string|in:Pembelian Pertama,Restock,Sample',
                'purchase_reason_detail' => 'nullable|string|max:255',
            ]);

            // Update the purchase request with the validated data
            $purchaseRequest->update([
                'buyer' => $validated['buyer'],
                'purchase_reason' => $validated['purchase_reason'] ?? null, // New field
                'purchase_reason_detail' => $validated['purchase_reason_detail'] ?? null, // New field
                'followed_by' => Auth::user()->name,
            ]);

            ProcurementLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'log_name' => 'Follow Up Purchase Request',
                'log_description' => 'Permintaan Di Follow Up',
                'user_id' => Auth::user()->id,
            ]);

            // Commit the transaction
            DB::commit();

            // Return success response with the updated purchase request
            return response()->json([
                'status' => 'success',
                'message' => 'Purchase request followed up successfully.',
                'data' => $purchaseRequest,
            ], 200);

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred during the follow-up process.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function updateStatus(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $purchaseRequest = PurchaseRequest::findOrFail($id);

            $validated = $request->validate([
                'status' => 'required|in:approved,revised,rejected',
                'update_status_reason' => 'nullable|string|max:1000', // Optional by default
                'update_status_by' => 'nullable|string|max:255', 
            ]);
            

            // Ensure reason is provided for revised or rejected statuses
            if (in_array($validated['status'], ['revised', 'rejected']) && empty($validated['update_status_reason'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Reason is required when the status is "revised" or "rejected".',
                ], 422);
            }

            $updateData = [
                'status' => $validated['status'],
                'update_status_by' => Auth::user()->name,
            ];

            if (!empty($validated['update_status_reason'])) {
                $updateData['update_status_reason'] = $validated['update_status_reason'];
            }

            // Set approval date if the status is approved
            if ($validated['status'] === 'approved') {
                $updateData['approval_date'] = now();
            }

            $purchaseRequest->update($updateData);

            //approved,revised,rejected',
            if ($validated['status'] === 'approved') {
                $log_description = 'Permintaan Disetujui';
            }else if($validated['status'] === 'revised'){
                $log_description = 'Permintaan Direvisi';
            }else{
                $log_description = 'Permintaan Ditolak';
            }
            ProcurementLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'log_name' => $validated['status'].' Purchase Request',
                'log_description' => $log_description,
                'user_id' => Auth::user()->id,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Purchase request status updated successfully.',
                'data' => $purchaseRequest,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while updating the purchase request status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getPurchaseHistory($goodsId, $departmentId){
        try {
            $purchaseHistory = PurchaseRequestItem::with(['purchaseRequest', 'goods'])
                ->where('goods_id', $goodsId)
                ->whereHas('purchaseRequest', function ($query) use ($departmentId) {
                    $query->where('department_id', $departmentId);
                })
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'purchase_request_id' => $item->purchase_request_id,
                        'goods_id' => $item->goods_id,
                        'goods_name' => $item->goods ? $item->goods->name : null, // Extract name from goods
                        'category_name' => $item->goods->category ? $item->goods->category->name : null, // Extract name from category
                        'quantity' => $item->quantity,
                        'measurement' => $item->measurement,
                        'purchase_request' => $item->purchaseRequest, // Include full purchase request details
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ];
                });
        
            return response()->json([
                'success' => true,
                'data' => $purchaseHistory
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching purchase history.',
                'error' => $e->getMessage()
            ], 500);
        }
            
    }

    /**
     * Remove the specified purchase request from storage.
     */
    public function destroy($id)
    {
        try {
            $purchaseRequest = PurchaseRequest::find($id);

            if (!$purchaseRequest) {
                return response()->json(['message' => 'Purchase request not found.'], 404);
            }

            $purchaseRequest->delete();

            return response()->json(['message' => 'Purchase request deleted successfully.'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while deleting the purchase request.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
