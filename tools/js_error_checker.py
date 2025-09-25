#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
اسکریپت تشخیص و عیب‌یابی خطاهای JavaScript در فایل‌های PHP
نویسنده: تیم توسعه عرشلاین
تاریخ: ۲۵ سپتامبر ۲۰۲۵

استفاده:
    python js_error_checker.py dashboard-template.php
    python js_error_checker.py --all  # بررسی تمام فایل‌های PHP
"""

import re
import sys
import os
from pathlib import Path
import argparse

class JavaScriptErrorChecker:
    def __init__(self):
        self.errors = []
        self.warnings = []
    
    def check_file(self, file_path):
        """بررسی یک فایل PHP برای خطاهای JavaScript"""
        try:
            with open(file_path, 'r', encoding='utf-8') as f:
                content = f.read()
        except Exception as e:
            print(f"❌ خطا در خواندن فایل {file_path}: {e}")
            return False
        
        print(f"\n🔍 بررسی فایل: {file_path}")
        print("=" * 50)
        
        # استخراج بلاک‌های script
        script_blocks = re.findall(r'<script[^>]*>(.*?)</script>', content, re.DOTALL | re.IGNORECASE)
        
        if not script_blocks:
            print("ℹ️  هیچ بلاک JavaScript یافت نشد")
            return True
        
        file_has_errors = False
        
        for i, block in enumerate(script_blocks):
            print(f"\n📝 بررسی Script Block {i + 1}:")
            block_errors = self.check_script_block(block, i)
            if block_errors:
                file_has_errors = True
        
        if not file_has_errors:
            print("✅ تمام بلاک‌های JavaScript سالم هستند")
        
        return not file_has_errors
    
    def check_script_block(self, block, block_index):
        """بررسی یک بلاک JavaScript"""
        errors_found = False
        
        # بررسی تعادل براکت‌ها
        bracket_errors = self.check_brackets(block, block_index)
        if bracket_errors:
            errors_found = True
        
        # بررسی Arrow Functions
        arrow_warnings = self.check_arrow_functions(block, block_index)
        if arrow_warnings:
            errors_found = True
        
        # بررسی Template Literals
        template_warnings = self.check_template_literals(block, block_index)
        if template_warnings:
            errors_found = True
        
        # بررسی Object Destructuring
        destructure_warnings = self.check_destructuring(block, block_index)
        if destructure_warnings:
            errors_found = True
        
        return errors_found
    
    def check_brackets(self, block, block_index):
        """بررسی تعادل براکت‌ها، پرانتزها و آکولادها"""
        stack = []
        pairs = {')': '(', ']': '[', '}': '{'}
        errors_found = False
        
        for j, char in enumerate(block):
            if char in '([{':
                stack.append((char, j))
            elif char in ')]}':
                if not stack or stack[-1][0] != pairs[char]:
                    line_num = block[:j].count('\n') + 1
                    print(f"❌ براکت نامتعادل: '{char}' در خط {line_num}")
                    
                    # نمایش context
                    lines = block.split('\n')
                    if line_num <= len(lines):
                        start_line = max(0, line_num - 3)
                        end_line = min(len(lines), line_num + 2)
                        print("📄 Context:")
                        for ln in range(start_line, end_line):
                            marker = " >>> " if ln == line_num - 1 else "     "
                            print(f"{marker}{ln + 1:3}: {lines[ln]}")
                    
                    errors_found = True
                    break
                stack.pop()
        
        if stack and not errors_found:
            print(f"⚠️  براکت‌های باز نشده: {[x[0] for x in stack]}")
            errors_found = True
        
        if not errors_found:
            print("✅ تعادل براکت‌ها: OK")
        
        return errors_found
    
    def check_arrow_functions(self, block, block_index):
        """بررسی استفاده از Arrow Functions"""
        arrow_pattern = r'=>\s*[{(]'
        matches = re.finditer(arrow_pattern, block)
        
        warnings_found = False
        for match in matches:
            line_num = block[:match.start()].count('\n') + 1
            print(f"⚠️  Arrow Function یافت شد در خط {line_num} (ممکن است در مرورگرهای قدیمی مشکل داشته باشد)")
            warnings_found = True
        
        return warnings_found
    
    def check_template_literals(self, block, block_index):
        """بررسی استفاده از Template Literals"""
        template_pattern = r'`[^`]*`'
        matches = re.finditer(template_pattern, block)
        
        warnings_found = False
        for match in matches:
            line_num = block[:match.start()].count('\n') + 1
            print(f"⚠️  Template Literal یافت شد در خط {line_num} (ممکن است در مرورگرهای قدیمی مشکل داشته باشد)")
            warnings_found = True
        
        return warnings_found
    
    def check_destructuring(self, block, block_index):
        """بررسی استفاده از Object Destructuring"""
        destructure_pattern = r'(?:const|let|var)\s*\{\s*\w+[^}]*\}\s*='
        matches = re.finditer(destructure_pattern, block)
        
        warnings_found = False
        for match in matches:
            line_num = block[:match.start()].count('\n') + 1
            print(f"⚠️  Object Destructuring یافت شد در خط {line_num} (ممکن است در مرورگرهای قدیمی مشکل داشته باشد)")
            warnings_found = True
        
        return warnings_found
    
    def check_all_php_files(self, directory="."):
        """بررسی تمام فایل‌های PHP در دایرکتوری"""
        php_files = list(Path(directory).rglob("*.php"))
        
        if not php_files:
            print("هیچ فایل PHP یافت نشد")
            return
        
        print(f"🔍 {len(php_files)} فایل PHP یافت شد")
        
        total_errors = 0
        for php_file in php_files:
            if not self.check_file(php_file):
                total_errors += 1
        
        print(f"\n📊 خلاصه نتایج:")
        print(f"   فایل‌های بررسی شده: {len(php_files)}")
        print(f"   فایل‌های دارای خطا: {total_errors}")
        print(f"   فایل‌های سالم: {len(php_files) - total_errors}")
        
        if total_errors == 0:
            print("🎉 تبریک! هیچ خطای JavaScript یافت نشد")
        else:
            print("⚠️  لطفاً خطاهای یافت شده را برطرف کنید")

def main():
    parser = argparse.ArgumentParser(description='تشخیص خطاهای JavaScript در فایل‌های PHP')
    parser.add_argument('file', nargs='?', help='فایل PHP برای بررسی')
    parser.add_argument('--all', action='store_true', help='بررسی تمام فایل‌های PHP')
    
    args = parser.parse_args()
    
    checker = JavaScriptErrorChecker()
    
    if args.all:
        checker.check_all_php_files()
    elif args.file:
        if os.path.exists(args.file):
            checker.check_file(args.file)
        else:
            print(f"❌ فایل یافت نشد: {args.file}")
    else:
        # اگر هیچ آرگومان داده نشده، فایل‌های مهم را بررسی کن
        important_files = [
            "src/Dashboard/dashboard-template.php",
            "assets/js/dashboard.js"
        ]
        
        found_files = [f for f in important_files if os.path.exists(f)]
        
        if found_files:
            print("🔍 بررسی فایل‌های مهم...")
            for file in found_files:
                checker.check_file(file)
        else:
            print("❌ فایل‌های مهم یافت نشدند. از --all استفاده کنید یا مسیر فایل را مشخص کنید.")

if __name__ == "__main__":
    main()