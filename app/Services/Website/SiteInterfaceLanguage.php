<?php
/** Translate only fixed source interface strings; user names, prices and content are never translated by this class. */
class SiteInterfaceLanguage {
    public static function text(string $text,string $language='en'): string {
        if(explode('-',strtolower($language))[0]==='ar')return $text;
        $map=require __DIR__.'/../../Config/site_interface_en.php';
        if(isset($map[$text]))return $map[$text];
        // Word boundaries avoid translating single-letter currency/unit markers inside other words.
        unset($map['ج'],$map['م']);return strtr($text,$map);
    }
}
