<?php
namespace Arshline\Hosha2;

/**
 * Minimal JSON Patch (RFC 6902 subset) applier supporting add/remove/replace for arrays & associative arrays.
 * Unsupported ops (move/copy/test) should be validated earlier and rejected.
 */
class Hosha2JsonPatchApplier
{
    /**
     * Apply a diff (array of patch operations) to a deep-copied snapshot and return new structure.
     * @param array $snapshot Base form structure (will not be mutated)
     * @param array $diff JSON patch operations
     * @param array $errors Collected errors (by reference)
     * @return array|null Modified snapshot or null on error
     */
    public function apply(array $snapshot, array $diff, array &$errors = []): ?array
    {
        $errors = [];
        $target = $snapshot; // work on copy
        foreach ($diff as $i => $op) {
            if (!is_array($op)) { $errors[] = "op[$i]: not array"; return null; }
            $kind = $op['op'] ?? null; $path = $op['path'] ?? null;
            if (!in_array($kind, ['add','remove','replace'], true)) { $errors[] = "op[$i]: unsupported op"; return null; }
            if (!is_string($path) || $path === '' || $path[0] !== '/') { $errors[] = "op[$i]: invalid path"; return null; }
            $segments = $this->parsePath($path);
            if ($segments === null) { $errors[] = "op[$i]: path parse failed"; return null; }
            switch ($kind) {
                case 'add':
                    if (!array_key_exists('value', $op)) { $errors[] = "op[$i]: add missing value"; return null; }
                    if (!$this->applyAdd($target, $segments, $op['value'])) { $errors[] = "op[$i]: add failed"; return null; }
                    break;
                case 'remove':
                    if (!$this->applyRemove($target, $segments)) { $errors[] = "op[$i]: remove failed"; return null; }
                    break;
                case 'replace':
                    if (!array_key_exists('value', $op)) { $errors[] = "op[$i]: replace missing value"; return null; }
                    if (!$this->applyReplace($target, $segments, $op['value'])) { $errors[] = "op[$i]: replace failed"; return null; }
                    break;
            }
        }
        return $target;
    }

    /** Convert /a/b/0 to ['a','b','0'] with ~1 and ~0 unescaping per RFC6901 */
    private function parsePath(string $path): ?array
    {
        if ($path === '/') return [''];
        $parts = explode('/', substr($path,1));
        $out = [];
        foreach ($parts as $p) {
            $p = str_replace('~1','/', str_replace('~0','~',$p));
            $out[] = $p;
        }
        return $out;
    }

    private function &navigateToParent(array &$root, array $segments, bool $create = false, bool &$ok = null)
    {
        $ok = true;
        $ref =& $root; $lastIndex = count($segments)-1;
        for ($i=0; $i<$lastIndex; $i++) {
            $seg = $segments[$i];
            $isIndex = ctype_digit(strval($seg));
            if ($isIndex) {
                // ensure array
                if (!is_array($ref)) { $ok=false; break; }
                if (!array_key_exists((int)$seg, $ref)) {
                    if ($create) { $ref[(int)$seg] = []; }
                    else { $ok=false; break; }
                }
                $ref =& $ref[(int)$seg];
            } else {
                if (!is_array($ref)) { $ok=false; break; }
                if (!array_key_exists($seg, $ref)) {
                    if ($create) { $ref[$seg] = []; }
                    else { $ok=false; break; }
                }
                $ref =& $ref[$seg];
            }
            if (!is_array($ref)) { // only descend into arrays/assoc arrays
                if ($i < $lastIndex-1) { $ok=false; break; }
            }
        }
        return $ref;
    }

    private function applyAdd(array &$root, array $segments, $value): bool
    {
        $ok = true; $parent =& $this->navigateToParent($root, $segments, true, $ok); if (!$ok) return false;
        $last = end($segments);
        $isIndex = ctype_digit(strval($last));
        if ($isIndex) {
            $idx = (int)$last;
            if (!is_array($parent)) return false;
            $count = count($parent);
            if ($idx === $count) { // append
                $parent[] = $value; return true;
            }
            if ($idx < 0 || $idx > $count) return false;
            // insert at index
            array_splice($parent, $idx, 0, [$value]);
            return true;
        } else {
            if (!is_array($parent)) return false;
            $parent[$last] = $value;
            return true;
        }
    }

    private function applyRemove(array &$root, array $segments): bool
    {
        $ok=true; $parent =& $this->navigateToParent($root, $segments, false, $ok); if (!$ok) return false;
        $last = end($segments);
        $isIndex = ctype_digit(strval($last));
        if ($isIndex) {
            $idx = (int)$last; if (!is_array($parent) || !array_key_exists($idx,$parent)) return false;
            array_splice($parent, $idx, 1);
            return true;
        } else {
            if (!is_array($parent) || !array_key_exists($last,$parent)) return false;
            unset($parent[$last]);
            return true;
        }
    }

    private function applyReplace(array &$root, array $segments, $value): bool
    {
        $ok=true; $parent =& $this->navigateToParent($root, $segments, false, $ok); if (!$ok) return false;
        $last = end($segments);
        $isIndex = ctype_digit(strval($last));
        if ($isIndex) {
            $idx = (int)$last; if (!is_array($parent) || !array_key_exists($idx,$parent)) return false;
            $parent[$idx] = $value; return true;
        } else {
            if (!is_array($parent) || !array_key_exists($last,$parent)) return false;
            $parent[$last] = $value; return true;
        }
    }
}
