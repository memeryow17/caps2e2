# Medicine Bulk Setup Fix - Product Type & Units

## Issue Summary
When adding new products through the Warehouse interface:
1. **Product Type Issue**: Even when selecting "Medicine" as the product type, products were being saved as "Non-Medicine" in the database
2. **Unit Configuration Issue**: The bulk setup inputs (boxes, strips per box, tablets per strip) were not being saved to the `tbl_product_units` table

## Root Causes

### 1. Product Type Not Being Saved
In `Api/backend.php`, the `add_batch_entry` case was missing the `product_type` field in the INSERT statement for `tbl_product`.

**Before:**
```php
INSERT INTO tbl_product (
    product_name, category_id, barcode, description, 
    brand_id, supplier_id, location_id, batch_id, status, date_added
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
```

### 2. Bulk Configuration Data Not Being Saved
There was no code to insert the unit configuration data into `tbl_product_units` table after creating the product.

### 3. Bulk Flag Not Set in Frontend
In `app/Inventory_Con/Warehouse.js`, the `bulk` flag was always set to `0` regardless of the `configMode`.

## Changes Made

### 1. Backend Changes (`Api/backend.php`)

#### A. Added Missing Fields to INSERT Statement (Lines 8581-8588)
```php
INSERT INTO tbl_product (
    product_name, category_id, barcode, description, 
    brand_id, supplier_id, location_id, batch_id, status, date_added,
    product_type, bulk, prescription  // ✅ ADDED
) VALUES (
    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
)
```

#### B. Extract Product Type and Bulk Data (Lines 8631-8634)
```php
// Get product_type and bulk configuration
$product_type = $product['product_type'] ?? 'Non-Medicine';
$bulk = isset($product['bulk']) ? (int)$product['bulk'] : 0;
$prescription = isset($product['prescription']) ? (int)$product['prescription'] : 0;
```

#### C. Updated Product Parameters Array (Lines 8637-8651)
```php
$productParams = [
    $product['product_name'],
    $product['category_id'],
    $product['barcode'],
    $product['description'] ?? '',
    $brand_id,
    $product['supplier_id'] ?? 1,
    $location_id,
    $batch_id,
    'active',
    date('Y-m-d'),
    $product_type,      // ✅ ADDED
    $bulk,              // ✅ ADDED
    $prescription       // ✅ ADDED
];
```

#### D. Added Unit Configuration Logic (Lines 8685-8771)

**For Medicine Products with Bulk Mode:**
```php
if ($product_type === 'Medicine' && isset($product['configMode']) && $product['configMode'] === 'bulk') {
    if (isset($product['boxes']) && isset($product['strips_per_box']) && isset($product['tablets_per_strip'])) {
        // Calculate unit prices
        $srp = (float)($product['srp'] ?? 0);
        $strip_price = $srp * $tablets_per_strip;
        $box_price = $strip_price * $strips_per_box;
        
        // Insert units: tablet (base), strip, box
        // Insert into tbl_product_units...
        
        // Enable multi-unit support
        UPDATE tbl_product SET allow_multi_unit = 1, default_unit = 'tablet'
    }
}
```

**For Non-Medicine Products with Bulk Mode:**
```php
else if ($product_type === 'Non-Medicine' && isset($product['configMode']) && $product['configMode'] === 'bulk') {
    if (isset($product['boxes']) && isset($product['pieces_per_pack'])) {
        // Calculate unit prices
        $srp = (float)($product['srp'] ?? 0);
        $box_price = $srp * $pieces_per_pack;
        
        // Insert units: piece (base), box
        // Insert into tbl_product_units...
        
        // Enable multi-unit support
        UPDATE tbl_product SET allow_multi_unit = 1, default_unit = 'piece'
    }
}
```

### 2. Frontend Changes (`app/Inventory_Con/Warehouse.js`)

#### Updated tempProduct Creation (Lines 2755-2763)
```javascript
const tempProduct = {
    ...newProductForm,
    batch: currentBatchNumber,
    temp_id: Date.now(),
    status: "pending",
    created_at: new Date().toISOString(),
    bulk: newProductForm.configMode === "bulk" ? 1 : 0  // ✅ ADDED: Set bulk flag based on config mode
};
```

## How It Works Now

### When Adding a Medicine Product with Bulk Configuration:

1. **Frontend** (`Warehouse.js`):
   - User selects "Medicine" as product type
   - User selects "Bulk Mode" configuration
   - User enters: Boxes, Strips per Box, Tablets per Strip
   - `configMode` is set to "bulk"
   - `bulk` flag is set to `1`
   - Product data is added to temporary storage

2. **Backend** (`backend.php`):
   - Product is inserted into `tbl_product` with:
     - `product_type = 'Medicine'` ✅
     - `bulk = 1` ✅
     - `allow_multi_unit = 1` ✅
     - `default_unit = 'tablet'` ✅
   
   - FIFO stock entry is created in `tbl_fifo_stock`
   
   - Unit configurations are inserted into `tbl_product_units`:
     - **Tablet** (base unit): `unit_quantity = 1`, `is_base_unit = 1`
     - **Strip**: `unit_quantity = tablets_per_strip`
     - **Box**: `unit_quantity = strips_per_box * tablets_per_strip`

### When Adding a Non-Medicine Product with Bulk Configuration:

1. **Frontend**: Same as Medicine but with pieces_per_pack instead of strips/tablets

2. **Backend**:
   - Product is inserted with `product_type = 'Non-Medicine'`
   - Unit configurations:
     - **Piece** (base unit): `unit_quantity = 1`, `is_base_unit = 1`
     - **Box**: `unit_quantity = pieces_per_pack`

## Database Schema Utilized

### `tbl_product`
- `product_type` ENUM('Medicine','Non-Medicine') ✅ Now properly saved
- `bulk` TINYINT(1) ✅ Now properly saved
- `allow_multi_unit` TINYINT(1) ✅ Set to 1 when bulk mode
- `default_unit` VARCHAR(50) ✅ Set to 'tablet' or 'piece'

### `tbl_product_units`
- `unit_id` INT (Primary Key)
- `product_id` INT ✅ Links to tbl_product
- `unit_name` VARCHAR(50) ✅ 'tablet', 'strip', 'box', 'piece'
- `unit_quantity` INT ✅ How many base units in this unit
- `unit_price` DECIMAL(10,2) ✅ Calculated price for this unit
- `is_base_unit` TINYINT(1) ✅ 1 for base unit (tablet/piece)
- `status` ENUM('active','inactive') ✅ Set to 'active'

## Testing Instructions

### Test Case 1: Medicine Product with Bulk Configuration
1. Open Warehouse module
2. Click "Add New Product"
3. Fill in product details:
   - Product Name: "Test Medicine A"
   - Select Category: "Medicine" category
   - **Select Product Type: "Medicine"** ← This should now work!
   - Configuration Mode: "Bulk Mode (Boxes × Pieces)"
   - Fill Medicine Fields:
     - Number of Boxes: 10
     - Strips per Box: 10
     - Tablets per Strip: 10
   - SRP: 5.00 (per tablet)
   - Expiration Date: Select a date
4. Click "Add to Batch"
5. Click "Save Batch"

**Expected Results:**
- ✅ Product is saved with `product_type = 'Medicine'` (not 'Non-Medicine')
- ✅ `tbl_product_units` has 3 entries:
  - Tablet: quantity=1, price=5.00, is_base_unit=1
  - Strip: quantity=10, price=50.00, is_base_unit=0
  - Box: quantity=100, price=500.00, is_base_unit=0
- ✅ Total quantity in FIFO stock: 1000 tablets (10 boxes × 10 strips × 10 tablets)

### Test Case 2: Non-Medicine Product with Bulk Configuration
1. Open Warehouse module
2. Click "Add New Product"
3. Fill in product details:
   - Product Name: "Test Non-Medicine B"
   - Select Category: "Food" or other non-medicine category
   - **Select Product Type: "Non-Medicine"** ← Verify this is saved correctly
   - Configuration Mode: "Bulk Mode (Boxes × Pieces)"
   - Fill Non-Medicine Fields:
     - Number of Boxes: 5
     - Pieces per Box: 12
   - SRP: 10.00 (per piece)
   - Expiration Date: Select a date
4. Click "Add to Batch"
5. Click "Save Batch"

**Expected Results:**
- ✅ Product is saved with `product_type = 'Non-Medicine'`
- ✅ `tbl_product_units` has 2 entries:
  - Piece: quantity=1, price=10.00, is_base_unit=1
  - Box: quantity=12, price=120.00, is_base_unit=0
- ✅ Total quantity in FIFO stock: 60 pieces (5 boxes × 12 pieces)

## Verification Queries

### Check Product Type
```sql
SELECT product_id, product_name, product_type, bulk, allow_multi_unit, default_unit
FROM tbl_product
WHERE product_name LIKE 'Test%'
ORDER BY product_id DESC;
```

### Check Unit Configuration
```sql
SELECT pu.unit_id, p.product_name, pu.unit_name, pu.unit_quantity, pu.unit_price, pu.is_base_unit
FROM tbl_product_units pu
JOIN tbl_product p ON pu.product_id = p.product_id
WHERE p.product_name LIKE 'Test%'
ORDER BY p.product_id, pu.is_base_unit DESC, pu.unit_quantity ASC;
```

### Check FIFO Stock
```sql
SELECT fs.batch_id, p.product_name, fs.quantity, fs.available_quantity, fs.srp, fs.expiration_date
FROM tbl_fifo_stock fs
JOIN tbl_product p ON fs.product_id = p.product_id
WHERE p.product_name LIKE 'Test%'
ORDER BY fs.batch_id DESC;
```

## Error Logging

The backend now includes extensive error logging:
- Product type being saved
- Unit configuration data being inserted
- Calculated unit prices
- Any missing bulk configuration data

Check `php_errors.log` for detailed logs during product creation.

## Notes

1. **SRP Calculation**: The SRP entered in the form is assumed to be the price per **base unit** (tablet for Medicine, piece for Non-Medicine). Higher-level unit prices are calculated automatically.

2. **Backward Compatibility**: Products without bulk configuration (configMode = "pieces") will work as before, with no unit entries created.

3. **Validation**: Both frontend and backend validate that all required fields are present before creating the product.

4. **Transaction Safety**: All database operations are wrapped in a transaction, so if any part fails, the entire batch creation is rolled back.

## Files Modified

1. ✅ `Api/backend.php` - Lines 8580-8773
2. ✅ `app/Inventory_Con/Warehouse.js` - Lines 2755-2763

## Status: FIXED ✅

Both issues are now resolved:
- ✅ Product type is correctly saved to database
- ✅ Bulk configuration data is saved to `tbl_product_units` table

---

**Date Fixed:** October 12, 2025  
**Fixed By:** AI Assistant  
**Tested:** Ready for testing by user

