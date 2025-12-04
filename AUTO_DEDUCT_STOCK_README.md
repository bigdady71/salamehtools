# Auto-Deduct Stock Feature

## Overview
The warehouse system now automatically deducts stock when orders are marked as prepared. This eliminates manual stock adjustments and ensures inventory accuracy.

## How It Works

### 1. Stock Availability Check
Before marking an order as prepared, the system checks if sufficient stock is available:
- Compares quantities needed vs quantities in stock
- Shows detailed error messages if stock is insufficient
- Prevents order preparation when stock is unavailable

### 2. Automatic Stock Deduction
When warehouse staff marks an order as "Prepared":
- System automatically deducts quantities from inventory
- Handles both **warehouse stock** (s_stock) and **van stock** (van_stock_items)
- Creates audit trail in stock_movements table
- Shows success confirmation

### 3. Order Type Handling

#### Regular Orders (Company Orders)
- Deducts from `s_stock` table (warehouse inventory)
- Updates `qty_on_hand` for each product
- Creates movement record with reason='sale'

#### Van Stock Sales
- Deducts from `van_stock_items` table (sales rep's van)
- Updates quantity for specific sales rep
- Creates movement record linked to sales rep

## Database Tables Used

### s_stock
- `qty_on_hand` - Current warehouse inventory
- `product_id` - Product reference
- `location` - Warehouse location

### van_stock_items
- `quantity` - Current van inventory
- `sales_rep_id` - Sales representative
- `product_id` - Product reference

### s_stock_movements (Audit Trail)
- `salesperson_id` - User who performed action
- `product_id` - Product affected
- `delta_qty` - Quantity change (negative for deductions)
- `reason` - 'sale' for order shipments
- `note` - Order reference
- `created_at` - Timestamp

## Error Handling

### Insufficient Stock
If any item in the order doesn't have enough stock:
- Transaction is rolled back (no partial updates)
- Error message shows which items are short
- Format: "SKU (Product Name): need X, have Y"

### Database Errors
If stock deduction fails:
- Order status is rolled back
- Error is logged to PHP error log
- User sees error message

## Functions (in includes/stock_functions.php)

### deductStockForOrder()
```php
deductStockForOrder(PDO $pdo, int $orderId, int $userId): bool
```
- Main function that deducts stock when order ships
- Returns true on success, false on failure
- Uses database transactions for data integrity

### checkStockAvailability()
```php
checkStockAvailability(PDO $pdo, int $orderId): array
```
- Checks if order can be fulfilled
- Returns array with 'available' (bool) and 'shortage' (array)
- Shows exactly which items are short and by how much

### reserveStockForOrder()
```php
reserveStockForOrder(PDO $pdo, int $orderId): bool
```
- (Future feature) Reserves stock when order is approved
- Prevents overselling during order preparation

### releaseStockReservation()
```php
releaseStockReservation(PDO $pdo, int $orderId): bool
```
- (Future feature) Releases reserved stock if order is cancelled

## User Experience

### Before (Manual Process)
1. Warehouse staff prepares order
2. Marks order as ready
3. **Separately** goes to stock adjustments
4. **Manually** enters each product deduction
5. Risk of forgetting or entering wrong quantities

### After (Automated Process)
1. Warehouse staff prepares order
2. Clicks "Mark as Prepared"
3. **System automatically**:
   - Checks stock availability
   - Deducts correct quantities
   - Creates audit records
   - Shows confirmation
4. Done! No manual steps needed

## Testing

To test the feature:

1. Login as warehouse user
2. Go to "Orders to Prepare"
3. Select an order with items in stock
4. Click "Mark as Prepared"
5. Check success message: "Order marked as prepared! Stock has been automatically deducted."
6. Verify in "Stock Movements" page that movements were created
7. Verify in "Products" page that quantities were reduced

## Benefits

✅ **Accuracy** - No manual entry errors
✅ **Speed** - One click instead of multiple steps
✅ **Audit Trail** - Automatic movement records
✅ **Prevention** - Cannot ship orders without stock
✅ **Simplicity** - Warehouse staff just click one button

## Future Enhancements

- Email notifications when stock is deducted
- Automatic reorder point alerts after deduction
- Batch processing for multiple orders
- Undo function for accidental deductions
- Stock reservation when orders are approved (prevents overselling)
