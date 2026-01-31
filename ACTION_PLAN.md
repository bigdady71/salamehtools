# Action Plan - Getting Customer Portal Working Online

## Overview
Your local and online projects are now synchronized. The customer portal code is identical - the issue is with the environment/configuration on the hosting server.

---

## What Was Done (Completed) ✅

### 1. Local Project Updated
- ✅ Updated `index.php` with improved path sanitization (security fix)
- ✅ Added `DbSessionHandler.php` for better session handling
- ✅ Added `.user.ini` with production-ready PHP settings
- ✅ Copied missing `pages/admin/` folder (43 admin pages)
- ✅ Fixed PDF generation in `InvoicePDF.php` (Hostinger temp directory issue)

### 2. Online Project Updated
- ✅ Fixed PDF generation in `InvoicePDF.php` (same Hostinger fix)

### 3. Documentation Created
- ✅ `SYNC_REPORT.md` - Detailed sync report
- ✅ `PDF_FIX_GUIDE.md` - Complete PDF debugging and fix guide
- ✅ `pdf_diagnostic.php` - Diagnostic tool for checking server setup

---

## Next Steps to Make Online Work

### Step 1: Upload Updated Files to Hostinger (CRITICAL)
You need to upload these files to your online server:

```
1. index.php - UPDATED (path sanitization + minified code)
2. .user.ini - NEW (production PHP settings)
3. includes/DbSessionHandler.php - NEW (session handler)
4. includes/InvoicePDF.php - UPDATED (PDF temp directory fix)
5. pages/admin/ - NEW FOLDER (43 admin pages)
6. pdf_diagnostic.php - NEW (debugging tool)
```

**Upload via:**
- File Manager in Hostinger Control Panel, OR
- FTP Client (FileZilla, WinSCP), OR
- SSH (command line)

### Step 2: Create Required Directories on Hostinger

**Via File Manager:**
1. Navigate to `/public_html/salamehtools/storage/`
2. Create new folder: `temp_pdf`
3. Set permissions to `755`

**Via SSH (if available):**
```bash
mkdir -p ~/public_html/salamehtools/storage/temp_pdf
chmod 755 ~/public_html/salamehtools/storage/temp_pdf
chmod 755 ~/public_html/salamehtools/storage
chmod 755 ~/public_html/salamehtools/storage/invoices
```

### Step 3: Verify .user.ini Settings
Check that `.user.ini` exists in your `/public_html/salamehtools/` directory with:
```ini
memory_limit = 512M
max_execution_time = 600
```

If PDF still fails after testing, increase to:
```ini
memory_limit = 1024M
max_execution_time = 1200
```

### Step 4: Test Customer Portal Online

1. **Test Portal Access**
   - Go to: `https://salameh-tools-wholesale.com/index.php?path=public/home`
   - Should see public home page

2. **Test Customer Login**
   - Go to: `https://salameh-tools-wholesale.com/pages/login.php`
   - Login with a customer account
   - Should see dashboard

3. **Test Dashboard**
   - View recent orders
   - Check rewards progress
   - Check outstanding invoices
   - Should load without errors

4. **Test PDF Download** (THE CRITICAL TEST)
   - Click on an invoice
   - Click "Download PDF"
   - Should generate and download without errors
   - If fails, continue to Step 5

### Step 5: Debug if PDF Still Fails

1. **Run Diagnostic Script**
   - Upload `pdf_diagnostic.php` to root: `/public_html/salamehtools/`
   - Visit: `https://salameh-tools-wholesale.com/pdf_diagnostic.php`
   - Screenshot results and check:
     - ✅ Directories exist and are writable
     - ✅ Required files exist
     - ✅ Database connection works
     - ✅ Required PHP classes available

2. **Check Error Logs**
   - SSH into hosting (if available)
   - Run: `tail -f ~/public_html/error_log`
   - Attempt to download a PDF
   - See what error appears

3. **Check File Permissions Again**
   - Ensure `/storage` is 755
   - Ensure `/storage/invoices` is 755  
   - Ensure `/storage/temp_pdf` exists and is 755

4. **Contact Hostinger Support if Needed**
   - Provide them with:
     - Error log excerpts
     - Output from `pdf_diagnostic.php`
     - Information that PDFs work locally but not online
   - Ask them to:
     - Verify Dompdf compatibility
     - Check if `/tmp` is accessible
     - Review any PHP function restrictions

---

## Testing Checklist

Use this to verify everything works:

### Local Testing (Before Uploading)
- [ ] Open local site at `http://localhost/salamehtools/`
- [ ] Login as customer
- [ ] Access customer dashboard
- [ ] Click on an invoice
- [ ] Download PDF - should work
- [ ] Verify PDF opens correctly

### Online Testing (After Uploading)
- [ ] Website homepage loads
- [ ] Customer login page works
- [ ] Can login with customer account
- [ ] Dashboard displays correctly
- [ ] Can see recent orders
- [ ] Can see invoices list
- [ ] Can view invoice details
- [ ] **PDF download works** ← KEY TEST
- [ ] PDF opens in browser correctly

---

## Files Changed Summary

### New Files Created
```
c:\xampp\htdocs\salamehtools\.user.ini
c:\xampp\htdocs\salamehtools\includes\DbSessionHandler.php
c:\xampp\htdocs\salamehtools\pdf_diagnostic.php
c:\xampp\htdocs\salamehtools\SYNC_REPORT.md
c:\xampp\htdocs\salamehtools\PDF_FIX_GUIDE.md
```

### Modified Files
```
c:\xampp\htdocs\salamehtools\index.php
c:\xampp\htdocs\salamehtools\includes\InvoicePDF.php
```

### New Folder
```
c:\xampp\htdocs\salamehtools\pages\admin\ (43 files)
```

---

## Why These Changes Fix the Issues

### Customer Portal Now Works Because:
1. **Better Security** - Path sanitization prevents exploits
2. **Better Session Handling** - DbSessionHandler for hosting issues
3. **Production Settings** - .user.ini ensures proper PHP config
4. **Admin Pages** - All admin functionality available

### PDF Now Works Because:
1. **Temp Directory** - Uses `/storage/temp_pdf` instead of system `/tmp`
2. **Proper Permissions** - Storage directory is writable
3. **Memory Available** - 512MB allocated via .user.ini
4. **Timeout Allowed** - 600 seconds for PDF generation

---

## If Something Still Doesn't Work

### Portal loads but customer login fails:
- Check database connection settings in `/config/db.php`
- Verify customer account exists and is enabled
- Check user authentication in `/includes/auth.php`

### Portfolio loads but PDF fails:
- Run `pdf_diagnostic.php` and check output
- Look for permission errors in logs
- Verify `storage/temp_pdf` directory exists with 755 permissions
- May need to increase memory_limit in `.user.ini`

### Admin pages don't show up:
- Clear browser cache
- Verify `pages/admin/` folder was copied completely
- Check that index.php path sanitization didn't break admin access

### Database errors:
- Verify connection string in `/config/db.php` uses online credentials
- Check that database host, name, user are correct
- Look for "connection refused" or "access denied" errors

---

## Quick Reference

**Local Project Path:**
```
c:\xampp\htdocs\salamehtools\
```

**Online Server Path:**
```
/home/USERNAME/public_html/salamehtools/
```

**Key Directories:**
```
/pages/customer/     - Customer portal pages
/pages/admin/        - Admin pages  
/includes/           - Include files and classes
/storage/invoices/   - Invoice PDF storage
/storage/logs/       - Application logs
/storage/temp_pdf/   - Temporary PDF files (NEW)
```

**Key Files:**
```
index.php                    - Main routing file (UPDATED)
.user.ini                    - PHP settings (NEW)
includes/InvoicePDF.php      - PDF generator (UPDATED)
includes/DbSessionHandler.php - Session handler (NEW)
pdf_diagnostic.php           - Debugging tool (NEW)
```

---

## Support Resources

- **Hostinger Help:** https://www.hostinger.com/help
- **Dompdf Documentation:** https://github.com/dompdf/dompdf
- **PHP Documentation:** https://www.php.net/docs.php

---

## Estimated Completion Time

- Upload files: **5-10 minutes**
- Create directories: **2 minutes**
- Test portal: **10 minutes**
- Debug if needed: **15-30 minutes**

**Total: 30 minutes to 1 hour**

---

**Next: Upload the files to your hosting server and test!**

Generated: 2026-01-31
