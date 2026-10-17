<?php
namespace EIPSI\StudyEngine\Engine;

use EIPSI\StudyEngine\Contracts\Check;

final class SimulationClock {
    private $instant;
    private $timezone;
    public function __construct(string $instant, string $timezone) {
        Check::timestamp($instant, 'clock'); Check::timezone($timezone, 'timezone');
        $this->instant = $instant; $this->timezone = $timezone;
    }
    public function now(): string { return $this->instant; }
    public function timezone(): string { return $this->timezone; }
    public function set(string $instant): void {
        Check::timestamp($instant, 'clock');
        if ($instant < $this->instant) { throw new \DomainException('CLOCK_BACKWARD'); }
        $this->instant = $instant;
    }
    public function advance(int $amount, string $unit = 'seconds'): void {
        $units = array('seconds'=>1, 'minutes'=>60, 'hours'=>3600, 'days'=>86400);
        if ($amount < 0 || !isset($units[$unit])) { throw new \DomainException('INVALID_CLOCK_ADVANCE'); }
        $this->set(TemporalResolver::utc(TemporalResolver::epoch($this->instant) + $amount * $units[$unit]));
    }
    public function changeTimezone(string $zone): void { Check::timezone($zone, 'timezone'); $this->timezone = $zone; }
}

/** Resolve wall-clock intent explicitly; never read the host clock or default zone. */
final class TemporalResolver {
    public static function epoch(string $utc): int { return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp(); }
    public static function utc(int $epoch): string { return gmdate('Y-m-d\TH:i:s\Z', $epoch); }
    public static function local(string $utc, string $zone): string {
        return (new \DateTimeImmutable($utc))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d\TH:i:s');
    }
    public static function wall(string $local, string $zone): array {
        Check::timezone($zone, 'timezone');
        $naive = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $local, new \DateTimeZone('UTC'));
        if (!$naive || $naive->format('Y-m-d\TH:i:s') !== $local) { throw new \DomainException('INVALID_LOCAL_TIME'); }
        $wall = $naive->getTimestamp(); $tz = new \DateTimeZone($zone);
        $transitions = $tz->getTransitions($wall - 259200, $wall + 259200); $offsets = array();
        if ($transitions === false) { $offsets[] = $tz->getOffset($naive); }
        else { foreach ($transitions as $t) { $offsets[$t['offset']] = $t['offset']; } }
        $candidates = array();
        foreach ($offsets as $offset) {
            $utc = self::utc($wall - $offset);
            if (self::local($utc, $zone) === $local) { $candidates[] = $wall - $offset; }
        }
        if ($candidates) {
            sort($candidates, SORT_NUMERIC); $instant = self::utc($candidates[0]);
            return array('instant'=>$instant, 'local_intent'=>$local, 'effective_local'=>self::local($instant,$zone), 'timezone'=>$zone, 'adjustment'=>count($candidates)>1?'EARLIER_INSTANT':'NONE');
        }
        $previous = null;
        foreach ($transitions ?: array() as $t) {
            if ($previous !== null && $t['offset'] > $previous && $wall >= $t['ts']+$previous && $wall < $t['ts']+$t['offset']) {
                $instant = self::utc($wall-$previous);
                return array('instant'=>$instant, 'local_intent'=>$local, 'effective_local'=>self::local($instant,$zone), 'timezone'=>$zone, 'adjustment'=>'SHIFT_FORWARD_BY_GAP');
            }
            $previous = $t['offset'];
        }
        throw new \DomainException('UNRESOLVABLE_LOCAL_TIME');
    }
    public static function add(string $anchor, array $duration, string $zone): string {
        if ($duration['kind'] === 'ELAPSED') { return self::utc(self::epoch($anchor)+$duration['seconds']); }
        $local = new \DateTimeImmutable(self::local($anchor,$zone), new \DateTimeZone('UTC'));
        if ($duration['unit'] === 'MONTH') {
            $day = (int)$local->format('d');
            $base = $local->modify('first day of this month')->modify('+'.$duration['amount'].' months');
            $local = $base->setDate((int)$base->format('Y'), (int)$base->format('m'), min($day,(int)$base->format('t')));
        } else { $local = $local->modify('+'.$duration['amount'].' '.($duration['unit']==='WEEK'?'weeks':'days')); }
        return self::wall($local->format('Y-m-d\TH:i:s'),$zone)['instant'];
    }
    public static function absolute(array $at): string {
        return $at['basis']==='UTC' ? $at['instant'] : self::wall($at['local_datetime'],$at['timezone'])['instant'];
    }
    public static function availability(?string $anchor, array $rule, string $zone): ?string {
        if ($rule['kind']==='ABSOLUTE') { return self::absolute($rule['at']); }
        if ($anchor===null) { return null; }
        if ($rule['kind']==='IMMEDIATE') { return $anchor; }
        if ($rule['kind']==='OFFSET') { return self::add($anchor,$rule['duration'],$zone); }
        if ($rule['kind']==='LOCAL_DAY_TIME') {
            $date = new \DateTimeImmutable(substr(self::local($anchor,$zone),0,10),new \DateTimeZone('UTC'));
            return self::wall($date->modify('+'.($rule['day_index']-1).' days')->format('Y-m-d').'T'.$rule['time'].':00',$zone)['instant'];
        }
        throw new \DomainException('RECURRENCE_REQUIRES_MATERIALIZER');
    }
    public static function deadline(?string $available, array $window, string $zone, callable $event): ?string {
        if ($available===null || $window['kind']==='NO_DEADLINE') { return null; }
        if ($window['kind']==='DURATION') { $due=self::add($available,$window['duration'],$zone); }
        elseif ($window['kind']==='ABSOLUTE') { $due=self::absolute($window['at']); }
        else { $due=$event($window); }
        if ($due!==null && $due<=$available) { throw new \DomainException('TEMPORAL_CONFLICT'); }
        return $due;
    }
    public static function state(string $now, ?string $available, ?string $due): string {
        if ($available===null) { return 'BLOCKED'; }
        if ($due!==null && $now >= $due) { return 'EXPIRED'; }
        return $now < $available ? 'SCHEDULED':'AVAILABLE';
    }
}

final class RecurrenceMaterializer {
    private $cap;
    public function __construct(int $cap = 10000) {
        if ($cap<1) { throw new \InvalidArgumentException('Invalid recurrence cap'); } $this->cap=$cap;
    }
    /** Generator has bounded work and an explicit continuation cursor (ordinal), never silent truncation. */
    public function slots(?array $recurrence, ?string $anchor, string $zone, ?string $horizon = null, int $from = 1): \Generator {
        if ($recurrence===null) { yield array('slot'=>'single','available_at'=>$anchor,'ordinal'=>1,'resolution'=>null); return true; }
        $r=$recurrence; $count=$r['kind']==='DAILY_LOCAL'?$r['days']*count($r['times']):($r['count']??null);
        if ($anchor===null && $count===null) { return false; }
        $date=$anchor===null?null:new \DateTimeImmutable(substr(TemporalResolver::local($anchor,$zone),0,10),new \DateTimeZone('UTC'));
        $ordinal=0; $emitted=0; $work=0;
        for ($index=0; $count===null || $ordinal<$count; ++$index) {
            if (++$work>$this->cap*8+8) { throw new \DomainException('RECURRENCE_WORK_CAP'); }
            $resolution=null;
            if ($r['kind']==='DAILY_LOCAL') {
                $day=$r['start_day']+intdiv($index,count($r['times'])); $slotIndex=$index%count($r['times']);
                $slot=array('day'=>$day,'slot'=>$slotIndex+1);
                $resolution=$date===null?null:TemporalResolver::wall($date->modify('+'.($day-1).' days')->format('Y-m-d').'T'.$r['times'][$slotIndex].':00',$zone);
                $at=$resolution['instant']??null;
            } elseif ($r['kind']==='ELAPSED') {
                $slot=array('ordinal'=>$index+1); $at=$anchor===null?null:TemporalResolver::utc(TemporalResolver::epoch($anchor)+$index*$r['interval']['seconds']);
            } else {
                if ($date===null) { $slot=array('ordinal'=>$index+1); $at=null; }
                else {
                    $d=$date->modify('+'.$index.' days');
                    if (!in_array(strtoupper($d->format('D')),$r['weekdays'],true)) { continue; }
                    $resolution=TemporalResolver::wall($d->format('Y-m-d').'T'.$r['time'].':00',$zone);$at=$resolution['instant'];
                    if ($at<$anchor || (!$r['inclusive_start'] && $at===$anchor)) { continue; }
                    $slot=array('ordinal'=>$ordinal+1);
                }
            }
            if ($at!==null && isset($r['until']) && $at>$r['until']) { return true; }
            ++$ordinal;
            if ($ordinal<$from) { continue; }
            if ($at!==null && $horizon!==null && $at>$horizon) { return false; }
            if (++$emitted>$this->cap) { throw new \DomainException('RECURRENCE_CAP'); }
            yield array('slot'=>$slot,'available_at'=>$at,'ordinal'=>$ordinal,'resolution'=>$resolution);
        }
        return true;
    }
}
