# اسکریپت PowerShell برای تشخیص خطاهای JavaScript
# نویسنده: تیم توسعه عرشلاین
# تاریخ: ۲۵ سپتامبر ۲۰۲۵
#
# استفاده:
#   .\CheckJavaScriptErrors.ps1 -FilePath "dashboard-template.php"
#   .\CheckJavaScriptErrors.ps1 -CheckAll

param(
    [string]$FilePath,
    [switch]$CheckAll,
    [switch]$Verbose
)

function Write-ColorOutput {
    param(
        [string]$Message,
        [string]$Color = "White"
    )
    Write-Host $Message -ForegroundColor $Color
}

function Test-BracketBalance {
    param([string]$JavaScriptCode)
    
    $stack = New-Object System.Collections.Stack
    $pairs = @{')' = '('; ']' = '['; '}' = '{'}
    $position = 0
    
    foreach ($char in $JavaScriptCode.ToCharArray()) {
        $position++
        
        if ($char -in @('(', '[', '{')) {
            $stack.Push(@{Char = $char; Position = $position})
        }
        elseif ($char -in @(')', ']', '}')) {
            if ($stack.Count -eq 0 -or $stack.Peek().Char -ne $pairs[$char]) {
                return @{
                    IsBalanced = $false
                    Error = "Unmatched closing '$char' at position $position"
                    Position = $position
                }
            }
            $stack.Pop() | Out-Null
        }
    }
    
    if ($stack.Count -gt 0) {
        $unmatched = ($stack.ToArray() | ForEach-Object { $_.Char }) -join ', '
        return @{
            IsBalanced = $false
            Error = "Unmatched opening brackets: $unmatched"
            Position = $stack.Peek().Position
        }
    }
    
    return @{IsBalanced = $true}
}

function Test-ArrowFunctions {
    param([string]$JavaScriptCode)
    
    $arrowPattern = '=>\s*[{(]'
    $matches = [regex]::Matches($JavaScriptCode, $arrowPattern)
    
    $warnings = @()
    foreach ($match in $matches) {
        $lineNumber = ($JavaScriptCode.Substring(0, $match.Index) -split "`n").Count
        $warnings += "Arrow Function found at line $lineNumber (may not work in older browsers)"
    }
    
    return $warnings
}

function Test-TemplateLiterals {
    param([string]$JavaScriptCode)
    
    $templatePattern = '`[^`]*`'
    $matches = [regex]::Matches($JavaScriptCode, $templatePattern)
    
    $warnings = @()
    foreach ($match in $matches) {
        $lineNumber = ($JavaScriptCode.Substring(0, $match.Index) -split "`n").Count
        $warnings += "Template Literal found at line $lineNumber (may not work in older browsers)"
    }
    
    return $warnings
}

function Test-JavaScriptInFile {
    param([string]$FilePath)
    
    if (-not (Test-Path $FilePath)) {
        Write-ColorOutput "❌ فایل یافت نشد: $FilePath" "Red"
        return $false
    }
    
    Write-ColorOutput "`n🔍 بررسی فایل: $FilePath" "Cyan"
    Write-ColorOutput ("=" * 50) "Gray"
    
    try {
        $content = Get-Content -Path $FilePath -Raw -Encoding UTF8
    }
    catch {
        Write-ColorOutput "❌ خطا در خواندن فایل: $_" "Red"
        return $false
    }
    
    # استخراج بلاک‌های JavaScript
    $scriptPattern = '<script[^>]*>(.*?)</script>'
    $scriptMatches = [regex]::Matches($content, $scriptPattern, 'IgnoreCase,Singleline')
    
    if ($scriptMatches.Count -eq 0) {
        Write-ColorOutput "ℹ️  هیچ بلاک JavaScript یافت نشد" "Yellow"
        return $true
    }
    
    $hasErrors = $false
    
    for ($i = 0; $i -lt $scriptMatches.Count; $i++) {
        $jsCode = $scriptMatches[$i].Groups[1].Value
        
        Write-ColorOutput "`n📝 بررسی Script Block $($i + 1):" "White"
        
        # بررسی تعادل براکت‌ها
        $bracketResult = Test-BracketBalance -JavaScriptCode $jsCode
        if (-not $bracketResult.IsBalanced) {
            Write-ColorOutput "❌ $($bracketResult.Error)" "Red"
            $hasErrors = $true
            
            if ($Verbose) {
                # نمایش context اطراف خطا
                $lines = $jsCode -split "`n"
                $errorLine = [math]::Min([math]::Max(($bracketResult.Position / 80), 1), $lines.Count)
                $startLine = [math]::Max(0, $errorLine - 3)
                $endLine = [math]::Min($lines.Count - 1, $errorLine + 2)
                
                Write-ColorOutput "📄 Context:" "Gray"
                for ($j = $startLine; $j -le $endLine; $j++) {
                    $marker = if ($j -eq ($errorLine - 1)) { " >>> " } else { "     " }
                    Write-ColorOutput "$marker$($j + 1): $($lines[$j])" "Gray"
                }
            }
        }
        else {
            Write-ColorOutput "✅ تعادل براکت‌ها: OK" "Green"
        }
        
        # بررسی Arrow Functions
        $arrowWarnings = Test-ArrowFunctions -JavaScriptCode $jsCode
        foreach ($warning in $arrowWarnings) {
            Write-ColorOutput "⚠️  $warning" "Yellow"
        }
        
        # بررسی Template Literals
        $templateWarnings = Test-TemplateLiterals -JavaScriptCode $jsCode
        foreach ($warning in $templateWarnings) {
            Write-ColorOutput "⚠️  $warning" "Yellow"
        }
    }
    
    if (-not $hasErrors) {
        Write-ColorOutput "✅ تمام بلاک‌های JavaScript سالم هستند" "Green"
    }
    
    return (-not $hasErrors)
}

function Test-AllPHPFiles {
    param([string]$Directory = ".")
    
    $phpFiles = Get-ChildItem -Path $Directory -Filter "*.php" -Recurse
    
    if ($phpFiles.Count -eq 0) {
        Write-ColorOutput "هیچ فایل PHP یافت نشد" "Yellow"
        return
    }
    
    Write-ColorOutput "🔍 $($phpFiles.Count) فایل PHP یافت شد" "Cyan"
    
    $totalErrors = 0
    foreach ($phpFile in $phpFiles) {
        if (-not (Test-JavaScriptInFile -FilePath $phpFile.FullName)) {
            $totalErrors++
        }
    }
    
    Write-ColorOutput "`n📊 خلاصه نتایج:" "White"
    Write-ColorOutput "   فایل‌های بررسی شده: $($phpFiles.Count)" "White"
    Write-ColorOutput "   فایل‌های دارای خطا: $totalErrors" $(if ($totalErrors -gt 0) { "Red" } else { "White" })
    Write-ColorOutput "   فایل‌های سالم: $($phpFiles.Count - $totalErrors)" "Green"
    
    if ($totalErrors -eq 0) {
        Write-ColorOutput "🎉 تبریک! هیچ خطای JavaScript یافت نشد" "Green"
    }
    else {
        Write-ColorOutput "⚠️  لطفاً خطاهای یافت شده را برطرف کنید" "Red"
    }
}

# اجرای اصلی اسکریپت
Write-ColorOutput "🔧 ابزار تشخیص خطاهای JavaScript در پلاگین عرشلاین" "Cyan"
Write-ColorOutput "نویسنده: تیم توسعه عرشلاین" "Gray"
Write-ColorOutput ""

if ($CheckAll) {
    Test-AllPHPFiles
}
elseif ($FilePath) {
    Test-JavaScriptInFile -FilePath $FilePath
}
else {
    # بررسی فایل‌های مهم
    $importantFiles = @(
        "src\Dashboard\dashboard-template.php",
        "assets\js\dashboard.js"
    )
    
    $foundFiles = $importantFiles | Where-Object { Test-Path $_ }
    
    if ($foundFiles.Count -gt 0) {
        Write-ColorOutput "🔍 بررسی فایل‌های مهم..." "Cyan"
        foreach ($file in $foundFiles) {
            Test-JavaScriptInFile -FilePath $file
        }
    }
    else {
        Write-ColorOutput "❌ فایل‌های مهم یافت نشدند. از -CheckAll استفاده کنید یا -FilePath را مشخص کنید." "Red"
        Write-ColorOutput ""
        Write-ColorOutput "مثال‌های استفاده:" "Gray"
        Write-ColorOutput "  .\CheckJavaScriptErrors.ps1 -FilePath `"dashboard-template.php`"" "Gray"
        Write-ColorOutput "  .\CheckJavaScriptErrors.ps1 -CheckAll" "Gray"
        Write-ColorOutput "  .\CheckJavaScriptErrors.ps1 -CheckAll -Verbose" "Gray"
    }
}