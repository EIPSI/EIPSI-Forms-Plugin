<?php
namespace EIPSI\StudyEngine\Runtime;
/** Dedicated participant document: never invokes theme, admin bar or site head/footer hooks. */
final class PublicPage {
    public static function notice(string $message): string {return '<div class="eipsi-portal-notices" aria-live="polite"><section class="eipsi-portal-notice is-error" role="alert">'.esc_html($message).'</section></div>';}
    public static function errorContent(string $message): string {return '<main id="eipsi-study" class="eipsi-study eipsi-notice-surface">'.self::notice($message).'</main>';}
    public static function document(string $content,string $title,array $styles=array(),array $scripts=array(),string $language='en'): string {
        $html='<!doctype html><html lang="'.esc_attr(in_array($language,array('en','es'),true)?$language:'en').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>'.esc_html($title).'</title><style>html,body{margin:0;min-height:100%;background:#f4f6f9;color:#172b4d;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5}*,*:before,*:after{box-sizing:border-box}body.eipsi-public-page{padding:0}img{max-width:100%;height:auto}</style>';
        if(strpos($content,'eipsi-study-state')!==false)$html.='<style>html,body{background:#fff}</style>';
        foreach($styles as $asset)$html.='<link rel="stylesheet" href="'.esc_url(self::asset($asset)).'">';
        $html.='</head><body class="eipsi-public-page">'.$content;
        foreach($scripts as $asset)$html.='<script src="'.esc_url(self::asset($asset)).'"></script>';
        return $html.'</body></html>';
    }
    private static function asset(string $path): string {return add_query_arg('ver',(string)filemtime(EIPSI_FORMS_PLUGIN_DIR.$path),EIPSI_FORMS_PLUGIN_URL.$path);}
}
