import re, sys
p = r'c:/laragon/www/ARSHLINE/wp-content/plugins/arshline/src/Dashboard/dashboard-template.php'
with open(p,'r',encoding='utf-8') as f:
    txt = f.read()
scripts = re.findall(r'<script[^>]*>([\s\S]*?)</script>', txt, flags=re.I)
s = scripts[1]  # large script

# simple scanner that records stack positions
stack = []
mode = None
positions = []
for i,c in enumerate(s):
    if mode:
        if c == '\\':
            i += 1
            continue
        if mode == '`' and c == '$' and i+1<len(s) and s[i+1]=='{':
            stack.append(('{', i))
            positions.append(('push','{',i))
            continue
        if c == mode:
            mode = None
            positions.append(('close_quote', c, i))
            continue
        continue
    if c == '/' and i+1<len(s) and s[i+1]=='/':
        # skip to newline
        j = s.find('\n', i+2)
        if j==-1: break
        i = j
        continue
    if c == '/' and i+1<len(s) and s[i+1]=='*':
        j = s.find('*/', i+2)
        if j==-1: break
        i = j+1
        continue
    if c in ('"', "'", '`'):
        mode = c
        positions.append(('open_quote', c, i))
        continue
    if c in '([{':
        stack.append((c,i))
        positions.append(('push', c, i))
        continue
    if c in ')]}':
        if not stack:
            positions.append(('extra_close', c, i))
            continue
        top, topi = stack.pop()
        pairs = { '(':')','[':']','{':'}' }
        if pairs.get(top, '') != c:
            positions.append(('mismatch', top, c, topi, i))
        else:
            positions.append(('pop', top, topi, c, i))

print('stack at end:', stack[:10])
# print last 200 events
for ev in positions[-200:]:
    print(ev)
