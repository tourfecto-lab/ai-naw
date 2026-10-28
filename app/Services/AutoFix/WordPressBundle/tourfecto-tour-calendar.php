<?php
if(!defined('ABSPATH'))exit;
/** Read-only bounded calendar inspection. No order/customer data and no calendar mutations. */
final class TourfectoTourCalendar {
    public static function read(int $id): array {
        if($id<=0)throw new RuntimeException('A valid tour ID is required.',422);$p=get_post($id);if(!$p||!TourfectoTraveler::active($p)||!in_array($p->post_status,['draft','pending','publish'],true)||!empty($p->post_password)||!current_user_can('edit_post',$id)||!current_user_can('manage_options'))throw new RuntimeException('An administrator and an editable Traveler 3.4 tour are required.',403);
        global $wpdb;if(!preg_match('/^[a-zA-Z0-9_]+$/D',$wpdb->prefix))throw new RuntimeException('Calendar database prefix unavailable.',503);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,check_in,check_out,is_base,status,number,price,adult_price,child_price,infant_price FROM {$wpdb->prefix}st_tour_availability WHERE post_id=%d ORDER BY id ASC LIMIT 401",$id),ARRAY_A);
        if(!is_array($rows)||$wpdb->last_error!=='')throw new RuntimeException('Calendar inspection could not be completed. No prices were changed.',503);
        $limited=count($rows)>400;$rows=array_slice($rows,0,400);$base=0;$custom=0;$sample=[];
        foreach($rows as $r){if((int)$r['is_base']===1)$base++;else $custom++;
            if(count($sample)<20)$sample[]=['from'=>gmdate('Y-m-d',(int)$r['check_in']),'to'=>gmdate('Y-m-d',(int)$r['check_out']),'kind'=>(int)$r['is_base']===1?'base':'custom','status'=>(string)$r['status'],'capacity'=>(int)$r['number'],'group_price'=>(string)$r['price'],'adult_price'=>(string)$r['adult_price'],'child_price'=>(string)$r['child_price'],'infant_price'=>(string)$r['infant_price']];
        }
        $currency=class_exists('TravelHelper')&&is_callable(['TravelHelper','get_default_currency'])?(string)TravelHelper::get_default_currency('name'):'unknown';if(!preg_match('/^[A-Z]{3}$/D',$currency))$currency='unknown';
        return ['id'=>$id,'currency'=>$currency,'state'=>$rows?'calendar_review_required':'no_dated_rows','rows_read'=>count($rows),'limited'=>$limited,'base_rows'=>$base,'custom_rows'=>$custom,'sample'=>$sample,'sample_limit'=>20,'dates_timezone'=>'UTC display','checked_at'=>gmdate('c'),'note'=>'Read only. Calendar rows and booking readiness have not been changed or verified. Existing rows require an explicitly reviewed calendar adapter before editing the draft base price.'];
    }
}
