#!/usr/bin/env python3
import sys
if len(sys.argv)<4:
    print('usage: dump_chars.py <file> <offset> <radius>')
    sys.exit(2)
fn=sys.argv[1]
o=int(sys.argv[2])
r=int(sys.argv[3])
s=open(fn,'r',encoding='utf-8',errors='replace').read()
start=max(0,o-r)
end=min(len(s),o+r+1)
for i in range(start,end):
    ch=s[i]
    print('%6d %4d %s' % (i, i-o, repr(ch)))
print('\n---slice---\n')
print(s[start:end])
