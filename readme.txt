Xataface user_filter_prefs module
Copyright (C) 2026 user_filter_prefs contributors

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.

Synopsis:
=========

The user_filter_prefs module stores and restores filters for the main list view
(-action=list) on a per-user and per-table basis.

Features:
=========

- Restore on list, mobile_filter_dialog, ajax_count_results, xf_infinite_scroll.
- Persist only on list.
- Session or DB backend.
- Self-contained Apply detection via -ufp-apply (no core patch required).
- Auto-injected Annulla Filtri action in list settings.
- Excludes technical query params.
- Excludes related context completely.
- Supports -qf=unfilter without redirect.
- Supports per-table disable list via disabled_tables.

Requirements:
=============

- Xataface 2.x or higher

Installation:
=============

1. Copy user_filter_prefs into your application modules directory.
2. Add to [_modules] in conf.ini:

	modules_user_filter_prefs=modules/user_filter_prefs/user_filter_prefs.php

3. Add config section:

	[user_filter_prefs]
	enabled=1
	backend=session
	auto_create_table=0
	use_session_cache=1
	disabled_tables=
	exclude_keys=skip,-skip,-limit,-sort,-action,-table,-relationship,-qf,-cursor,--msg
	include_keys=-search

Usage:
======

The module automatically persists explicit filters from list requests and restores
saved filters when opening list-related filter/count actions.

If your ApplicationDelegate applies default prefilters, guard them during clear
filter flows using:

	(isset($query['-qf']) and $query['-qf'] == 'unfilter')

To disable the module on specific tables:

	[user_filter_prefs]
	disabled_tables=logs,audit_trail,temp_results

Limitations:
============

- Related list filters are intentionally not persisted.
- Array query values are not persisted.

Version:
========

1.1.0

Support:
========

http://xataface.com/forum
