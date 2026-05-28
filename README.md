# Xataface user_filter_prefs module

Persist user list filters in Xataface without redirects.

## Synopsis

The user_filter_prefs module stores and restores filters for the main list view
(`-action=list`) on a per-user, per-table basis.

## Features

- Restores filters on `list`, `mobile_filter_dialog`, `ajax_count_results`, `xf_infinite_scroll`.
- Persists filters only on `list`.
- Supports `session` or `db` backend.
- Apply detection supports both markers: `-ufp-apply` (module) and `-xf-filter-apply` (core mobile dialog).
- Registers a core `list_settings` action (`ufp_unfilter`) for clear filters, with native Xataface action rendering and i18n support.
- Skips technical query parameters by default.
- Fully excludes related contexts (`-relationship`, `-related:*`).
- Supports `-qf=unfilter` without redirect.
- Normalizes no-filter placeholders (`=`) so they are not persisted as active filters.
- Keeps behavior self-contained in the module (no core Xataface patch required).

## Requirements

- Xataface 2.x or later
- PHP with mysqli extension (only when using `backend=db`)

## Installation

1. Copy `modules/user_filter_prefs` into your application `modules` directory.
2. Add to `[_modules]` in `conf.ini`:

```ini
modules_user_filter_prefs=modules/user_filter_prefs/user_filter_prefs.php
```

3. Add module config in `conf.ini`:

```ini
[user_filter_prefs]
enabled=1
backend=session
auto_create_table=0
use_session_cache=1
disabled_tables=
exclude_keys=skip,-skip,-limit,-sort,-action,-table,-relationship,-qf,-cursor,--msg
include_keys=-search
```

## Configuration

- `enabled`: `1|0`
- `backend`: `session|db`
- `table_name`: storage table name (db backend only)
- `auto_create_table`: `1|0` (db backend only)
- `use_session_cache`: `1|0`
- `disabled_tables`: comma-separated table names where the module is disabled
- `exclude_keys`: comma-separated query keys never persisted
- `include_keys`: comma-separated `-` prefixed keys explicitly allowed

Defaults:
- `enabled=1`
- `backend=db`
- `table_name=dataface__filter_preferences`
- `auto_create_table=1`
- `use_session_cache=1`
- `disabled_tables=` (empty)
- `exclude_keys=skip,-skip,-limit,-sort,-action,-table,-relationship,-qf,-cursor,--msg`
- `include_keys=-search`

## Disable for Specific Tables

To disable this module for selected tables, configure `disabled_tables`:

```ini
[user_filter_prefs]
disabled_tables=logs,audit_trail,temp_results
```

Notes:
- Matching is exact and case-sensitive.
- Only valid table identifiers are accepted (`A-Z`, `a-z`, `0-9`, `_`).
- Disabled tables skip both save and restore behavior.

## Behavior

- Does nothing for anonymous users.
- Does nothing for unsupported actions.
- Does nothing in related context.
- Clears stored preferences when `-qf=unfilter` is used on `list`.
- Exposes clear-filters through the `ufp_unfilter` action in `list_settings`.
- Detects explicit Apply from filter flows via `-ufp-apply=1` and `-xf-filter-apply=1`.
- Saves explicit filters only on `list`.
- Restores saved filters into query, GET, and REQUEST when needed.

### Filter value semantics

- `field=` is treated as no-filter placeholder ("All") and is not persisted.
- `field==` remains a valid explicit filter value (for workflows that use it).
- This normalization prevents stale placeholder filters from polluting persisted preferences.

### Mobile vs Desktop filters

Xataface uses different UI flows for desktop result filters (`use_xataface2_result_filters`) and mobile dialog (`mobile_filter_dialog`).

- Desktop: filter query is built by result list JS.
- Mobile: filter query is rebuilt by mobile dialog JS and may include placeholder values.

The module handles both flows by normalizing source values during persistence, so active filters remain consistent across list reloads.

## Developer Integration

If your `ApplicationDelegate` (or table delegates) apply default prefilters,
you should skip them during an explicit unfilter flow.

The module exposes unfilter intent using one of these request signals:
- `-qf=unfilter`

Recommended pattern:

```php
$isUserUnfilter = (
	(isset($query['-qf']) and $query['-qf'] == 'unfilter')
);
```

Then guard default prefilter blocks with `and !$isUserUnfilter`.

Notes:
- Unfilter contract is based on `-qf=unfilter` only.

## Optional DB backend

```ini
[user_filter_prefs]
backend=db
auto_create_table=1
table_name=dataface__filter_preferences
```

Storage columns:
- `username`
- `table`
- `key`
- `value`
- `updated_at`

## Limitations

- Related list filters are intentionally not persisted.
- Array query values are not persisted.
- `-` prefixed keys are excluded unless whitelisted via `include_keys`.
- The standard mobile dialog does not expose an explicit "only empty values" UX for all field types.

## Verification checklist

- Apply desktop filters and verify persistence after reload.
- Apply mobile filters (including multi-select OR) and verify list results and persisted state.
- Use clear filters (`ufp_unfilter` or `-qf=unfilter`) and verify preferences are removed.
- Verify no core files are required to be modified.

## Support

- Xataface forum: http://xataface.com/forum

## License

GPL-2.0-or-later. See `LICENSE`.

## Version

- `1.1.1`
