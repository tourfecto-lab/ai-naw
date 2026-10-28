<?php
require_once __DIR__.'/../Services/SeoExpert/SeoPageEditorService.php';
final class SeoPageEditorController extends Controller {
    public function dispatch(array $p=[]): array {
        header('Cache-Control: no-store, private');
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if($e=$this->verifyCsrf())return $e;
        if($e=$this->rateLimitGuard('user','seo_page_editor',15,60))return $e;
        try { return $this->success((new SeoPageEditorService())->call((int)$this->user['id'],(int)($p['website_id']??0),(string)($p['operation']??''),$this->data)); }
        catch(InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
        catch(Throwable $e) { Logger::warning('WordPress page editor needs review',['user_id'=>(int)$this->user['id'],'website_id'=>(int)($p['website_id']??0)]);return $this->error('WordPress operation could not be confirmed. Read the page and operation history before retrying a save. / تعذر تأكيد العملية. اقرأ الصفحة وسجل التعديلات قبل إعادة محاولة الحفظ.',503); }
    }
}
