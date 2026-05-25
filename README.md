# Xataface user_filter_prefs module

Persist user list filters in Xataface without redirects.

## Synopsis

The user_filter_prefs module stores and restores filters for the main list view
(`-action=list`) on a per-user, per-table basis.

## Features

- Restores filters on `list`, `mobile_filter_dialog`, `ajax_count_results`, `xf_infinite_scroll`.
- Persists filters only on `list`.
- Supports `session` or `db` backend.
- Skips technical query parameters by default.
- Fully excludes related contexts (`-relationship`, `-related:*`).
- Supports `-qf=unfilter` without redirect.

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
exclude_keys=skip,-skip,-limit,-sort,-action,-table,-relationship,-qf,-cursor,--msg
include_keys=-search
```

## Configuration

- `enabled`: `1|0`
- `backend`: `session|db`
- `table_name`: storage table name (db backend only)
- `auto_create_table`: `1|0` (db backend only)
- `use_session_cache`: `1|0`
- `exclude_keys`: comma-separated query keys never persisted
- `include_keys`: comma-separated `-` prefixed keys explicitly allowed

## Behavior

- Does nothing for anonymous users.
- Does nothing for unsupported actions.
- Does nothing in related context.
- Clears stored preferences when `-qf=unfilter` is used on `list`.
- Saves explicit filters only on `list`.
- Restores saved filters into query, GET, and REQUEST when needed.

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

## Support

- Xataface forum: http://xataface.com/forum

## License

GPL-2.0-or-later. See `LICENSE`.

## Version

- `1.0.0`
