# Database Migrations

This directory contains all schema changes for the SalamehTools B2B system.

## Migration Order

Run migrations in this exact order:

1. **Phase 1: Settings & Imports** (Foundation)
   - `phase1_settings_and_imports_UP.sql`
   - Creates: `settings`, `import_runs` tables
   - Adds: Import tracking infrastructure

2. **Phase 2: Receivables** (AR Management)
   - `phase2_receivables_UP.sql`
   - Creates: `ar_followups` table
   - Adds: Customer AR tracking

3. **Phase 3: Warehouse** (Stock Management)
   - `phase3_warehouse_UP.sql`
   - Creates: `warehouse_movements` table
   - Adds: Stock movement tracking with triggers

4. **Phase 4: Invoice Ready** (Order Validation)
   - `phase4_invoice_ready_UP.sql`
   - Adds: `invoice_ready` column to orders

5. **Week 2: Performance Indexes** (Optimization)
   - `week2_performance_indexes_UP.sql`
   - Adds: 20 composite indexes for query optimization

## Rollback

To rollback, run the `_DOWN.sql` files in **reverse order**:

```bash
week2_performance_indexes_DOWN.sql
phase4_invoice_ready_DOWN.sql
phase3_warehouse_DOWN.sql
phase2_receivables_DOWN.sql
phase1_settings_and_imports_DOWN.sql
```

## Running Migrations

### Manual Execution
```bash
mysql -u root -p salamaehtools < migrations/phase1_settings_and_imports_UP.sql
mysql -u root -p salamaehtools < migrations/phase2_receivables_UP.sql
mysql -u root -p salamaehtools < migrations/phase3_warehouse_UP.sql
mysql -u root -p salamaehtools < migrations/phase4_invoice_ready_UP.sql
mysql -u root -p salamaehtools < migrations/week2_performance_indexes_UP.sql
```

### Future: Migration Runner
TODO: Create a PHP migration runner:
- Track executed migrations in `migrations` table
- Prevent duplicate execution
- Support up/down migrations
- Add to `composer migrate` command

## Legacy Migrations

The following migrations from `database/migrations/` have been consolidated:
- `20241029_add_invoice_ready.sql` → Covered by `phase4_invoice_ready_UP.sql`
- `20251028_add_customers_user_fk.sql` → Already exists in base schema

These files are archived and can be deleted.

## Schema State

After all migrations, the database has:
- **20 tables**: users, customers, products, orders, invoices, payments, etc.
- **40+ indexes**: Optimized for common query patterns
- **6 triggers**: Auto-updating AR, stock movements
- **1 view**: `v_invoice_balances` for AR calculations
