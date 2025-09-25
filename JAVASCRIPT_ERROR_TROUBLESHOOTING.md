# راهنمای حل مسائل خطاهای JavaScript در پلاگین عرشلاین

## مقدمه
این داکیومنت راهنمای کاملی برای تشخیص، عیب‌یابی و حل مسائل خطاهای JavaScript در پلاگین عرشلاین است. این مسائل بخصوص در فایل‌های template و dashboard بسیار تکرار می‌شوند.

## انواع خطاهای رایج

### ۱. SyntaxError: missing ) after argument list

**علت:** عدم تعادل در براکت‌ها، پرانتزها یا آکولادها

**مثال خطا:**
```javascript
(index):1690 Uncaught SyntaxError: missing ) after argument list (at (index):1690:21)
```

**علت‌های اصلی:**
- آکولاد بسته اضافی `}`
- پرانتز بسته کم `)`
- براکت بسته کم `]`
- استفاده نادرست از Arrow Functions در مرورگرهای قدیمی

### ۲. خطاهای Arrow Function

**کد مشکل‌دار:**
```javascript
.then(r => r.json())
```

**کد درست:**
```javascript
.then(function(r){ return r.json(); })
```

**علت:** Arrow Functions در مرورگرهای قدیمی پشتیبانی نمی‌شوند.

### ۳. خطاهای Object Literal ناقص

**کد مشکل‌دار:**
```javascript
var config = {
    name: 'test',
    value: 123,  // کاما اضافی
}
```

**کد درست:**
```javascript
var config = {
    name: 'test',
    value: 123
}
```

## روش‌های تشخیص مشکل

### ۱. استفاده از Browser Console
```javascript
// باز کردن Developer Tools
F12 → Console Tab
// مشاهده خطاهای JavaScript
```

### ۲. اسکریپت تشخیص تعادل براکت‌ها

```python
# فایل: bracket_checker.py
import re

def check_brackets(file_path):
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # استخراج بلاک‌های script
    script_blocks = re.findall(r'<script[^>]*>(.*?)</script>', content, re.DOTALL | re.IGNORECASE)
    
    for i, block in enumerate(script_blocks):
        stack = []
        pairs = {')': '(', ']': '[', '}': '{'}
        
        for j, char in enumerate(block):
            if char in '([{':
                stack.append((char, j))
            elif char in ')]}':
                if not stack or stack[-1][0] != pairs[char]:
                    print(f'Script block {i}: Unmatched closing {char} at position {j}')
                    # نمایش متن اطراف خطا
                    start = max(0, j-50)
                    end = min(len(block), j+50)
                    print(f'Context: ...{block[start:end]}...')
                    break
                stack.pop()
        
        if stack:
            print(f'Script block {i}: Unmatched opening brackets: {[x[0] for x in stack]}')
        else:
            print(f'Script block {i}: Brackets balanced OK')

# استفاده:
check_brackets('dashboard-template.php')
```

### ۳. اسکریپت PowerShell سریع

```powershell
# فایل: CheckJSErrors.ps1
param([string]$FilePath)

$content = Get-Content -Path $FilePath -Raw -Encoding UTF8
$scriptBlocks = [regex]::Matches($content, '<script[^>]*>(.*?)</script>', 'IgnoreCase,Singleline')

foreach ($block in $scriptBlocks) {
    $jsCode = $block.Groups[1].Value
    
    # شمارش براکت‌ها
    $openBrace = ($jsCode.ToCharArray() | Where-Object {$_ -eq '{'}).Count
    $closeBrace = ($jsCode.ToCharArray() | Where-Object {$_ -eq '}'}).Count
    $openParen = ($jsCode.ToCharArray() | Where-Object {$_ -eq '('}).Count
    $closeParen = ($jsCode.ToCharArray() | Where-Object {$_ -eq ')'}).Count
    
    Write-Host "Braces: Open=$openBrace, Close=$closeBrace"
    Write-Host "Parentheses: Open=$openParen, Close=$closeParen"
    
    if ($openBrace -ne $closeBrace -or $openParen -ne $closeParen) {
        Write-Host "⚠️ Bracket mismatch detected!" -ForegroundColor Red
    } else {
        Write-Host "✅ Brackets balanced" -ForegroundColor Green
    }
}
```

## مراحل عیب‌یابی گام به گام

### مرحله ۱: شناسایی اولیه
1. باز کردن Browser Console
2. رفرش صفحه
3. مشاهده خطاها و یادداشت خط و فایل

### مرحله ۲: تشخیص دقیق
1. اجرای اسکریپت تشخیص براکت
2. بررسی خطوط مشکوک
3. مقایسه با نسخه‌های قبلی کد

### مرحله ۳: اصلاح مشکل
1. اصلاح براکت‌های نامتعادل
2. تبدیل Arrow Functions به توابع عادی
3. بررسی Object Literals و آرایه‌ها

### مرحله ۴: تست و تایید
1. اجرای مجدد اسکریپت تشخیص
2. رفرش مرورگر و بررسی Console
3. تست عملکرد کلی

## ابزارهای مفید

### ۱. IDE Extensions
- **VSCode:** Bracket Pair Colorizer
- **PHPStorm:** Built-in bracket matching
- **Sublime Text:** BracketHighlighter

### ۲. Linting Tools
```bash
# نصب ESLint برای JavaScript
npm install -g eslint

# بررسی فایل
eslint your-script.js
```

### ۳. Online Validators
- JSHint.com
- JSLint.com
- JavaScript Validator

## الگوهای رایج مشکلات

### ۱. مشکل Copy-Paste
```javascript
// اشتباه: کپی کردن کد بدون توجه به محیط
fetch(url).then(data => data.json())

// درست: استفاده از syntax سازگار
fetch(url).then(function(data) { return data.json(); })
```

### ۲. مشکل Template Literals
```javascript
// اشتباه: استفاده از template literals در مرورگرهای قدیمی
var html = `<div>${variable}</div>`;

// درست: استفاده از concatenation
var html = '<div>' + variable + '</div>';
```

### ۳. مشکل Object Destructuring
```javascript
// اشتباه: استفاده از destructuring
const {name, age} = user;

// درست: دسترسی مستقیم
var name = user.name;
var age = user.age;
```

## چک‌لیست پیشگیری

### قبل از Commit:
- [ ] اجرای اسکریپت تشخیص براکت
- [ ] تست در مرورگرهای مختلف
- [ ] بررسی Console برای خطاها
- [ ] استفاده از Linter

### هنگام توسعه:
- [ ] استفاده از IDE با bracket matching
- [ ] اجتناب از Arrow Functions در کد پشتیبانی قدیمی
- [ ] استفاده از `'use strict';` در ابتدای فایل‌ها
- [ ] کامنت گذاری روی بخش‌های پیچیده

### بعد از تغییرات:
- [ ] تست کامل عملکرد
- [ ] بررسی Performance
- [ ] مستندسازی تغییرات

## مثال عملی: حل مشکل Dashboard Template

### خطای اصلی:
```
(index):1696 Uncaught SyntaxError: missing ) after argument list
```

### تشخیص:
```bash
# اجرای اسکریپت تشخیص
python bracket_checker.py dashboard-template.php

# نتیجه: Script block 1: Unmatched closing } at position 121042
```

### حل مشکل:
1. **Arrow Function Issue:**
```javascript
// قبل
.then(r => r.json())
// بعد  
.then(function(r){ return r.json(); })
```

2. **Missing Condition:**
```javascript
// قبل
if (t === 'short_text' || t === 'long_text' || draggingTool)
// بعد
if (t === 'short_text' || t === 'long_text' || t === 'multiple_choice' || t === 'multiple-choice' || draggingTool)
```

3. **Extra Closing Brace:**
```javascript
// قبل
                    }
                }).catch(function(){...
// بعد
                }).catch(function(){...
```

4. **Missing Function Closing:**
```javascript
// اضافه کردن آکولاد بسته برای renderFormBuilder
function renderFormBuilder(id){
    // ... کد تابع
} // این آکولاد کم بود
```

## نتیجه‌گیری

حل مسائل JavaScript نیاز به رویکرد منظم و استفاده از ابزارهای مناسب دارد. با دنبال کردن این راهنما و استفاده مداوم از ابزارهای تشخیص، می‌توان از بروز مجدد این مسائل جلوگیری کرد.

## فایل‌های مرتبط
- `dashboard-template.php` - فایل اصلی template
- `dashboard.js` - فایل JavaScript جداگانه  
- `bracket_checker.py` - اسکریپت تشخیص براکت
- `CheckJSErrors.ps1` - اسکریپت PowerShell

---
**آخرین به‌روزرسانی:** ۲۵ سپتامبر ۲۰۲۵
**نویسنده:** تیم توسعه عرشلاین