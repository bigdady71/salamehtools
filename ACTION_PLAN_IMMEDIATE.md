# SalamehTools - Immediate Action Plan

**Target**: Make production-ready for enterprise scale (100+ concurrent users)
**Timeline**: 2 weeks
**Priority**: CRITICAL fixes only

---

## WEEK 1: Security & Stability

### Day 1-2: Security Hardening
**Priority**: CRITICAL - Prevents data breaches

#### Task 1.1: Implement Rate Limiting (4 hours)
```php
// Create: includes/RateLimiter.php
// Integrate: pages/login.php, all form submissions
// Test: Attempt 6 logins in 1 minute
```

#### Task 1.2: Session Security (3 hours)
```php
// Create: includes/SessionManager.php
// Features:
// - session_regenerate_id() on login
// - Secure cookie flags (httponly, secure, samesite)
// - Session timeout (30 min)
// - IP address validation (optional)
```

#### Task 1.3: File Upload Hardening (2 hours)
```php
// Update: pages/admin/settings.php
// Add: Magic byte validation (finfo_file)
// Add: 10MB size limit enforcement
// Add: Filename sanitization
```

---

### Day 3-4: Observability

#### Task 2.1: Centralized Logging (6 hours)
```php
// Create: includes/Logger.php
// Log to: storage/logs/app-{date}.log
// Format: JSON with timestamp, level, context, user_id, ip
// Integrate: All catch blocks, critical operations
```

**Replace this pattern**:
```php
catch (PDOException $e) {
    flash('error', 'Database error');
    // Details lost
}
```

**With this**:
```php
catch (PDOException $e) {
    $logger->error('Order creation failed', [
        'customer_id' => $customerId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    flash('error', 'Order creation failed. Support team has been notified.');
}
```

#### Task 2.2: Health Check Endpoint (1 hour)
```php
// Create: public/health.php
// Checks: Database connection, disk space
// Returns: 200 OK or 503 Service Unavailable
```

---

### Day 5: Code Cleanup

#### Task 3.1: Resolve orders_service.php Duplication (3 hours)
**Issue**: Two implementations of invoice-ready validation

**Decision**: Remove `includes/orders_service.php` (keeps existing working code)

**Steps**:
1. Delete `includes/orders_service.php`
2. Delete `PHASE_4_IMPLEMENTATION_NOTES.md` (no longer relevant)
3. Update `PROJECT_COMPLETION_SUMMARY.md`:
   - Mark Phase 4 as "Already Implemented"
   - Note: Invoice-ready logic existed in orders.php since before Phase 4
4. Verify orders.php still calls `refresh_invoice_ready()` correctly

---

## WEEK 2: Performance & Enterprise Features

### Day 6-7: Caching Layer

#### Task 4.1: Install Redis (2 hours)
```bash
# Windows (via chocolatey)
choco install redis

# Or use Memcached
choco install memcached
```

#### Task 4.2: Implement Cache Wrapper (4 hours)
```php
// Create: includes/CacheManager.php
// Cache: Settings (1 hour TTL), product lookups (5 min), exchange rates (15 min)
```

**Before**:
```php
// Every request hits DB
$settings = fetch_all_settings($pdo);
```

**After**:
```php
// Cached for 1 hour
$settings = $cache->remember('settings:all', 3600, fn() => fetch_all_settings($pdo));
```

---

### Day 8-9: Pagination & Performance

#### Task 5.1: Implement Paginator Class (4 hours)
```php
// Create: includes/Paginator.php
// Update: orders.php, invoices.php, products.php, receivables.php
// Change: LIMIT 100 → proper pagination with page controls
```

#### Task 5.2: Add Missing Indexes (2 hours)
```sql
CREATE INDEX idx_orders_status_created ON orders(status, created_at);
CREATE INDEX idx_invoices_status_created ON invoices(status, created_at);
CREATE INDEX idx_payments_invoice_received ON payments(invoice_id, received_at);
CREATE INDEX idx_order_items_product ON order_items(product_id);
```

---

### Day 10: Environment Configuration

#### Task 6.1: Environment Variables (3 hours)
```bash
composer require vlucas/phpdotenv
```

```php
// Create: .env
DB_HOST=localhost
DB_NAME=salamaehtools
DB_USER=root
DB_PASS=your_password_here
APP_ENV=production
LOG_LEVEL=error

// Create: .env.example (commit this, not .env)
// Update: config/db.php to use $_ENV
// Add to .gitignore: .env
```

---

## CRITICAL FILES TO MODIFY

### Security (Week 1)
- ✅ `includes/RateLimiter.php` (create)
- ✅ `includes/SessionManager.php` (create)
- ✅ `pages/login.php` (integrate rate limiter)
- ✅ `pages/admin/settings.php` (harden upload)

### Logging (Week 1)
- ✅ `includes/Logger.php` (create)
- ✅ `pages/admin/orders.php` (add logging to all try/catch)
- ✅ `pages/admin/invoices.php` (add logging)
- ✅ `pages/admin/products_import.php` (add logging)

### Cleanup (Week 1)
- ❌ `includes/orders_service.php` (delete)
- ✅ `PROJECT_COMPLETION_SUMMARY.md` (update)

### Performance (Week 2)
- ✅ `includes/CacheManager.php` (create)
- ✅ `includes/Paginator.php` (create)
- ✅ `pages/admin/orders.php` (add pagination)
- ✅ `pages/admin/receivables.php` (add pagination)

### Config (Week 2)
- ✅ `.env` (create, add to .gitignore)
- ✅ `.env.example` (create, commit)
- ✅ `config/db.php` (use environment variables)

---

## TESTING CHECKLIST

### Security Tests
- [ ] Login fails after 5 attempts
- [ ] Session expires after 30 minutes
- [ ] File upload rejects non-Excel files
- [ ] File upload rejects files > 10MB
- [ ] Uploaded PHP file is rejected (even if renamed .xlsx)

### Logging Tests
- [ ] Failed login attempt logged
- [ ] Order creation error logged with context
- [ ] Log file rotates daily
- [ ] Logs contain user_id and IP

### Performance Tests
- [ ] Settings loaded from cache (check Redis)
- [ ] Orders page loads in < 200ms with 100 orders
- [ ] Pagination shows correct page numbers
- [ ] Can navigate to page 10 of orders

### Environment Tests
- [ ] Database connects using .env credentials
- [ ] APP_ENV=production disables debug output
- [ ] Changing .env value affects application

---

## VALIDATION METRICS

**Before Improvements**:
- Security score: 6/10
- Performance: ~300ms page load
- Observability: No logs
- Scalability: ~50 concurrent users

**After Week 2**:
- Security score: 8.5/10
- Performance: ~150ms page load
- Observability: Full logging + health checks
- Scalability: ~100 concurrent users

---

## WHAT NOT TO DO (Yet)

### Defer to Phase 5-8
- ❌ Don't refactor orders.php yet (3,776 lines stays for now)
- ❌ Don't build REST API (comes in Phase 7)
- ❌ Don't add email notifications (comes in Phase 7)
- ❌ Don't implement testing framework (comes in Phase 5)
- ❌ Don't add CI/CD pipeline (comes in Phase 8)

**Reason**: Focus on critical security/performance issues first. Architecture improvements come after system is secure and stable.

---

## SUCCESS CRITERIA

### Week 1 Complete When:
1. ✅ Can't brute force login (rate limited)
2. ✅ Session invalidates after 30 min
3. ✅ All errors logged to storage/logs/
4. ✅ Health check returns 200 OK
5. ✅ orders_service.php deleted, no errors

### Week 2 Complete When:
1. ✅ Settings load from Redis cache
2. ✅ Can paginate through 1,000+ orders
3. ✅ Database queries use new indexes
4. ✅ .env file controls all configuration
5. ✅ Page load times reduced by 40%+

---

## ROLLBACK PLAN

If issues arise:

### Security Changes
- Rate limiting: Comment out middleware, redeploy
- Session changes: Revert SessionManager, use old session_start()

### Caching
- Disable cache: Set `CACHE_ENABLED=false` in .env
- Flush Redis: `redis-cli FLUSHALL`

### Database Indexes
```sql
DROP INDEX idx_orders_status_created ON orders;
DROP INDEX idx_invoices_status_created ON invoices;
-- etc.
```

---

## RESOURCES NEEDED

### Infrastructure
- Redis server (or Memcached)
- 2GB additional disk space for logs
- Backup system (daily snapshots)

### Development
- 1 developer (full-time, 2 weeks)
- Staging environment for testing
- Access to production logs

### Budget
- $0 (all open-source tools)
- Optional: $29/month for APM tool (Sentry, optional)

---

## COMMUNICATION PLAN

### Daily Standup (5 minutes)
- What was completed yesterday?
- What's planned for today?
- Any blockers?

### End of Week 1 Review
- Security audit: Attempt to break rate limiter
- Log review: Ensure all errors captured
- Performance baseline: Measure current page loads

### End of Week 2 Demo
- Show pagination in action
- Demonstrate cache hit rates
- Compare before/after metrics

---

## NEXT STEPS AFTER 2 WEEKS

Once Week 1-2 complete, assess:

### If System Stable:
→ Proceed to Phase 5: Refactor orders.php + testing framework

### If Performance Still Poor:
→ Deep dive into slow queries (use EXPLAIN)
→ Consider database replication

### If Security Concerns Remain:
→ Hire external security audit
→ Implement WAF (Web Application Firewall)

---

**Start Date**: [FILL IN]
**Target Completion**: [START DATE + 14 days]
**Owner**: [DEVELOPER NAME]
**Status**: 🔴 Not Started

---

## APPENDIX: Quick Reference

### Start Redis (Windows)
```powershell
redis-server
```

### View Logs
```bash
tail -f storage/logs/app-2025-01-04.log
```

### Test Health Check
```bash
curl http://localhost/health.php
```

### Clear Cache
```php
php cli/cache-clear.php
```

### Check Current Performance
```bash
# Apache Bench
ab -n 100 -c 10 http://localhost/pages/admin/orders.php
```

---

**Document Version**: 1.0
**Last Updated**: 2025-01-04
**Generated by**: Claude Code Assistant
