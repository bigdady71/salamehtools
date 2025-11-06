# Security Components Testing Guide

**Purpose**: Test all 5 security components before integration
**Status**: Option 2 - Review & Test Security Components
**Date**: 2025-01-04

---

## ✅ Receivables Page Status

**Tested**: Yes
**Status**: ✅ **FULLY WORKING AND SYNCED**

See [RECEIVABLES_STATUS_REPORT.md](RECEIVABLES_STATUS_REPORT.md) for complete analysis.

**Summary**:
- ✅ Database sync perfect (invoices ← orders ← customers)
- ✅ Payment aggregation working correctly
- ✅ Aging buckets calculated properly
- ✅ Follow-up system operational
- ✅ All foreign keys configured correctly

**Current Data**: 3 invoices, 4 payments, all fully paid (no outstanding receivables to display - **correct behavior**)

---

## 🧪 Security Components Testing

### Test Scripts Created

All test scripts are in [tests/security/](tests/security/) directory:

1. **[test_rate_limiter.php](tests/security/test_rate_limiter.php)** - RateLimiter tests
2. **[test_logger.php](tests/security/test_logger.php)** - Logger tests
3. **[test_session_manager.php](tests/security/test_session_manager.php)** - SessionManager tests
4. **[test_file_upload_validator.php](tests/security/test_file_upload_validator.php)** - FileUploadValidator tests

### How to Run Tests

```bash
# From project root directory
cd c:\xampp\htdocs\salamehtools

# Test RateLimiter
php tests/security/test_rate_limiter.php

# Test Logger
php tests/security/test_logger.php

# Test SessionManager
php tests/security/test_session_manager.php

# Test FileUploadValidator
php tests/security/test_file_upload_validator.php

# Run all tests
php tests/security/test_rate_limiter.php && php tests/security/test_logger.php && php tests/security/test_session_manager.php && php tests/security/test_file_upload_validator.php
```

---

## 📋 Test Coverage

### RateLimiter Tests (8 Tests)
- ✅ Test 1: First 5 attempts succeed
- ✅ Test 2: 6th attempt blocked (rate limiting works)
- ✅ Test 3: Wait time calculation
- ✅ Test 4: `tooManyAttempts()` method
- ✅ Test 5: Clear attempts and retry
- ✅ Test 6: Different keys are independent
- ✅ Test 7: Cleanup old entries
- ✅ Test 8: Verify `rate_limits` table created

**Expected Output**:
```
🧪 Testing RateLimiter
==================================================

Test 1: First 5 attempts should succeed
  Attempt 1: ✅ Allowed (Total: 1)
  Attempt 2: ✅ Allowed (Total: 2)
  Attempt 3: ✅ Allowed (Total: 3)
  Attempt 4: ✅ Allowed (Total: 4)
  Attempt 5: ✅ Allowed (Total: 5)

Test 2: 6th attempt should be blocked
  Attempt 6: ✅ PASS: Blocked (Total: 5)
...
✅ All RateLimiter tests completed!
```

---

### Logger Tests (7 Tests)
- ✅ Test 1: Log different levels (DEBUG, INFO, WARNING, ERROR, CRITICAL)
- ✅ Test 2: Exception logging
- ✅ Test 3: Verify log file created in `storage/logs/`
- ✅ Test 4: Read recent log entries (`tail()`)
- ✅ Test 5: Get statistics (`getStats()`)
- ✅ Test 6: Context enrichment (IP, user, URL, memory)
- ✅ Test 7: Show raw JSON log entry

**Expected Output**:
```
🧪 Testing Logger
==================================================

Test 1: Logging different levels
  ✅ Debug logged
  ✅ Info logged
  ✅ Warning logged
  ✅ Error logged
  ✅ Critical logged

Test 3: Verify log file
  ✅ Log file exists: storage/logs/app-2025-01-04.log
  ✅ File size: 2.45 KB
...
✅ All Logger tests completed!
```

**Log File Location**: `storage/logs/app-{date}.log`

---

### SessionManager Tests (12 Tests)
- ✅ Test 1: Start session
- ✅ Test 2: Verify secure session parameters
- ✅ Test 3: Login simulation (session ID regeneration)
- ✅ Test 4: Validate session
- ✅ Test 5: Get current user
- ✅ Test 6: Check session metadata
- ✅ Test 7: Session timing (age, idle, remaining)
- ✅ Test 8: Flash messages (one-time messages)
- ✅ Test 9: `isAuthenticated()` check
- ✅ Test 10: Regenerate session ID
- ✅ Test 11: Timeout simulation (manual test required)
- ✅ Test 12: Logout

**Expected Output**:
```
🧪 Testing SessionManager
==================================================

Test 1: Start session
  Session status: ✅ Active
  Session ID: 1a2b3c4d5e6f7g8h... ✅

Test 2: Verify secure session parameters
  HttpOnly: ✅ Yes
  Secure: ⚠️  No (HTTP - OK for dev)
  SameSite: Strict ✅

Test 3: Login simulation
  Session ID changed: ✅ Yes (fixation protection)
  User stored: ✅ Yes
  Authenticated flag: ✅ Yes
...
✅ All SessionManager tests completed!
```

---

### FileUploadValidator Tests (10 Tests)
- ✅ Test 1: Create test files
- ✅ Test 2: Validate valid XLSX file
- ✅ Test 3: Validate fake XLSX (wrong magic bytes)
- ✅ Test 4: Validate wrong extension (.php rejected)
- ✅ Test 5: Filename sanitization (dangerous patterns)
- ✅ Test 6: File size validation
- ✅ Test 7: Empty file rejection
- ✅ Test 8: ClamAV virus scanner (if available)
- ✅ Test 9: Supported file types list
- ✅ Test 10: Cleanup test files

**Expected Output**:
```
🧪 Testing FileUploadValidator
==================================================

Test 1: Creating test files
  ✅ Created valid XLSX test file
  ✅ Created fake XLSX test file
  ✅ Created file with malicious name

Test 2: Validate valid XLSX file
  Valid: ✅ Yes
  Safe name: products_2025.xlsx ✅
  Errors: ✅ None

Test 3: Validate fake XLSX (wrong signature)
  Valid: ✅ Correctly rejected
  Errors: ✅ File signature verification failed
...
✅ All FileUploadValidator tests completed!
```

---

## 🔍 Manual Testing Checklist

### After Running Automated Tests

#### 1. Check Database Tables
```sql
-- Verify rate_limits table created
SHOW TABLES LIKE 'rate_limits';

-- Check rate_limits structure
DESCRIBE rate_limits;

-- View sample entries
SELECT * FROM rate_limits LIMIT 5;
```

#### 2. Check Log Files
```bash
# View log directory
ls -la storage/logs/

# View today's log
cat storage/logs/app-$(date +%Y-%m-%d).log

# Count log entries
cat storage/logs/app-$(date +%Y-%m-%d).log | wc -l

# View last 10 entries
tail -10 storage/logs/app-$(date +%Y-%m-%d).log
```

#### 3. Verify Log Format
```bash
# Parse JSON log entry
cat storage/logs/app-$(date +%Y-%m-%d).log | head -1 | jq .
```

Expected JSON structure:
```json
{
  "timestamp": "2025-01-04 15:30:00",
  "level": "INFO",
  "message": "Test message",
  "context": {
    "custom_field": "value"
  },
  "metadata": {
    "request_id": "a1b2c3d4e5f6g7h8",
    "ip": "192.168.1.100",
    "method": "POST",
    "url": "/test/endpoint",
    "user_agent": "Mozilla/5.0...",
    "memory_usage": "12.50 MB"
  }
}
```

#### 4. Test Health Endpoint
```bash
# Access health check
curl http://localhost/health.php

# Or open in browser
# http://localhost/health.php
```

Expected response:
```json
{
  "status": "healthy",
  "timestamp": "2025-01-04 15:30:00",
  "checks": {
    "database": {"status": "up"},
    "disk_space": {"status": "ok", "used_percent": 45.23},
    "php_version": {"status": "ok", "version": "8.2.0"}
  }
}
```

---

## ⚠️ Known Issues & Solutions

### Issue 1: "Call to undefined function finfo_open()"
**Cause**: fileinfo extension not loaded
**Solution**:
```ini
; In php.ini, uncomment:
extension=fileinfo
```

### Issue 2: "Permission denied" on log directory
**Cause**: storage/logs/ not writable
**Solution**:
```bash
mkdir -p storage/logs
chmod 755 storage/logs
```

### Issue 3: SessionManager shows "Secure: No"
**Cause**: Running on HTTP (not HTTPS)
**Impact**: OK for development, **required for production**
**Solution**: Deploy with HTTPS in production

### Issue 4: ClamAV not available
**Cause**: ClamAV not installed
**Impact**: Optional - virus scanning disabled
**Solution**: Install ClamAV (optional):
```bash
# Windows (Chocolatey)
choco install clamav

# Linux
sudo apt-get install clamav
```

---

## 📊 Test Results Summary

### Expected Results
After running all tests, you should see:

✅ **RateLimiter**: 8/8 tests passed
- rate_limits table created
- Rate limiting works (blocks after 5 attempts)
- Wait time calculated correctly
- Cleanup function works

✅ **Logger**: 7/7 tests passed
- Log file created in storage/logs/
- All log levels working
- JSON format correct
- Context enrichment working
- Statistics accurate

✅ **SessionManager**: 12/12 tests passed
- Session starts with secure parameters
- Session ID regenerates on login
- Metadata tracked (IP, user agent, timestamps)
- Flash messages work
- Validation enforces timeouts

✅ **FileUploadValidator**: 10/10 tests passed
- Valid files accepted
- Fake files rejected (magic byte check)
- Dangerous filenames sanitized
- File size limits enforced
- Empty files rejected

✅ **Health Endpoint**: Accessible
- Returns HTTP 200 (healthy)
- All checks pass
- JSON format correct

---

## 🚀 Next Steps After Testing

### If All Tests Pass (✅)
**Proceed to Option 3**: Performance optimization (Week 2)
1. Install Redis/Memcached
2. Implement caching layer
3. Add proper pagination
4. Optimize database queries

### If Tests Fail (❌)
**Debug and fix issues**:
1. Check error messages
2. Verify PHP extensions loaded
3. Check file permissions
4. Review database configuration

### Integration Preparation
After Option 3 (Performance), return to integrate security:
1. Update login.php with RateLimiter
2. Replace auth.php with SessionManager
3. Add Logger to critical operations
4. Update settings.php with FileUploadValidator

---

## 📞 Support

### Test Failures?
**Check**:
- PHP version 8.0+ (`php -v`)
- PDO extension (`php -m | grep pdo`)
- Database connection (test with login.php)
- File permissions (storage/ directory writable)

### Need Help?
- Review inline code comments in each class
- Check PRODUCTION_READINESS_ANALYSIS.md
- See WEEK1_SECURITY_IMPLEMENTATION.md for detailed docs

---

## 📖 Component Documentation

### Quick Reference

**RateLimiter**:
```php
$limiter = new RateLimiter($pdo, $maxAttempts, $decayMinutes);
$allowed = $limiter->attempt($key);
$waitTime = $limiter->availableIn($key);
$limiter->clear($key);
```

**Logger**:
```php
$logger = new Logger($logPath, $minLevel);
$logger->info($message, $context);
$logger->error($message, $context);
$logger->exception($exception, $message, $context);
$recent = $logger->tail($lines);
```

**SessionManager**:
```php
SessionManager::start();
SessionManager::login($user);
$valid = SessionManager::validate();
$user = SessionManager::getUser();
SessionManager::logout();
```

**FileUploadValidator**:
```php
$validator = new FileUploadValidator($maxSize, $checkMagicBytes, $logger);
$result = $validator->validate($_FILES['upload'], $allowedExtensions);
if ($result['valid']) {
    $safeName = $result['safe_name'];
}
```

---

**Generated**: 2025-01-04
**Status**: Ready for testing
**Action**: Run test scripts and verify all components work correctly
**Next**: Option 3 - Performance optimization (Week 2)
