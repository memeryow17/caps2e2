# 💊 Pharmacy Data Fix - Database Column Error

## 🔴 Problem Identified

The pharmacy component was failing to load data with this error:

```
Database error: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'p.unit_price' in 'field list'
```

---

## 🔍 Root Cause

The `getPharmacyProducts()` and `getLocationProducts()` functions in `Api/batch_tracking.php` were trying to access a column `p.unit_price` that **doesn't exist** in the `tbl_product` table.

The query was:
```sql
SELECT 
    p.product_id,
    p.product_name,
    p.unit_price,  ❌ This column doesn't exist!
    ...
```

---

## ✅ Solution Applied

### File: `Api/batch_tracking.php`

#### 1. Fixed `getPharmacyProducts()` function (Lines 924-959)

**REMOVED:**
- `p.unit_price` column reference

**ADDED:**
- `total_quantity` field (needed by frontend)
- `stock_status` field (calculated based on quantity)
  - `'out of stock'` when quantity = 0
  - `'low stock'` when quantity <= 10
  - `'in stock'` when quantity > 10

**Updated Query:**
```sql
SELECT DISTINCT
    p.product_id,
    p.product_name,
    p.barcode,
    c.category_name,
    b.brand,
    COALESCE((SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1), 0) as srp,
    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as quantity,
    COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) as total_quantity,
    p.status,
    s.supplier_name,
    p.expiration,
    l.location_name,
    COALESCE(NULLIF(first_transfer_batch.first_batch_srp, 0), (SELECT fs.srp FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id AND fs.available_quantity > 0 ORDER BY fs.expiration_date ASC LIMIT 1)) as first_batch_srp,
    CASE
        WHEN COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) = 0 THEN 'out of stock'
        WHEN COALESCE((SELECT SUM(fs.available_quantity) FROM tbl_fifo_stock fs WHERE fs.product_id = p.product_id), 0) <= 10 THEN 'low stock'
        ELSE 'in stock'
    END as stock_status
FROM tbl_product p
LEFT JOIN tbl_category c ON p.category_id = c.category_id
LEFT JOIN tbl_location l ON p.location_id = l.location_id
LEFT JOIN tbl_brand b ON p.brand_id = b.brand_id
LEFT JOIN tbl_supplier s ON p.supplier_id = s.supplier_id
LEFT JOIN (
    SELECT 
        tbd.product_id,
        tbd.srp as first_batch_srp,
        ROW_NUMBER() OVER (PARTITION BY tbd.product_id ORDER BY tbd.created_at ASC, tbd.id ASC) as rn
    FROM tbl_transfer_batch_details tbd
    WHERE tbd.srp > 0
) first_transfer_batch ON p.product_id = first_transfer_batch.product_id AND first_transfer_batch.rn = 1
WHERE l.location_name LIKE '%pharmacy%'
ORDER BY p.product_name ASC
```

#### 2. Fixed `getLocationProducts()` function (Lines 1031-1060)

**REMOVED:**
- `p.unit_price` column reference from SELECT
- `p.unit_price` from GROUP BY clause

**ADDED:**
- `total_quantity` field
- `stock_status` field

---

## 📊 Data Fields Now Returned

The pharmacy API now returns products with these fields:

| Field | Type | Description | Source |
|-------|------|-------------|--------|
| `product_id` | int | Product ID | `tbl_product` |
| `product_name` | string | Product name | `tbl_product` |
| `barcode` | string | Product barcode | `tbl_product` |
| `category_name` | string | Category | `tbl_category` |
| `brand` | string | Brand name | `tbl_brand` |
| `srp` | decimal | Selling price (FIFO first batch) | `tbl_fifo_stock` |
| `quantity` | int | Total available quantity | `tbl_fifo_stock` (SUM) |
| `total_quantity` | int | Same as quantity (for compatibility) | `tbl_fifo_stock` (SUM) |
| `status` | string | Product status | `tbl_product` |
| `stock_status` | string | `'in stock'` / `'low stock'` / `'out of stock'` | Calculated |
| `supplier_name` | string | Supplier | `tbl_supplier` |
| `expiration` | date | Expiration date | `tbl_product` |
| `location_name` | string | Location name | `tbl_location` |
| `first_batch_srp` | decimal | SRP from first transferred batch | `tbl_transfer_batch_details` |

---

## 🧪 Testing

### Test Page Created
- **File**: `test_pharmacy_data_verification.html`
- **URL**: `http://localhost/caps2e2/test_pharmacy_data_verification.html`

This page automatically tests:
1. ✅ Pharmacy products loading
2. ✅ FIFO stock verification
3. ✅ Pharmacy location check
4. ✅ Database tables access

### Manual Testing Steps
1. Open: `http://localhost/caps2e2/test_pharmacy_data_verification.html`
2. Wait for auto-tests to complete
3. Verify all sections show "✅ SUCCESS"
4. Check that pharmacy products are displayed

---

## 🎯 Expected Results

### If Pharmacy Has Products:
```json
{
  "success": true,
  "data": [
    {
      "product_id": 123,
      "product_name": "Paracetamol 500mg",
      "barcode": "1234567890",
      "category_name": "Medicine",
      "brand": "Biogesic",
      "srp": 5.50,
      "quantity": 100,
      "total_quantity": 100,
      "status": "active",
      "stock_status": "in stock",
      "supplier_name": "PharmaCorp",
      "expiration": "2025-12-31",
      "location_name": "Pharmacy",
      "first_batch_srp": 5.50
    }
  ]
}
```

### If Pharmacy Is Empty:
```json
{
  "success": true,
  "data": []
}
```

---

## 📝 Frontend Compatibility

The PharmacyStore.js component (`app/admin/components/PharmacyStore.js`) already expects these fields:
- ✅ `product_name`
- ✅ `barcode`
- ✅ `category_name`
- ✅ `brand`
- ✅ `total_quantity` or `quantity`
- ✅ `srp` or `first_batch_srp`
- ✅ `stock_status`
- ✅ `supplier_name`
- ✅ `expiration`

All required fields are now provided by the API! 🎉

---

## 🔄 Related Components

### PharmacyStore.js (Frontend)
- **Line 220-269**: `loadProducts()` function
- Calls: `api.getPharmacyProducts()`
- Already configured to handle the correct data structure

### API Handler
- **File**: `app/lib/apiHandler.js`
- Routes `getPharmacyProducts()` to `batch_tracking.php`
- No changes needed

---

## ⚡ Quick Verification

Run these tests to verify the fix:

```javascript
// Test 1: Basic products fetch
fetch('http://localhost/caps2e2/Api/batch_tracking.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ 
    action: 'get_pharmacy_products',
    location_name: 'pharmacy'
  })
})
.then(r => r.json())
.then(data => console.log('Pharmacy Products:', data));

// Test 2: Check for column errors
// Should return success: true (no more unit_price error!)
```

---

## 🐛 Troubleshooting

### Still Getting Errors?

**1. Check XAMPP MySQL is running**
```
http://localhost/phpmyadmin
```

**2. Verify database tables exist**
- `tbl_product`
- `tbl_fifo_stock`
- `tbl_category`
- `tbl_location`
- `tbl_supplier`

**3. Check pharmacy location exists**
```sql
SELECT * FROM tbl_location WHERE location_name LIKE '%pharmacy%';
```

**4. Verify products have been transferred to pharmacy**
```sql
SELECT p.product_name, fs.available_quantity, l.location_name
FROM tbl_product p
JOIN tbl_fifo_stock fs ON p.product_id = fs.product_id
JOIN tbl_location l ON p.location_id = l.location_id
WHERE l.location_name LIKE '%pharmacy%'
AND fs.available_quantity > 0;
```

---

## ✅ Verification Checklist

- [x] Removed `p.unit_price` column reference
- [x] Added `total_quantity` field
- [x] Added `stock_status` calculated field
- [x] Fixed `getPharmacyProducts()` function
- [x] Fixed `getLocationProducts()` function
- [x] PHP syntax validated (no errors)
- [x] Test page created
- [x] Documentation written

---

## 🎉 Status: FIXED!

The pharmacy component should now load data successfully without the "Column not found: unit_price" error.

**Next Steps:**
1. Refresh your browser
2. Open pharmacy section: `http://localhost:3000/admin` → Pharmacy tab
3. Verify products are displayed
4. Check statistics cards show correct data

---

**Fixed Date**: October 12, 2025  
**Files Modified**: `Api/batch_tracking.php`  
**Test Page**: `test_pharmacy_data_verification.html`  
**Status**: ✅ **COMPLETE**

