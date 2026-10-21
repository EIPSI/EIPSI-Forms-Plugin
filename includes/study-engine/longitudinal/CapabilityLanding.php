<?php
namespace EIPSI\StudyEngine\Longitudinal;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Runtime\AccessDenied;
/** Short-lived cookie handoff strips the email credential before automatic access. */
final class CapabilityLanding {
    public static function pendingName(string $public): string {return Identity::cookieName($public).'_pending';}
    public static function validate(P\Database $db,string $public,string $cap,?string $existing): void {
        $row=Capability::configured($db)->find($public,$cap);
        if($row['consumed_at']!==null){$session=(new Identity($db))->recover($public,$existing);if(!$session||$session['participant_id']!==$row['participant_id'])throw new AccessDenied('ACCESS_REQUIRED');}
    }
    public static function cookie(string $public,string $value,int $expires): void {setcookie(self::pendingName($public),$value,array('expires'=>$expires,'path'=>wp_parse_url(home_url('/'),PHP_URL_PATH)?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'));}
}
