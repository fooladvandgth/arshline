import sys
f=sys.argv[1]
o=int(sys.argv[2])
ctx=int(sys.argv[3]) if len(sys.argv)>3 else 400
s=open(f,'r',encoding='utf-8').read()
print('file len',len(s))
print('offset',o)
start=max(0,o-ctx)
end=min(len(s),o+ctx)
print('\n--- around offset (literal view) ---\n')
print(repr(s[start:end]))
print('\n--- around offset (raw) ---\n')
print(s[start:end])
print('\n--- char at offset ---\n')
print(repr(s[o]))
print(ord(s[o]))
# show last 200 opening tokens positions
opens=[]
for i,ch in enumerate(s[:o+1]):
    if ch in '([{': opens.append((i,ch))
    elif ch in ')]}':
        if opens and ((opens[-1][1]=='(' and ch==')') or (opens[-1][1]=='[' and ch==']') or (opens[-1][1]=='{' and ch=='}')):
            opens.pop()
        else:
            print('\nfound early mismatch at',i,ch,'; open stack tail',opens[-5:])
            break
print('\nopen stack tail before offset:')
print(opens[-10:])
