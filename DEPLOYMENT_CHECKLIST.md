# 📋 DEPLOYMENT CHECKLIST

Use this checklist to ensure everything is ready before uploading to production.

---

## Pre-Upload Verification

### Code Quality ✅
- [x] Local `index.php` has path sanitization
- [x] `InvoicePDF.php` uses custom temp directory
- [x] `.user.ini` has production settings
- [x] `DbSessionHandler.php` exists
- [x] Admin pages folder copied (43 files)

### File Integrity
- [x] No syntax errors in PHP files
- [x] All includes reference correct paths
- [x] No hardcoded localhost URLs
- [x] PDF diagnostic script ready

### Documentation ✅
- [x] EXEC_SUMMARY.md created
- [x] ACTION_PLAN.md created
- [x] SYNC_REPORT.md created
- [x] PDF_FIX_GUIDE.md created

---

## Local Testing Checklist

Run these tests before uploading:

### Website Basics
- [ ] Homepage loads at `http://localhost/salamehtools/`
- [ ] Page navigation works
- [ ] Links don't have broken paths
- [ ] CSS/JS loads correctly

### Customer Portal
- [ ] Login page loads at `/pages/login.php`
- [ ] Can login with test customer account
- [ ] Dashboard displays correctly
- [ ] Sidebar navigation works
- [ ] Recent orders section loads
- [ ] Invoice list displays

### PDF Download (CRITICAL)
- [ ] Click on invoice to view details
- [ ] "Download PDF" button appears
- [ ] Click download - file downloads successfully
- [ ] Open downloaded PDF - content correct
- [ ] PDF has proper formatting
- [ ] Arabic text renders correctly in PDF

### Admin Pages (if testing admin)
- [ ] Can access `/pages/admin/dashboard.php`
- [ ] Admin navigation works
- [ ] Admin pages load without errors

### Database
- [ ] Customer data loads correctly
- [ ] Order data displays properly
- [ ] Invoice amounts calculate correctly
- [ ] Payment history shows

---

## Upload Preparation

### File Organization
- [ ] Identify all files that changed (see SYNC_REPORT.md)
- [ ] Create folder for upload: `/to_upload/`
- [ ] Copy changed files maintaining structure
- [ ] Double-check: No system files included
- [ ] Ready to upload

### FTP/File Manager Prep
- [ ] Hostinger credentials ready
- [ ] Know hosting username/password
- [ ] Know how to access File Manager or FTP
- [ ] Backup of current online version (optional but recommended)

---

## Upload Steps

### Step 1: Create Directories
```
[ ] Access File Manager in Hostinger
[ ] Navigate to /public_html/salamehtools/
[ ] Create folder: storage/temp_pdf/
[ ] Set permissions: 755
```

### Step 2: Upload Core Files
```
[ ] Upload index.php (UPDATED)
[ ] Upload .user.ini (NEW)
[ ] Upload includes/DbSessionHandler.php (NEW)
[ ] Upload includes/InvoicePDF.php (UPDATED)
```

### Step 3: Upload Admin Pages
```
[ ] Upload entire pages/admin/ folder (43 files)
```

### Step 4: Upload Diagnostic Tools
```
[ ] Upload pdf_diagnostic.php
```

---

## Post-Upload Testing

### Homepage & Navigation
- [ ] Visit `https://salameh-tools-wholesale.com/`
- [ ] Page loads without errors
- [ ] Navigation works
- [ ] No 404 errors in console

### Customer Portal Access
- [ ] Go to `/pages/login.php`
- [ ] Login page displays
- [ ] Can login with test customer

### Dashboard Check
- [ ] Dashboard loads
- [ ] Shows recent orders
- [ ] Shows outstanding invoices
- [ ] Rewards progress displays
- [ ] Statistics load correctly

### The Critical PDF Test
- [ ] Click on an invoice in dashboard
- [ ] Invoice details page loads
- [ ] "Download PDF" button visible
- [ ] Click "Download PDF"
- [ ] **PDF downloads without error** ← KEY TEST
- [ ] Open PDF file
- [ ] Content looks correct
- [ ] All text readable

### If PDF Fails
- [ ] Visit `/pdf_diagnostic.php`
- [ ] Check results for problems
- [ ] Take screenshot of diagnostic output
- [ ] Check error logs
- [ ] Follow troubleshooting in `PDF_FIX_GUIDE.md`

---

## Verification Steps

### Code Validation
- [ ] No PHP syntax errors in error logs
- [ ] No JavaScript console errors (F12)
- [ ] No 404 errors in network tab

### Functionality Validation
- [ ] All customer portal pages accessible
- [ ] Database queries work
- [ ] File uploads work (if applicable)
- [ ] PDF generation works

### Security Validation
- [ ] Login required for customer pages
- [ ] Cannot access other customers' data
- [ ] Path traversal attempts blocked
- [ ] SQL injection protection works

---

## Success Criteria

✅ **Consider deployment successful when:**
1. Customer can login to portal
2. Customer sees their orders and invoices
3. Customer can download PDF invoice
4. PDF opens in browser correctly
5. Admin can access admin panel
6. No errors in logs
7. All pages load quickly

❌ **DO NOT consider complete if:**
1. PDF download gives errors
2. Customer data shows incorrectly
3. Login doesn't work
4. Pages return 404 errors
5. Console has JavaScript errors

---

## Troubleshooting Quick Reference

| Problem | Solution |
|---------|----------|
| PDF won't download | Check `/pdf_diagnostic.php` output |
| Login fails | Verify database connection in `/config/db.php` |
| 404 errors | Check that all files uploaded to correct paths |
| Slow loading | Check PHP memory/timeout settings in `.user.ini` |
| Database errors | Verify credentials in `/config/db.php` |
| Admin pages missing | Check that `pages/admin/` folder fully copied |

---

## Emergency Rollback

If something breaks after upload:

1. **Quick Fix:**
   - Revert changed files one at a time
   - Test after each revert
   - Usually the last uploaded file causes issue

2. **Nuclear Option:**
   - If time critical, revert to pre-upload backup
   - Contact Hostinger support
   - Try again with careful testing

3. **Keep Notes:**
   - What worked/didn't work
   - Error messages
   - Which files were problematic
   - Use for next attempt

---

## File Size Reference

```
index.php              ~1 KB
InvoicePDF.php        ~46 KB
DbSessionHandler.php   ~3 KB
.user.ini             ~0.5 KB
pages/admin/ folder   ~500 KB
pdf_diagnostic.php    ~5 KB
---
Total upload:         ~555 KB
```

---

## Timeline Estimate

| Step | Time | Status |
|------|------|--------|
| Local testing | 15 min | Ready |
| Upload files | 10 min | Ready |
| Create directories | 5 min | Ready |
| Online testing | 15 min | Ready |
| Debug if needed | 20 min | If required |
| **TOTAL** | **65 min** | **Ready** |

---

## Final Checklist

Before clicking "upload":

- [ ] All local tests passed
- [ ] Files organized correctly
- [ ] Hostinger login credentials ready
- [ ] Have Hostinger support number handy
- [ ] Time to test without rushing
- [ ] Backup of current version (optional)
- [ ] All documentation printed/saved
- [ ] Ready to roll!

---

## Support Contact

If issues arise:

1. **Check Documentation First:**
   - EXEC_SUMMARY.md
   - ACTION_PLAN.md
   - PDF_FIX_GUIDE.md
   - SYNC_REPORT.md

2. **Run Diagnostic:**
   - Visit `/pdf_diagnostic.php` on server
   - Check error logs

3. **Contact Hostinger:**
   - Live chat: Available 24/7
   - Provide `/pdf_diagnostic.php` output
   - Mention Dompdf PDF generation issues

---

**Ready to deploy? Follow ACTION_PLAN.md next!**

Last Updated: 2026-01-31
