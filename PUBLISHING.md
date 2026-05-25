# Publishing Guide

This guide describes how to publish this module as a standalone GitHub repository.

## 1. Preflight checks

Run these checks from the module root:

```powershell
php -l user_filter_prefs.php
```

Optional secret scan in the module folder:

```powershell
Get-ChildItem -Recurse -File | Select-String -Pattern "password\s*=|api[_-]?key|token|secret|smtp" -CaseSensitive:$false
```

## 2. Create a dedicated repository folder

Use a clean folder outside your application workspace and copy only module files:

- user_filter_prefs.php
- README.md
- readme.txt
- LICENSE
- changes.txt
- version.txt
- composer.json
- .gitignore

## 3. Initialize git and first commit

From the new module folder:

```powershell
git init
git add .
git commit -m "Initial release: user_filter_prefs 1.0.0"
```

## 4. Create GitHub repository

Create a new public repository (example):

- Repository name: xataface-module-user_filter_prefs
- Visibility: Public
- Do not initialize with README or license (already present)

## 5. Push to GitHub

Replace URL with your repository URL:

```powershell
git branch -M main
git remote add origin https://github.com/<your-user>/xataface-module-user_filter_prefs.git
git push -u origin main
```

## 6. Create first release

Suggested tag/version:

- Tag: v1.0.0
- Title: v1.0.0
- Notes: content from changes.txt

## 7. Post-publish checklist

- Verify README renders correctly on GitHub.
- Verify LICENSE is visible and recognized.
- Add repository topics (xataface, php, module, filters).
- Optionally enable Issues and Discussions.
