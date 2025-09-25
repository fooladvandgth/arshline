#!/usr/bin/env python3
# quick helper to find unmatched tokens in a file and print context
import sys, os
if len(sys.argv)<2:
    print('usage: find_js_mismatch.py <file>')
    sys.exit(2)
fn=sys.argv[1]
s=open(fn,'rb').read()
try:
    s2=s.decode('utf-8')
except:
    s2=s.decode('utf-8','replace')
pairs={')':'(',']':'[','}':'{'}
openers='([{'
closers=')]}'
stack=[]
line=1
col=1
for i,ch in enumerate(s2):
    if ch=='\n':
        line+=1; col=1; continue
    if ch in openers:
        stack.append((ch,i,line,col))
    elif ch in closers:
        if stack and stack[-1][0]==pairs[ch]:
            stack.pop()
        else:
            print('MISMATCH at index',i,'char',repr(ch),'line',line,'col',col)
            ctx_start=max(0,i-200)
            ctx_end=min(len(s2),i+200)
            print('\n---context---\n')
            print(s2[ctx_start:ctx_end])
            print('\n---stack top---')
            print(stack[-6:])
            sys.exit(1)
    col+=1
if stack:
    print('UNMATCHED OPENERS remain:', len(stack))
    for k in stack[-6:]:
        print('  opener',k)
    last=stack[-1]
    i=last[1]
    ctx_start=max(0,i-200)
    ctx_end=min(len(s2),i+200)
    print('\n---context around last opener---\n')
    print(s2[ctx_start:ctx_end])
    sys.exit(1)
print('OK - all matched')
sys.exit(0)
