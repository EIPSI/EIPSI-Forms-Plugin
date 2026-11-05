<?php
/** Test-only source-copy instrumentation; does not write the approved worktree. */
if(PHP_SAPI!=='cli'||getenv('EIPSI_PERF1')!=='1'||empty($argv[1]))exit(2);
$root=realpath($argv[1]);if(!$root||strpos($root,'/.cache/perf1/')===false)throw new RuntimeException('Only a disposable profiling copy is allowed');
$manifest=array();foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/includes',FilesystemIterator::SKIP_DOTS)) as $f){if($f->getExtension()!=='php')continue;$relative=substr((string)$f,strlen($root)+1);$src=file_get_contents($f);$tokens=token_get_all($src);$positions=array();$offset=0;foreach($tokens as $t){$positions[]=$offset;$offset+=strlen(is_array($t)?$t[1]:$t);}$edits=array();
 foreach($tokens as $i=>$token){if(!is_array($token)||$token[0]!==T_FUNCTION)continue;$paren=0;$body=null;for($j=$i+1;$j<count($tokens);$j++){if($tokens[$j]==='(')$paren++;elseif($tokens[$j]===')')$paren--;elseif($tokens[$j]===';'&&$paren===0)break;elseif($tokens[$j]==='{'&&$paren===0){$body=$j;break;}}if($body===null)continue;$depth=1;$end=null;for($j=$body+1;$j<count($tokens);$j++){if($tokens[$j]==='{'||(is_array($tokens[$j])&&in_array($tokens[$j][0],array(T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES),true)))$depth++;elseif($tokens[$j]==='}')$depth--;if($depth===0){$end=$j;break;}}if($end===null)throw new RuntimeException('Unbalanced test copy');
 $relative=substr((string)$f,strlen($root)+1);$label=$relative.':'.$token[2];$context='null';$object='null';
 // Compare immutable version input contexts internally, never export their identifiers/hashes.
 if(basename((string)$f)==='StudyVersionRepository.php'){$sig=substr($src,$positions[$i],$positions[$body]-$positions[$i]);if(preg_match('/function\s+compiled\s*\(/',$sig)){$context="\$row";$object="\$this";}}
 $before="\$__perf1Token=class_exists('Perf1Profiler',false)?\\Perf1Profiler::enter(__METHOD__,".var_export($label,true).",$context,$object):null;try{";$after="}finally{if(\$__perf1Token!==null)\\Perf1Profiler::leave(\$__perf1Token);}";
 $edits[]=array($positions[$body]+1,$before);$edits[]=array($positions[$end],$after);
 }
 $combined=array();foreach($edits as $edit)$combined[$edit[0]]=($combined[$edit[0]]??'').$edit[1];krsort($combined);foreach($combined as $position=>$text)$src=substr($src,0,$position).$text.substr($src,$position);
 if(substr((string)$f,-strlen('/study-engine/persistence/Database.php'))==='/study-engine/persistence/Database.php')$src=str_replace('mysqli_query($this->wpdb->dbh,$sql)',"(class_exists('Perf1Profiler',false)?\\Perf1Profiler::mysqliQuery(\$this->wpdb->dbh,\$sql):mysqli_query(\$this->wpdb->dbh,\$sql))",$src);
 file_put_contents($f,$src);$manifest[$relative]=count($edits)/2;
}
echo json_encode(array('instrumented_methods'=>array_sum($manifest),'files'=>count($manifest)))."\n";
