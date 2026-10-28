<?php
/** Private bounded local extraction; never sends the source document to an OCR provider. */
class KnowledgeDocumentReader
{
    public static function status(): array {
        return ['text_pdf'=>function_exists('proc_open')&&is_executable('/usr/bin/pdftotext'),'ocr'=>function_exists('proc_open')&&is_executable('/usr/bin/pdftoppm')&&is_executable('/usr/bin/tesseract'),'ocr_page_limit'=>6,'ocr_note'=>'Local Tesseract OCR. Required language packs must be installed. Review extracted facts before saving.'];
    }
    public static function read(string $path,bool $ocr=false,string $language='en',?callable $runner=null): array {
        if(!is_file($path)||is_link($path)||filesize($path)>10*1024*1024||file_get_contents($path,false,null,0,5)!=='%PDF-')return ['ok'=>false,'error'=>'Choose a valid PDF under 10 MB'];
        $status=self::status();if(!$runner&&!$status['text_pdf']&&!($ocr&&$status['ocr']))return ['ok'=>false,'error'=>'PDF tools are unavailable. Upload a text file or enable PDF tools on the server.'];
        $run=$runner??fn($args,$timeout)=>VideoEditing::process($args,$timeout);
        try{$text=($runner||$status['text_pdf'])?$run(['/usr/bin/pdftotext','-l','40','-enc','UTF-8','-nopgbrk',$path,'-'],15):'';}catch(Throwable $e){$text='';}
        if(mb_strlen(trim($text))>=80)return ['ok'=>true,'text'=>mb_substr($text,0,24000),'method'=>'pdf_text','truncated'=>mb_strlen($text)>24000];
        if(!$ocr)return ['ok'=>false,'error'=>'This PDF has little readable text. Select Local OCR for scanned documents.'];
        if(!$runner&&!$status['ocr'])return ['ok'=>false,'error'=>'Local OCR requires pdftoppm, Tesseract and the selected language pack.'];
        $langs=['en'=>'eng','ar'=>'ara','fr'=>'fra','de'=>'deu','es'=>'spa','it'=>'ita','pt'=>'por'];$lang=$langs[$language]??'eng';
        $root=dirname(__DIR__,3).'/storage/knowledge-import';if(!is_dir($root)&&!mkdir($root,0700,true))return ['ok'=>false,'error'=>'Unable to prepare document workspace'];
        $dir=$root.'/'.bin2hex(random_bytes(16));mkdir($dir,0700);$started=microtime(true);
        try{$run(['/usr/bin/pdftoppm','-f','1','-l','6','-r','110','-scale-to','1800','-png',$path,$dir.'/page'],25);$pages=glob($dir.'/page-*.png')?:[];sort($pages,SORT_NATURAL);if(!$pages||count($pages)>6)throw new RuntimeException('No readable OCR pages');$parts=[];
            foreach($pages as $page){if(microtime(true)-$started>45||filesize($page)>12*1024*1024)throw new RuntimeException('OCR time or size limit reached');chmod($page,0600);$parts[]=$run(['/usr/bin/tesseract',$page,'stdout','-l',$lang,'--psm','3'],10);}
            $text=implode("\n\n",$parts);if(mb_strlen(trim($text))<80)throw new RuntimeException('OCR did not extract enough text');return ['ok'=>true,'text'=>mb_substr($text,0,24000),'method'=>'local_ocr','pages_read'=>count($pages),'truncated'=>true,'note'=>'OCR reads at most the first six pages. Review recognition errors before saving.'];
        }catch(Throwable $e){return ['ok'=>false,'error'=>'Unable to read this scan. Check the document and OCR language pack, or upload its text.'];}finally{foreach(glob($dir.'/*')?:[] as $p)if(is_file($p))@unlink($p);@rmdir($dir);}
    }
}
