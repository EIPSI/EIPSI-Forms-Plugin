<?php
require dirname(__DIR__,2).'/includes/assessment-editor/Answers.php';
use EIPSI\AssessmentEditor\Answers;
use EIPSI\I18n\ContentDefaults as C;
$failed=0;
foreach(array('en','es') as $language){
$q=array('type'=>'text','properties'=>array_merge(C::question('text',$language),array('required'=>false,'min_length'=>0,'max_length'=>2000,'content_type'=>'letters','validation_message'=>'')));$method=new ReflectionMethod(Answers::class,'error');$message=$method->invoke(null,$q,'123',true,$language);
$expected=C::get('validation',$language)['letters'];
if($message===$expected)echo "PASS $language empty format validation fallback\n";else{$failed++;echo "FAIL $language empty format validation fallback\n";}
}
echo "2 tests, $failed failures\n";exit($failed?1:0);
