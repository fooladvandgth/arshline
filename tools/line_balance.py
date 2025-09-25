import re
p = r'c:/laragon/www/ARSHLINE/wp-content/plugins/arshline/src/Dashboard/dashboard-template.php'
with open(p,'r',encoding='utf-8') as f:
    lines = f.readlines()

# naive stateful scanner ignoring strings and comments
pairs = {'(':')','{':'}','[':']'}
openers = set(pairs.keys())
closers = set(pairs.values())
mode = None
stack = []

for i,l in enumerate(lines, start=1):
    j = 0
    while j < len(l):
        c = l[j]
        if mode:
            if c == '\\':
                j += 2
                continue
            if mode == '`' and c == '$' and j+1 < len(l) and l[j+1]=='{':
                stack.append('{')
                j += 2
                continue
            if c == mode:
                mode = None
                j += 1
                continue
            j += 1
            continue
        if c == '/' and j+1 < len(l) and l[j+1]=='/':
            break
        if c == '/' and j+1 < len(l) and l[j+1]=='*':
            # skip until end comment possibly across lines
            end = l.find('*/', j+2)
            if end == -1:
                # consume remainder of this line and go to next until close
                j = len(l)
                mode = '/*'
                break
            else:
                j = end+2
                continue
        if c in ('"',"'","`"):
            mode = c
            j += 1
            continue
        if c in openers:
            stack.append((c,i,j))
            j += 1
            continue
        if c in closers:
            if not stack:
                print('Extra closer',c,'at',i,j)
                j += 1
                continue
            top, li, col = stack.pop()
            expected = pairs[top]
            if expected != c:
                print('Mismatch', top + ' at '+str(li)+':'+str(col), ' vs ', c, 'at', i, j)
            j += 1
            continue
        j += 1
    # if mode == '/*', search subsequent lines until comment close
    if mode == '/*':
        k = i
        closed = False
        while k < len(lines):
            if '*/' in lines[k]:
                mode = None
                closed = True
                break
            k += 1
        if closed:
            continue
        else:
            break

print('At end, remaining stack size:', len(stack))
for item in stack[-10:]:
    print(' Left opener', item)
