<?php
namespace EIPSI\StudyEngine\Engine;
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Contracts\Check;
use EIPSI\StudyEngine\Domain\OccurrenceKey;

/** Exact HMAC stream; private per decision, no global PRNG. */
final class RandomStream {
    private $seed;
    private $message;
    private $counter=0;
    private $buffer='';
    public function __construct(string $seedHex, array $scope) {
        self::validateSeed($seedHex);
        Check::fields($scope,array('execution_id','version_id','owner_id','segment_id','scope','occurrence_key'));
        foreach(array('execution_id','version_id','owner_id','segment_id') as $field)Check::id($scope[$field],$field);
        if(!in_array($scope['scope'],array('EXECUTION','BLOCK_INSTANCE'),true))throw new \DomainException('INVALID_RANDOM_SCOPE');
        if($scope['scope']==='EXECUTION'&&$scope['occurrence_key']!==null)throw new \DomainException('EXECUTION_SCOPE_HAS_OCCURRENCE');
        if($scope['scope']==='BLOCK_INSTANCE')new OccurrenceKey($scope['occurrence_key']);
        $this->seed=hex2bin($seedHex); $json=Canonical::json($scope);
        $this->message="EIPSI_RANDOM_V1\0".pack('N',strlen($json)).$json;
    }
    public static function validateSeed(string $seedHex): void {
        if (PHP_INT_SIZE<8) { throw new \DomainException('RANDOM_REQUIRES_64_BIT_INTEGERS'); }
        if (!preg_match('/^[a-fA-F0-9]{64}$/D',$seedHex)) { throw new \InvalidArgumentException('Seed must encode 32 bytes'); }
    }
    public function bytes(int $length): string {
        if ($length<0 || $length>1048576) { throw new \DomainException('RANDOM_STREAM_CAP'); }
        while (strlen($this->buffer)<$length) {
            if ($this->counter>4294967295) { throw new \DomainException('RANDOM_COUNTER_EXHAUSTED'); }
            $this->buffer.=hash_hmac('sha256',$this->message.pack('N',$this->counter++),$this->seed,true);
        }
        $out=substr($this->buffer,0,$length); $this->buffer=substr($this->buffer,$length); return $out;
    }
    public function integer(int $bound): int {
        if ($bound<1 || $bound>2147483647) { throw new \DomainException('RANDOM_BOUND'); }
        $limit=intdiv(4294967296,$bound)*$bound;
        for ($tries=0;$tries<10000;++$tries) {
            $word=unpack('Nvalue',$this->bytes(4))['value']; if ($word<$limit) { return $word%$bound; }
        }
        throw new \DomainException('RANDOM_REJECTION_CAP');
    }
}

final class RandomizationEngine {
    public function draw(array $config, array $scope, string $seedHex): array {
        $pool=$config['candidates']; $strategy=$config['strategy'];
        if (in_array($strategy,array('SEQUENTIAL','PARALLEL_AVAILABLE'),true)) { return $pool; }
        if(!in_array($strategy,array('RANDOM_ORDER','RANDOM_SELECT_ONE','RANDOM_SELECT_N','WEIGHTED_RANDOM'),true)||$config['algorithm_version']!=='EIPSI_RANDOM_V1')throw new \DomainException('UNKNOWN_RANDOM_ALGORITHM');
        $stream=new RandomStream($seedHex,$scope); $selection=array();
        if ($strategy==='WEIGHTED_RANDOM') {
            $weights=$config['weights'];
            for ($draw=0;$draw<$config['draw_count'];++$draw) {
                $total=0; foreach ($pool as $candidate) { $total+=$weights[$candidate]; }
                if ($total<1) { throw new \DomainException('INVALID_ELIGIBILITY'); }
                $pick=$stream->integer($total); $sum=0;
                foreach ($pool as $index=>$candidate) {
                    $sum+=$weights[$candidate];
                    if ($pick<$sum) { $selection[]=$candidate; array_splice($pool,$index,1); break; }
                }
            }
        } else {
            for ($i=count($pool)-1;$i>0;--$i) { $j=$stream->integer($i+1); $tmp=$pool[$i];$pool[$i]=$pool[$j];$pool[$j]=$tmp; }
            $n=$strategy==='RANDOM_SELECT_ONE'?1:($strategy==='RANDOM_SELECT_N'?$config['select_n']:count($pool));
            if ($n>count($pool) || $n<1) { throw new \DomainException('INVALID_ELIGIBILITY'); }
            $selection=array_slice($pool,0,$n);
        }
        if ($config['order']==='DEFINITION' && $strategy!=='RANDOM_ORDER') {
            $selection=array_values(array_filter($config['candidates'],static function($id)use($selection){return in_array($id,$selection,true);}));
        }
        return $selection;
    }
}
