<?php
namespace EIPSI\StudyEngine\Domain;
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Contracts\Check;

/** An ordered path: each segment identifies a block and its logical slot. */
final class OccurrenceKey implements \JsonSerializable {
    private $segments;
    public function __construct(array $segments) {
        Check::listOf($segments, 'occurrence_key');
        if (!$segments) { Check::fail('EMPTY_OCCURRENCE_KEY', 'occurrence_key', 'At least one segment required'); }
        foreach ($segments as $i => $segment) {
            Check::fields($segment, array('block_id','slot'), array(), 'occurrence_key.' . $i);
            Check::id($segment['block_id'], 'block_id');
            $slot = $segment['slot'];
            if ($slot !== 'single') {
                Check::fields($slot, array(), array('day','slot','ordinal'), 'slot');
                if (!$slot || (!isset($slot['day']) && !isset($slot['ordinal'])) || (isset($slot['day']) && isset($slot['ordinal']))) {
                    Check::fail('INVALID_OCCURRENCE_SLOT', 'slot', 'Use single, day[/slot] or ordinal');
                }
                foreach ($slot as $key => $value) { Check::integer($value, 'slot.' . $key, 1); }
            }
        }
        $this->segments = Canonical::normalize($segments);
    }
    public function jsonSerialize(): array { return $this->segments; }
    public function key(): string { return Canonical::json($this->segments); }
}
