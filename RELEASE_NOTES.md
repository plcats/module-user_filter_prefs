# Release Notes

## Suggested Tag

`v1.1.1`

## Suggested Commit Message

`Release user_filter_prefs 1.1.1`

## Suggested PR Title

`Release user_filter_prefs 1.1.1`

## Summary

- self-contained filter persistence module with no core Xataface patch required
- apply detection supports both `-ufp-apply` and `-xf-filter-apply`
- core `list_settings` clear action registration (`ufp_unfilter`)
- unfilter contract simplified to `-qf=unfilter`
- normalization of no-filter placeholders (`=`) to avoid stale persisted filters
- compatibility improvement: module JS loaded by module URL with version token for cache-busting
- documentation aligned for GitHub publication

## Verification

- `php -l user_filter_prefs.php`
- install module in `conf.ini`
- apply desktop filters and verify persistence on list reload
- apply mobile filters (including multi-select OR) and verify persisted state remains coherent
- clear filters via `ufp_unfilter`/`-qf=unfilter` and verify saved filters are removed
- verify applications with default prefilters can skip them with:

```php
(isset($query['-qf']) and $query['-qf'] == 'unfilter')
```