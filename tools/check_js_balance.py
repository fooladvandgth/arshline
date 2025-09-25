import re
import sys
p = r'c:/laragon/www/ARSHLINE/wp-content/plugins/arshline/src/Dashboard/dashboard-template.php'
try:
    with open(p,'r',encoding='utf-8') as f:
        txt = f.read()
except Exception as e:
    print('ERR: open',e); sys.exit(2)
# extract script blocks
scripts = re.findall(r'<script[^>]*>([\s\S]*?)</script>', txt, flags=re.I)
if not scripts:
    print('No <script> blocks found')
    sys.exit(0)

def check_balance(s):
    stack = []
    i = 0
    n = len(s)
    errors = []
    mode = None  # '"', "'", '`'
    while i < n:
        c = s[i]
        if mode:
            if c == '\\':
                i += 2
                continue
            if mode == '`' and c == '$' and i+1<n and s[i+1]=='{':
                stack.append('{')
                i += 2
                continue
            if c == mode:
                mode = None
                i += 1
                continue
            i += 1
            continue
        # skip comments
        if c == '/' and i+1<n and s[i+1]=='/':
            i += 2
            while i<n and s[i] != '\n': i+=1
            continue
        if c == '/' and i+1<n and s[i+1]=='*':
            i += 2
            while i+1<n and not (s[i]== '*' and s[i+1]=='/'): i+=1
            i += 2
            continue
        if c in ('"', "'", '`'):
            mode = c
            i += 1
            continue
        if c in '([{':
            stack.append(c)
            i += 1
            continue
        if c in ')]}':
            if not stack:
                errors.append(('extra_closer', c, i))
                i += 1
                continue
            top = stack.pop()
            pairs = { '(':')','[':']','{':'}' }
            if pairs.get(top, '') != c:
                errors.append(('mismatch', top, c, i))
            i += 1
            continue
        i += 1
    return stack, errors

any_err = False
for idx, s in enumerate(scripts, start=1):
    st, errs = check_balance(s)
    print(f'--- script #{idx} length={len(s)} ---')
    if st:
        print('UNMATCHED OPENERS:', st[:20])
        any_err = True
    if errs:
        print('ERRS:')
        for e in errs[:20]:
            print(' ', e)
        any_err = True
    if not st and not errs:
        print('OK')

if not any_err:
    print('All script blocks appear balanced (basic check)')

