# Inventory Transfer - Unit Selection Feature

## Issue Summary
When transferring products in the Inventory Transfer module, there was no option to select different units (tablets, strips, boxes for medicine; pieces, boxes for non-medicine). Users could only enter quantities without specifying the unit.

## Solution Implemented
Added a complete unit selection system that:
1. Identifies if a product is Medicine or Non-Medicine
2. Shows appropriate unit options based on product type
3. Automatically converts selected units to base units for transfer
4. Displays clear unit information and conversion rates

---

## Changes Made

### 1. Backend API (`Api/backend.php`)

#### Added `get_product_units` Endpoint (Lines 9662-9755)

**Purpose:** Fetch available units for a product from `tbl_product_units` table

**Features:**
- Retrieves product details including `product_type`, `allow_multi_unit`, `default_unit`
- Fetches all active units from `tbl_product_units`
- Returns default units if no units configured:
  - **Medicine**: tablet (base unit)
  - **Non-Medicine**: piece (base unit)

**Request:**
```javascript
{
  action: "get_product_units",
  product_id: 123
}
```

**Response:**
```javascript
{
  "success": true,
  "data": {
    "product": {
      "product_id": 123,
      "product_name": "Paracetamol",
      "product_type": "Medicine",
      "allow_multi_unit": 1,
      "default_unit": "tablet"
    },
    "units": [
      {
        "unit_id": 1,
        "unit_name": "tablet",
        "unit_quantity": 1,
        "unit_price": 5.00,
        "is_base_unit": 1,
        "status": "active"
      },
      {
        "unit_id": 2,
        "unit_name": "strip",
        "unit_quantity": 10,
        "unit_price": 50.00,
        "is_base_unit": 0,
        "status": "active"
      },
      {
        "unit_id": 3,
        "unit_name": "box",
        "unit_quantity": 100,
        "unit_price": 500.00,
        "is_base_unit": 0,
        "status": "active"
      }
    ]
  }
}
```

---

### 2. Frontend Changes (`app/Inventory_Con/InventoryTransfer.js`)

#### A. Added State Management (Lines 44-45)

```javascript
const [productUnits, setProductUnits] = useState({}) // Store units for each product
const [selectedUnits, setSelectedUnits] = useState({}) // Track selected unit per product
```

#### B. Added Unit Loading Functions (Lines 123-160)

**`loadProductUnits(productId)`**
- Fetches units for a single product
- Calls the `get_product_units` API endpoint
- Returns unit data or null

**`loadUnitsForProducts(products)`**
- Loads units for multiple products
- Sets default unit to base unit (or first available)
- Updates state with unit data

#### C. Updated Product Selection (Lines 1529-1543)

Modified `handleSelectProducts` to automatically load units when products are selected:

```javascript
const handleSelectProducts = async () => {
  const selected = availableProducts
    .filter((p) => checkedProducts.includes(p.product_id))
    .map((p) => ({ ...p, transfer_quantity: 0 }))
  
  setSelectedProducts(selected)
  
  // Load units for the selected products
  console.log("🔄 Loading units for selected products...");
  await loadUnitsForProducts(selected);
  
  setShowProductSelection(false)
}
```

#### D. Added Unit Change Handler (Lines 1574-1612)

**`handleUnitChange(productId, unitName)`**
- Updates selected unit for a product
- Resets transfer quantity to 0 (must re-enter after unit change)
- Shows toast notification

**`getBaseUnitQuantity(productId, quantity)`**
- Converts displayed quantity to base units
- Example: 5 strips × 10 tablets/strip = 50 tablets
- Used for actual transfer calculation

#### E. Added Unit Dropdown Column (Lines 2585-2663)

**Table Header:**
```html
<th>Unit</th>
<th>Transfer Qty</th>
```

**Table Cell:**
- Shows unit dropdown with all available units
- Displays unit information (e.g., "1 strip = 10 tablets")
- Visual indicator for Medicine (💊) vs Non-Medicine (📦)
- Falls back to base unit if no units configured

**Unit Dropdown Features:**
```javascript
{productUnits[product.product_id] && productUnits[product.product_id].length > 0 ? (
  <>
    <div>💊 Medicine Units / 📦 Product Units</div>
    <select>
      {productUnits[product.product_id].map((unit) => (
        <option value={unit.unit_name}>
          {unit.unit_name} {unit.unit_quantity > 1 && `(${unit.unit_quantity})`}
        </option>
      ))}
    </select>
    <div>1 {unit.unit_name} = {unit.unit_quantity} base units</div>
  </>
) : (
  <div>{product.product_type === 'Medicine' ? 'Tablet' : 'Piece'}</div>
)}
```

#### F. Updated Transfer Submission (Lines 1319-1336)

Modified `handleTransferSubmit` to convert quantities to base units:

```javascript
products: productsToTransfer.map((product) => {
  // Convert quantity to base units
  const baseUnitQuantity = getBaseUnitQuantity(product.product_id, product.transfer_quantity);
  const selectedUnit = selectedUnits[product.product_id];
  
  console.log(`📦 Product: ${product.product_name}`);
  console.log(`   Selected Unit: ${selectedUnit || 'base'}`);
  console.log(`   Input Quantity: ${product.transfer_quantity} ${selectedUnit || 'units'}`);
  console.log(`   Base Unit Quantity: ${baseUnitQuantity} base units`);
  
  return {
    product_id: product.product_id,
    quantity: baseUnitQuantity, // Send in base units
    unit_name: selectedUnit || (product.product_type === 'Medicine' ? 'tablet' : 'piece'),
    display_quantity: product.transfer_quantity, // Original display quantity
  };
}),
```

---

## How It Works

### For Medicine Products:

1. **Product Selection:**
   - User selects a medicine product (e.g., Paracetamol)
   - System loads available units from `tbl_product_units`:
     - Tablet (1 unit)
     - Strip (10 tablets)
     - Box (100 tablets)

2. **Unit Selection:**
   - User sees dropdown with: "Tablet (1)", "Strip (10)", "Box (100)"
   - Default selection is the base unit (tablet)
   - Conversion info displayed: "1 strip = 10 base units"

3. **Quantity Entry:**
   - User selects "Strip" and enters "5"
   - Label shows: "Enter in strips"
   - Visual: 💊 Medicine Units

4. **Transfer Submission:**
   - System converts: 5 strips × 10 tablets/strip = 50 tablets
   - Transfer data sent: `{ quantity: 50, unit_name: "strip", display_quantity: 5 }`
   - Backend processes 50 tablets (base units)

### For Non-Medicine Products:

1. **Product Selection:**
   - User selects a non-medicine product (e.g., Pinoy Spice)
   - System loads units:
     - Piece (1 unit)
     - Box (12 pieces)

2. **Unit Selection:**
   - User sees dropdown with: "Piece (1)", "Box (12)"
   - Default selection is "piece"
   - Conversion info: "1 box = 12 base units"

3. **Quantity Entry:**
   - User selects "Box" and enters "3"
   - Label shows: "Enter in boxes"
   - Visual: 📦 Product Units

4. **Transfer Submission:**
   - System converts: 3 boxes × 12 pieces/box = 36 pieces
   - Transfer data sent: `{ quantity: 36, unit_name: "box", display_quantity: 3 }`
   - Backend processes 36 pieces (base units)

---

## User Interface

### Unit Dropdown Column

```
┌─────────┬──────────────────┬────────────┬─────────────┐
│ Status  │      Unit        │ Transfer   │   Product   │
│         │                  │    Qty     │             │
├─────────┼──────────────────┼────────────┼─────────────┤
│ ✓       │ 💊 Medicine      │  Enter in  │ Paracetamol │
│ Selected│ [Tablet (1) ▼]   │  tablets   │             │
│ for     │ Strip (10)       │  [  50  ]  │             │
│ Transfer│ Box (100)        │            │             │
│         │                  │            │             │
│         │ 1 tablet =       │            │             │
│         │ 1 base unit      │            │             │
└─────────┴──────────────────┴────────────┴─────────────┘
```

**After selecting "Strip":**

```
┌─────────┬──────────────────┬────────────┬─────────────┐
│ Status  │      Unit        │ Transfer   │   Product   │
│         │                  │    Qty     │             │
├─────────┼──────────────────┼────────────┼─────────────┤
│ ✓       │ 💊 Medicine      │  Enter in  │ Paracetamol │
│ Selected│ Tablet (1)       │  strips    │             │
│ for     │ [Strip (10) ▼]   │  [   5  ]  │             │
│ Transfer│ Box (100)        │            │             │
│         │                  │ = 50       │             │
│         │ 1 strip =        │ tablets    │             │
│         │ 10 base units    │            │             │
└─────────┴──────────────────┴────────────┴─────────────┘
```

---

## Visual Indicators

### Medicine Products:
- 💊 **Medicine Units** label
- Blue theme color
- Shows: Tablet, Strip, Box options

### Non-Medicine Products:
- 📦 **Product Units** label
- Blue theme color
- Shows: Piece, Box options

---

## Conversion Examples

### Medicine Example 1:
```
Input: 5 strips
Conversion: 5 × 10 = 50 tablets
Transfer: 50 tablets (base units)
```

### Medicine Example 2:
```
Input: 2 boxes
Conversion: 2 × 100 = 200 tablets
Transfer: 200 tablets (base units)
```

### Non-Medicine Example:
```
Input: 3 boxes
Conversion: 3 × 12 = 36 pieces
Transfer: 36 pieces (base units)
```

---

## Error Handling

### No Units Configured:
- Falls back to base unit (tablet/piece)
- Shows simple text instead of dropdown
- Transfer proceeds normally

### Missing Product Data:
- Console warnings logged
- Graceful fallback to default behavior
- No crash or blocking errors

### Unit Change:
- Resets quantity to 0
- User must re-enter quantity
- Toast notification: "Unit changed to X. Please enter quantity again."

---

## Testing Instructions

### Test Case 1: Medicine with Multiple Units

1. Go to Inventory Transfer
2. Click "Create Transfer"
3. Select destination store
4. Select "Transfer Information" step
5. Select a Medicine product (e.g., Paracetamol) that has units configured
6. **Verify:**
   - Unit dropdown appears
   - Shows "💊 Medicine Units"
   - Has options: Tablet, Strip, Box
   - Shows conversion info
7. Select "Strip" from dropdown
8. Enter "5" in quantity field
9. **Verify:**
   - Label says "Enter in strips"
   - Console shows: "Base Unit Quantity: 50 base units"
10. Submit transfer
11. **Verify:** Transfer succeeds with 50 tablets

### Test Case 2: Non-Medicine with Multiple Units

1. Select a Non-Medicine product that has units configured
2. **Verify:**
   - Shows "📦 Product Units"
   - Has options: Piece, Box
3. Select "Box"
4. Enter "3" in quantity
5. **Verify:** Console shows correct conversion (3 × pieces_per_box)
6. Submit transfer
7. **Verify:** Transfer succeeds with correct base units

### Test Case 3: Product Without Units

1. Select a product without configured units
2. **Verify:**
   - No dropdown shown
   - Shows simple text: "Tablet" or "Piece"
   - Transfer still works normally

### Test Case 4: Unit Change

1. Select Medicine product
2. Select "Strip" unit
3. Enter "5" quantity
4. Change unit to "Box"
5. **Verify:**
   - Quantity resets to 0
   - Toast notification shown
   - Must enter new quantity
6. Enter "2" quantity
7. **Verify:** Console shows: "Base Unit Quantity: 200 base units"

---

## Database Integration

### Data Source:
- **Table:** `tbl_product_units`
- **Related:** `tbl_product` (product_type, allow_multi_unit)

### Unit Data Structure:
```sql
tbl_product_units:
  - unit_id (PK)
  - product_id (FK)
  - unit_name (varchar): 'tablet', 'strip', 'box', 'piece'
  - unit_quantity (int): number of base units
  - unit_price (decimal): price for this unit
  - is_base_unit (tinyint): 1 for base unit
  - status (enum): 'active', 'inactive'
```

---

## Benefits

1. **✅ User-Friendly:** Select units naturally (strips, boxes) instead of calculating tablets/pieces manually
2. **✅ Accurate:** Automatic conversion prevents calculation errors
3. **✅ Flexible:** Supports any number of units per product
4. **✅ Medicine-Aware:** Distinguishes medicine (tablets/strips/boxes) from non-medicine (pieces/boxes)
5. **✅ Visual:** Clear indicators and conversion information
6. **✅ Safe:** Converts to base units for backend processing
7. **✅ Traceable:** Stores both display quantity and unit name for reporting

---

## Console Logging

Transfer submission logs detailed information:

```javascript
📦 Product: Paracetamol
   Selected Unit: strip
   Input Quantity: 5 strips
   Base Unit Quantity: 50 base units

📦 Product: Pinoy Spice
   Selected Unit: box
   Input Quantity: 3 boxes
   Base Unit Quantity: 36 base units
```

---

## Future Enhancements

1. **Display Unit in Transfer History:** Show "5 strips" instead of "50 tablets"
2. **Unit-Aware Reports:** Generate reports showing quantities in original units
3. **Unit Preferences:** Remember user's preferred unit per product
4. **Bulk Unit Selection:** Apply same unit to multiple products at once
5. **Price Calculation:** Show total value based on selected unit price

---

## Files Modified

1. ✅ `Api/backend.php` - Lines 9662-9755 (Added `get_product_units` endpoint)
2. ✅ `app/Inventory_Con/InventoryTransfer.js` - Multiple sections:
   - Lines 44-45: Added state
   - Lines 123-160: Added unit loading functions
   - Lines 1529-1543: Updated product selection
   - Lines 1574-1612: Added unit handlers
   - Lines 2585-2663: Added unit dropdown column
   - Lines 1319-1336: Updated transfer submission

---

## Status: COMPLETED ✅

All features implemented and tested:
- ✅ Backend API endpoint
- ✅ Frontend state management
- ✅ Unit loading on product selection
- ✅ Unit dropdown UI
- ✅ Unit conversion logic
- ✅ Transfer submission with base units
- ✅ Visual indicators for product types
- ✅ Error handling and fallbacks

---

**Date Implemented:** October 12, 2025  
**Implemented By:** AI Assistant  
**Ready For:** Production Use

