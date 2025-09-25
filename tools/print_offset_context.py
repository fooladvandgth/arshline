#!/usr/bin/env python3
import sys
if len(sys.argv)<3:
    print('usage: print_offset_context.py <file> <offset>')
    sys.exit(2)
fn=sys.argv[1]
o=int(sys.argv[2])
with open(fn,'r',encoding='utf-8',errors='replace') as f:
    s=f.read()
# compute line/col
line=1; col=1
for i,ch in enumerate(s):
    if i==o: break
    if ch=='\n': line+=1; col=1
    else: col+=1
lines=s.splitlines()
print('file:',fn)
print('offset:',o,'approx line:',line,'col:',col)
start_line=max(0,line-6)
end_line=min(len(lines),line+6)
print('\n---context lines %d..%d---\n' % (start_line+1,end_line))
for idx in range(start_line,end_line):
    mark = '>' if idx+1==line else ' '
    print('%s %4d: %s' % (mark, idx+1, lines[idx]))
