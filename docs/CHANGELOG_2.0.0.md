# 2.0.0 (2025-09-26)

- Results UI cleanup: removed global filters, polished table, density modes.
- Exports: CSV/Excel (.xls header) added next to per-field filters; exports respect filters and include all pages; dropped status column.
- Unicode: Export uses UTF-8 with BOM, unescaped Persian labels/values.
- Fields: Skip non-answer blocks (welcome/thankyou) from fields/exports.
- Security: Added _wpnonce to export URLs to avoid 401.
- Bugfix: Fixed undefined $request in submissions filter builders (500 error).
- Version bump to 2.0.0.
