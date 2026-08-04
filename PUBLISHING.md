# Publishing Guide

This guide describes how to publish this module as a standalone GitHub repository.

## Repository

- GitHub repo: `module-user_filter_prefs`
- Owner: [plcats](https://github.com/plcats)
- URL: https://github.com/plcats/module-user_filter_prefs
- Composer package: `plcats/xataface-user-filter-prefs`
- Release tag: from `version.txt` (currently `1.1.1` → tag `v1.1.1`)

## 1. Preflight checks

Run these checks from the module root:

```powershell
php -l user_filter_prefs.php
```

Optional secret scan in the module folder:

```powershell
Get-ChildItem -Recurse -File | Select-String -Pattern "password\s*=|api[_-]?key|token|secret|smtp" -CaseSensitive:$false
```

Core integrity check (recommended):

- Do not include local changes to Xataface core files in this module release.
- The module is expected to work without core patching.

## 2. Create a dedicated repository folder

Use a clean folder outside your application workspace and copy only module files:

- user_filter_prefs.php
- user_filter_prefs.js
- README.md
- readme.txt
- LICENSE
- changes.txt
- version.txt
- composer.json
- RELEASE_NOTES.md
- PUBLISHING.md
- .gitignore

## 3. Initialize git and first commit

From the new module folder:

```powershell
git init
git add .
git commit -m "Release user_filter_prefs 1.1.1"
```

## 4. Create GitHub repository

Create a new public repository (example):

- Repository name: `module-user_filter_prefs`
- Owner: `plcats`
- Visibility: Public
- Do not initialize with README or license (already present)

```powershell
gh repo create plcats/module-user_filter_prefs --public --source=. --remote=origin
```

## 5. Push to GitHub

```powershell
git branch -M main
git remote add origin https://github.com/plcats/module-user_filter_prefs.git
git push -u origin main
```

## 6. Create first release

Suggested tag/version:

- Tag: `v1.1.1`
- Title: `v1.1.1`
- Notes: content from `changes.txt` / `RELEASE_NOTES.md`

## 6b. Suggested PR metadata

- PR title: `Release user_filter_prefs 1.1.1`
- Squash commit message: `Release user_filter_prefs 1.1.1`

Suggested PR summary:

- no core-patch filter persistence module for Xataface lists
- apply detection via `-ufp-apply` and `-xf-filter-apply`
- clear-filters action integration (`ufp_unfilter`) and `-qf=unfilter` contract
- mobile/desktop filter-flow normalization and documentation updates

## 7. Post-publish checklist

- Verify README renders correctly on GitHub.
- Verify LICENSE is visible and recognized.
- Add repository topics (xataface, php, module, filters).
- Optionally enable Issues and Discussions.
- Verify desktop and mobile filter flows on a test app.
- Verify no Xataface core modifications are required to use the module.
