# Release Notes

## Suggested Tag

`v1.1.0`

## Suggested Commit Message

`Release user_filter_prefs 1.1.0`

## Suggested PR Title

`Release user_filter_prefs 1.1.0`

## Summary

- add self-contained Apply detection via `-ufp-apply`
- add automatic `Annulla Filtri` injection in list settings
- simplify unfilter handling to `-qf=unfilter`
- improve compatibility by loading module JS via module URL
- document delegate integration for applications with default prefilters

## Verification

- `php -l user_filter_prefs.php`
- install module in `conf.ini`
- apply filters and verify persistence on list reload
- clear filters via `Annulla Filtri` and verify saved filters are removed
- verify applications with default prefilters can skip them with:

```php
(isset($query['-qf']) and $query['-qf'] == 'unfilter')
```