# Changelog

## 7.1.0 - Hoosha Smart Form Builder (هوشا، فرم‌ساز باهوش)

Enhancements:
- Advanced format detection: sheba_ir, credit_card_ir, national_id_company_ir, alphanumeric, alphanumeric_no_space, captcha_alphanumeric, file_upload.
- Enhanced enumeration parsing for numbered, parenthetical, comma, slash, and hyphen separated option lists.
- Dynamic rating range detection (e.g. "از 1 تا 7").
- Multi-select intent detection for plural constructs (e.g. روزهای هفته).
- Option cross-contamination resolver with semantic splitting (drinks vs contact methods).
- Confirm national ID field modeling via confirm_for metadata.
- Phrase preservation improvements (e.g. "روزی که گذشت").
- Early spelling normalization pipeline (نوشیدنی‌آ -> نوشیدنی‌ها, دوس -> دوست...).

Fixes:
- Eliminated residual mixed option sets and concatenated tokens.
- Improved sanitation and rebuilt edited_text for consistent schema alignment.

Upcoming (not yet in this release):
- Validator & telemetry instrumentation.
- Composite field splitting (mobile & email, height & weight).
- Expanded semantic noun preservation and debug UI enhancements.

## 7.0.0
Initial baseline for new multipass parser foundation before advanced formats.
