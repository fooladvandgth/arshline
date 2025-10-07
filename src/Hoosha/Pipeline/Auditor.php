<?php
namespace Arshline\Hoosha\Pipeline;

class Auditor
{
    /** Build map of canonical baseline labels for quick preservation checks */
    public function buildBaselineCanonMap(array $baseline): array
    {
        $map=[]; foreach (($baseline['fields']??[]) as $f){ if(!is_array($f)) continue; $lbl=$f['label']??''; if($lbl==='') continue; $map[Normalizer::canonLabel($lbl)]=true; }
        return $map;
    }

    /** Audit baseline preservation; restore silently missing baseline fields (tag as restored_baseline)
     *  @param array $skipCanons canonical labels we intentionally suppressed (e.g., alternate date format) */
    public function baselineAudit(array $baseline, array &$schema, array $skipCanons = []): array
    {
        $res = ['missing'=>[], 'restored'=>[], 'duplicates'=>[]];
        if (empty($baseline['fields'])) return $res;
        $canonFinal = [];
        foreach (($schema['fields']??[]) as $idx=>$f){ if(!is_array($f)) continue; $lbl=$f['label']??''; if($lbl==='') continue; $c=Normalizer::canonLabel($lbl); if(isset($canonFinal[$c])){ $res['duplicates'][]=$c; } else { $canonFinal[$c]=$idx; } }
        foreach ($baseline['fields'] as $bf){ if(!is_array($bf)) continue; $lbl=$bf['label']??''; if($lbl==='') continue; $c=Normalizer::canonLabel($lbl);
            if (isset($skipCanons[$c])) continue; // intentionally suppressed, do not restore
            if(!isset($canonFinal[$c])){ $res['missing'][]=$c; $clone=$bf; if(!isset($clone['props'])||!is_array($clone['props'])) $clone['props']=[]; $clone['props']['source']='restored_baseline'; $schema['fields'][]=$clone; $res['restored'][]=$c; }
        }
        return $res;
    }
}
