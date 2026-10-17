<?php
namespace EIPSI\StudyEngine\Privacy;
final class Coverage {
    public static function report(array $categories=array(),array $unsupported=array()): array {
        $base=\EIPSI_Privacy_Coverage_Report::for_operation('cleanup');
        $excluded=array_values(array_unique(array_merge($base['excluded'],array('institutional_backups','external_databases','server_proxy_logs','delivered_email','downloaded_exports','browser_client_copies','external_analytics','unlinked_free_text'),$unsupported)));
        return array('contract'=>'P1G_COVERAGE_V1','source'=>'WORDPRESS_LOCAL_RELATIONAL','complete'=>false,'local_complete'=>!$unsupported&&!in_array('PENDING',$categories,true),'categories'=>$categories,'not_covered'=>$excluded);
    }
}
