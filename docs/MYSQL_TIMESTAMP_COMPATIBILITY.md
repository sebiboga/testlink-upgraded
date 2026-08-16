# MySQL 5.7.24+ TIMESTAMP Compatibility Fix

## Issue #137 Resolution

### Problem

MySQL 5.7.24 and later versions introduced a constraint that only allows **ONE** TIMESTAMP column per table to have automatic defaults (`DEFAULT CURRENT_TIMESTAMP` or `ON UPDATE CURRENT_TIMESTAMP`).

The original TestLink schema violated this constraint in two tables:

1. **inventory** - Had 2 TIMESTAMP columns with CURRENT_TIMESTAMP:
   - `creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`
   - `modification_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`

2. **baseline_l1l2_context** - Had 3 TIMESTAMP columns with CURRENT_TIMESTAMP:
   - `begin_exec_ts timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP`
   - `end_exec_ts timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP`
   - `creation_ts timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP`

### Error Message

When creating the database with MySQL 5.7.24+, you would receive:

```
ERROR 1293: Incorrect table definition; 
there can be only one TIMESTAMP column with CURRENT_TIMESTAMP in DEFAULT or ON UPDATE clause
```

### Solution

Modified both tables to use DATETIME for non-primary timestamp columns while keeping the original functionality:

#### 1. inventory table

**Before:**
```sql
`creation_ts` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`modification_ts` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
```

**After:**
```sql
`creation_ts` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`modification_ts` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
```

**Benefits:**
- Maintains creation timestamp as TIMESTAMP (primary)
- Uses DATETIME for modification tracking
- `ON UPDATE CURRENT_TIMESTAMP` automatically updates on record changes
- Fully MySQL 5.7.24+ compatible

#### 2. baseline_l1l2_context table

**Before:**
```sql
begin_exec_ts timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
end_exec_ts timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
creation_ts timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
```

**After:**
```sql
begin_exec_ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
end_exec_ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
creation_ts timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
```

**Benefits:**
- Keeps only creation_ts as TIMESTAMP (primary)
- Uses DATETIME for execution timestamps
- Fully MySQL 5.7.24+ compatible

### Technical Details

**Why DATETIME instead of TIMESTAMP?**

In MySQL, DATETIME and TIMESTAMP are functionally similar but with key differences:

| Feature | TIMESTAMP | DATETIME |
|---------|-----------|----------|
| Automatic Default | Yes (with ONE column limit) | Yes (multiple allowed) |
| Auto Update | Yes (with ONE column limit) | Yes (multiple allowed) |
| Storage Range | 1970-2038 | 1000-9999 |
| Timezone Aware | Yes (UTC stored) | No (naive local time) |
| Size | 4 bytes | 8 bytes |

For our use case:
- DATETIME allows multiple DEFAULT CURRENT_TIMESTAMP columns
- TestLink doesn't require timezone conversion (stores local time)
- 1000-9999 date range is sufficient for any reasonable timeline
- The extra 4 bytes per column is negligible for test management data

### Verification

To verify your database is compatible:

```sql
-- Check table structure
DESCRIBE inventory;
DESCRIBE baseline_l1l2_context;
```

Expected output:
- inventory: `creation_ts` = TIMESTAMP, `modification_ts` = DATETIME
- baseline_l1l2_context: `begin_exec_ts` = DATETIME, `end_exec_ts` = DATETIME, `creation_ts` = TIMESTAMP

### Installation

The fix is included in the schema file: `install/sql/mysql/testlink_create_tables.sql`

Install as usual:

```bash
mysql -u root -p testlink < install/sql/mysql/testlink_create_tables.sql
```

### Testing

Tested against:
- MySQL 5.7.24
- MySQL 8.0
- MariaDB 10.3+

All versions now accept the schema without errors.

### Affected Versions

- **Applied in:** TestLink v1.9.20+
- **Fixes:** MySQL 5.7.24+ compatibility

### Related Files

- `install/sql/mysql/testlink_create_tables.sql` - Updated schema
- Commit: 1223956e1 - MySQL timestamp compatibility fix

---

**Status:** ✅ Resolved  
**Last Updated:** August 2026
