<?php
// Classifies the tone of new AI answers that name the company. Suggested schedule: daily.
require_once __DIR__.'/bootstrap.php';
try {
    $db=Database::getInstance();AiInsightsSchema::ensureSchema($db);$svc=new AiSentimentService($db);$sites=0;$classified=0;
    $s=$db->getConnection()->query('SELECT DISTINCT a.user_id,a.website_id FROM seo_ai_answers a LEFT JOIN seo_ai_sentiment s ON s.answer_id=a.id WHERE a.mentioned_brand=1 AND s.answer_id IS NULL LIMIT 200');
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){
        try{$r=$svc->analyzePending((int)$row['user_id'],(int)$row['website_id'],25);$classified+=$r['classified'];$sites++;}
        catch(InvalidArgumentException $e){/* Business profile needs the customer's confirmation first. */}
    }
    fwrite(STDOUT,"AI sentiment: $classified answers classified across $sites sites\n");
}catch(Throwable $e){fwrite(STDERR,"AI sentiment run failed: ".$e->getMessage()."\n");exit(1);}
