<?php
/** Test-only: shared CI gates structural bounds; a controlled benchmark may assert wall time. */
function mg_timing_limit($raw): ?float {
    if($raw===false)return null;
    if(!is_string($raw)||!preg_match('/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/D',$raw)||!is_finite((float)$raw)||(float)$raw<=0)throw new InvalidArgumentException('EIPSI_MANAGEMENT_MAX_SECONDS must be a positive finite number');
    return (float)$raw;
}
function mg_timing_assert(float $elapsed,?float $limit,int $participations): void {
    if($limit!==null&&$elapsed>=$limit)throw new RuntimeException('Dashboard query budget: '.$participations.' participations, '.round($elapsed,4).' s >= '.$limit.' s');
}
