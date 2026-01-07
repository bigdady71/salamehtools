# Sales Rep Stock Adjustment - Two-Factor Authentication Workflow

## Overview
Sales representatives can no longer adjust their own van stock directly. All stock adjustments must be initiated by an administrator or warehouse manager and require confirmation from both parties via OTP (One-Time Password) codes.

## Security Features
- ✅ Two-factor authentication (2FA) required
- ✅ OTP codes expire after 15 minutes
- ✅ Both parties must confirm before adjustment is applied
- ✅ Complete audit trail of all adjustments
- ✅ Prevents stock manipulation by either party alone

## How It Works

### Step 1: Admin/Warehouse Initiates Adjustment

1. Admin or warehouse manager goes to **Rep Stock Auth** page
2. Fills out the adjustment form:
   - Select sales representative
   - Select product
   - Enter quantity change (positive to add, negative to remove)
   - Select reason (load, return, adjustment, transfer_in, transfer_out)
   - Add optional notes
3. Clicks **Create Adjustment Request**

### Step 2: System Generates OTP Codes

The system generates two OTP codes:
- **Initiator OTP**: 6-digit code for the admin/warehouse manager
- **Sales Rep OTP**: 6-digit code for the sales representative

**IMPORTANT**: Both codes are displayed on screen after creation. The initiator must:
- Save their own OTP code
- Share the sales rep's OTP code with the sales representative (via phone, message, etc.)

### Step 3: Both Parties Confirm

#### Option A: Initiator Confirms First
1. Initiator enters their OTP code in the pending adjustments section
2. System marks initiator as confirmed
3. Sales rep receives the sales rep OTP code (shared by initiator)
4. Sales rep goes to **🔐 Stock Authorizations** page
5. Sales rep enters their OTP code
6. **Adjustment is immediately processed**

#### Option B: Sales Rep Confirms First
1. Sales rep receives the sales rep OTP code (shared by initiator)
2. Sales rep goes to **🔐 Stock Authorizations** page
3. Sales rep enters their OTP code
4. System marks sales rep as confirmed
5. Initiator enters their OTP code in the pending adjustments section
6. **Adjustment is immediately processed**

### Step 4: Adjustment Applied

Once both parties have confirmed:
- Stock quantity is updated in the `s_stock` table
- Movement is logged in the `s_stock_movements` table
- Adjustment record is marked as completed
- Both parties receive confirmation

## Pages

### For Sales Representatives
- **My Van Stock** (`/pages/sales/van_stock.php`)
  - View current van stock
  - REMOVED: Adjust Stock button (no longer available)

- **🔐 Stock Authorizations** (`/pages/sales/stock_adjustment_auth.php`)
  - View pending adjustments awaiting confirmation
  - Enter OTP codes to authorize adjustments
  - See who initiated each adjustment and details

### For Admin/Warehouse Manager
- **Rep Stock Auth** (`/pages/admin/sales_rep_stock_adjustment.php`)
  - Create new adjustment requests
  - View pending confirmations
  - Enter OTP codes to confirm adjustments

## Database Tables

### `stock_adjustment_otps`
Stores all adjustment requests and their OTP codes:
- `adjustment_id`: Unique identifier for the adjustment
- `initiator_id`: User ID of admin/warehouse manager
- `initiator_type`: 'admin' or 'warehouse_manager'
- `sales_rep_id`: User ID of the sales representative
- `product_id`: Product being adjusted
- `delta_qty`: Quantity change (positive or negative)
- `reason`: Reason for adjustment
- `note`: Optional notes
- `initiator_otp`: 6-digit OTP for initiator
- `sales_rep_otp`: 6-digit OTP for sales rep
- `initiator_confirmed`: 0 or 1
- `sales_rep_confirmed`: 0 or 1
- `expires_at`: Expiration timestamp (15 minutes from creation)
- `completed_at`: When both parties confirmed (NULL if pending)

## Security Considerations

### Why Two-Factor Authentication?
- Prevents sales reps from manipulating their own stock
- Prevents admin/warehouse from manipulating rep stock without rep's knowledge
- Creates accountability: both parties must agree
- Provides complete audit trail

### OTP Code Security
- OTP codes are 6 digits (1 million possibilities)
- OTP codes expire after 15 minutes
- OTP codes can only be used once
- OTP codes are stored in database (not sent via email/SMS for now)
- Initiator must communicate sales rep OTP code through secure channel

### Audit Trail
Every adjustment request creates records in:
- `stock_adjustment_otps`: The request and confirmation status
- `s_stock_movements`: The actual stock movement (once confirmed)
- Both tables include timestamps and user IDs

## Example Workflow

### Scenario: Loading Stock from Warehouse

1. **Warehouse Manager** creates adjustment:
   - Sales Rep: John Doe
   - Product: Product A (SKU: ABC123)
   - Quantity: +50 units
   - Reason: Load from Warehouse
   - Note: "Weekly stock replenishment"

2. **System generates OTPs**:
   - Warehouse Manager OTP: `456789`
   - Sales Rep OTP: `123456`

3. **Warehouse Manager**:
   - Saves their code: `456789`
   - Calls John and tells him: "I'm loading 50 units of Product A to your van. Your confirmation code is `123456`"

4. **John (Sales Rep)**:
   - Goes to Stock Authorizations page
   - Sees the pending adjustment
   - Enters OTP: `123456`
   - Clicks Confirm

5. **Warehouse Manager**:
   - Enters their OTP: `456789`
   - Clicks Confirm

6. **System**:
   - Updates John's van stock: +50 units of Product A
   - Logs movement in database
   - Marks adjustment as completed
   - Both parties see success message

## Troubleshooting

### "Invalid OTP" Error
- Check that you're entering the correct 6-digit code
- Verify the code hasn't expired (15 minute limit)
- Ensure the adjustment hasn't already been completed

### "Adjustment Not Found" Error
- The adjustment may have expired (15 minutes)
- The adjustment may have already been completed
- Refresh the page to see current status

### OTP Code Expired
- Adjustment requests expire after 15 minutes
- Create a new adjustment request if expired
- Both parties must confirm within the 15-minute window

## Future Enhancements

Possible improvements for future versions:
- SMS/Email OTP delivery instead of on-screen display
- Push notifications when adjustment is created
- In-app messaging between initiator and sales rep
- Bulk adjustment requests
- Scheduled adjustments
- Mobile app integration
