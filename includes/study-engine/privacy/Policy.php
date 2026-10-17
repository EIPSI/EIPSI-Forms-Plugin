<?php
namespace EIPSI\StudyEngine\Privacy;
use EIPSI\StudyEngine\Persistence as P;
/** All future analytical consumers must use this policy, not completion state. */
final class Policy {
    public static function usable(array $execution,?array $withdrawal=null,bool $includeRetained=true): bool {
        if(($execution['data_policy']??'USE_ALLOWED')==='DO_NOT_USE'||($withdrawal['data_policy']??null)==='DO_NOT_USE')return false;
        return $includeRetained||($withdrawal['data_policy']??$execution['data_policy']??null)!=='RETAIN_FOR_ANALYSIS';
    }
    public static function emailKey(string $study,string $email): string {return hash_hmac('sha256','EIPSI_PRIVACY_EMAIL_V1|'.$study.'|'.$email,wp_salt('auth'));}
    public static function keyId(): string {return substr(hash('sha256',wp_salt('auth')),0,32);}
    public static function outcome(P\Database $db,string $execution): ?array {
        $w=(new P\WithdrawalRepository($db))->find($execution);if(!$w)return null;
        return array('id'=>$w['id'],'mode'=>$w['mode'],'data_policy'=>$w['data_policy'],'cleanup_state'=>$w['cleanup_state'],'coverage'=>P\Database::json($w['coverage_json']),'cleanup_attempts'=>(int)$w['cleanup_attempts']);
    }
}
