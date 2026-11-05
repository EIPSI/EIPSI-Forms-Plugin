<?php
namespace { final class Perf2Entropy { public static $active=false,$counter=0,$scope="";public static function begin(string $scope=""):void{self::$scope=$scope;self::$counter=0;self::$active=true;}public static function end():void{self::$active=false;}public static function bytes(int $n):string{if(!self::$active)return random_bytes($n);$out='';while(strlen($out)<$n)$out.=hash('sha512','PERF2_DISPOSABLE_ONLY|'.self::$scope.'|'.(++self::$counter),true);return substr($out,0,$n);} } if(($_SERVER['HTTP_X_PERF2_ENTROPY']??'')==='fixed'&&getenv('WORDPRESS_DB_HOST')==='eipsi-m0-db:3306')Perf2Entropy::begin("HTTP:".($_GET["eipsi_study"]??"")); }
namespace EIPSI\StudyEngine\Persistence {function random_bytes(int $n):string{return $n===32?str_repeat(chr(1),32):\Perf2Entropy::bytes($n);} }
namespace EIPSI\StudyEngine\Orchestration {function random_bytes(int $n):string{return \Perf2Entropy::bytes($n);} }
namespace EIPSI\StudyEngine\Longitudinal {function random_bytes(int $n):string{return \Perf2Entropy::bytes($n);} }
namespace EIPSI\StudyEngine\Runtime {function random_bytes(int $n):string{return \Perf2Entropy::bytes($n);} }
namespace EIPSI\AssessmentEditor\Runtime {function random_bytes(int $n):string{return \Perf2Entropy::bytes($n);} }
