<?php
namespace EIPSI\StudyEngine\Runtime;
/** Presentation only. Stored dates and participant answers remain ISO. */
final class DateFormat {
    public static function read(array $definition): string {
        $format=$definition['protocol_metadata']['date_format']??'YYYY-MM-DD';
        if(!in_array($format,array('YYYY-MM-DD','DD/MM/YYYY','MM/DD/YYYY'),true))throw new \DomainException('INVALID_STUDY_DATE_FORMAT');
        return $format;
    }
    public static function local(?string $utc,string $zone,string $format='YYYY-MM-DD'): string {
        if($utc===null)return '';
        $patterns=array('YYYY-MM-DD'=>'Y-m-d','DD/MM/YYYY'=>'d/m/Y','MM/DD/YYYY'=>'m/d/Y');
        if(!isset($patterns[$format]))throw new \DomainException('INVALID_STUDY_DATE_FORMAT');
        return (new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($zone))->format($patterns[$format].' H:i T');
    }
}
