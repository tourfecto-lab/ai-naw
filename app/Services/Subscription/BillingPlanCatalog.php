<?php
/** Billing rows are the authority for purchasable prices and allowances. */
class BillingPlanCatalog
{
    public const LEGACY_ALLOWANCES = [
        'starter'=>['ai_visibility'=>30,'seo_data'=>200,'seo_markets'=>1,'website_generations'=>2,'website_items'=>20,'articles'=>4,'marketing_tools'=>40,'media_images'=>10,'media_videos'=>0,'ai_tools'=>60],
        'professional'=>['ai_visibility'=>120,'seo_data'=>1500,'seo_markets'=>2,'website_generations'=>5,'website_items'=>80,'articles'=>12,'marketing_tools'=>150,'media_images'=>40,'media_videos'=>2,'ai_tools'=>250],
        'enterprise'=>['ai_visibility'=>500,'seo_data'=>6000,'seo_markets'=>4,'website_generations'=>12,'website_items'=>300,'articles'=>40,'marketing_tools'=>500,'media_images'=>120,'media_videos'=>6,'ai_tools'=>800],
    ];

    /** حدود خدمة السيو اللي الأدمن يقدر يعدلها: [أقل قيمة, أكبر قيمة] */
    public const SEO_LIMITS = ['seo_cycles'=>[1,4],'seo_max_pages'=>[100,500],'seo_markets'=>[1,12],'ai_visibility'=>[0,100000],'seo_data'=>[0,1000000],'articles'=>[0,10000]];

    /** الفحص بيشتغل على 100 أو 300 أو 500 صفحة بس: ده الرقم الفعلي لأي قيمة مخزّنة (مصدر واحد للفحص وصفحة الأسعار) */
    public static function effectiveSeoPages($stored): int
    {
        $n = is_numeric($stored) ? (int) $stored : 100;
        return $n >= 500 ? 500 : ($n >= 300 ? 300 : 100);
    }

    public static function seoLimit(string $field, $value): int
    {
        if (!isset(self::SEO_LIMITS[$field])) throw new InvalidArgumentException('Unknown SEO limit');
        if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string) $value)) throw new InvalidArgumentException('Invalid SEO limit');
        [$min, $max] = self::SEO_LIMITS[$field];
        $v = (int) $value;
        if ($v < $min || $v > $max) throw new InvalidArgumentException('SEO limit out of range');
        // الفحص بيشتغل على 100 أو 300 أو 500 صفحة بس، فأي رقم تاني كان هيتقرّب في صمت
        if ($field === 'seo_max_pages' && !in_array($v, [100, 300, 500], true)) throw new InvalidArgumentException('Pages must be 100, 300 or 500');
        return $v;
    }

    private const AUDIT_FIELDS = ['name','price_monthly','price_yearly','currency','is_active','ai_analysis','competitor_analysis','chat_credits','review_credits','multiple_websites','whatsapp_bot','auto_pilot','advanced_analytics','ai_assistant_messages','website_generations','website_items','whatsapp_marketing_messages','seo_cycles','seo_max_pages','seo_markets','ai_visibility','seo_data','articles'];

    /** الجدول بيتعمل قبل الترانزاكشن (DDL بيقفل أي ترانزاكشن مفتوحة في MySQL) */
    public static function ensureAuditTable(Database $db): void
    {
        $db->getConnection()->exec('CREATE TABLE IF NOT EXISTS plan_change_audit (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            plan_key VARCHAR(80) NOT NULL,
            actor_id INT UNSIGNED NULL,
            changes_json TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_plan_time (plan_key, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    /** بيرجع الفروق بس (قبل/بعد) ويسجّلها؛ لو مفيش تغيير مبيكتبش حاجة */
    public static function recordChange(Database $db, string $planKey, ?int $actorId, array $before, array $after): array
    {
        $diff = [];
        foreach (self::AUDIT_FIELDS as $field) {
            if (!array_key_exists($field, $after)) continue;
            $old = $before[$field] ?? null;
            $new = $after[$field];
            if (is_bool($old)) $old = (int) $old;
            if (is_bool($new)) $new = (int) $new;
            $same = is_numeric($old) && is_numeric($new) ? (float) $old === (float) $new : (string) $old === (string) $new;
            if (!$same) $diff[$field] = ['from' => $old, 'to' => $new];
        }
        if ($diff) {
            $db->getConnection()->prepare('INSERT INTO plan_change_audit (plan_key, actor_id, changes_json) VALUES (?,?,?)')
                ->execute([$planKey, $actorId, json_encode($diff, JSON_UNESCAPED_UNICODE)]);
        }
        return $diff;
    }

    public static function recentChanges(Database $db, int $limit = 40): array
    {
        self::ensureAuditTable($db);
        $st = $db->getConnection()->prepare('SELECT a.plan_key, a.actor_id, a.changes_json, a.created_at, u.email AS actor_email FROM plan_change_audit a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT ' . max(1, min(200, $limit)));
        $st->execute();
        return array_map(static function ($r) { $r['changes'] = json_decode((string) $r['changes_json'], true) ?: []; unset($r['changes_json']); return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public const MAX_CUSTOM_PLANS = 12;

    /**
     * باقة جديدة من الأدمن: نسخة من باقة موجودة (دورتين شهري وسنوي + صف العرض)، مقفولة للبيع (is_active=0)
     * لحد ما الأدمن يعدّلها ويفعّلها. العلامة custom في features_json هي اللي بتسمح بحذفها بعدين.
     */
    public static function createCustomPlan(Database $db, string $newKey, string $name, string $cloneKey, ?int $actorId): string
    {
        if (!preg_match('/^[a-z][a-z0-9-]{2,29}$/D', $newKey)) throw new InvalidArgumentException('مفتاح الباقة: حروف إنجليزية صغيرة وأرقام وشرطة، من 3 لـ 30 حرف');
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150) throw new InvalidArgumentException('اكتب اسم الباقة');
        $pdo = $db->getConnection();
        self::ensureAuditTable($db);
        $pdo->beginTransaction();
        try {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $st = $pdo->prepare('SELECT * FROM subscription_plans WHERE plan_code IN (?,?,?,?)' . $lock);
            $st->execute([$newKey . '_monthly', $newKey . '_yearly', $cloneKey . '_monthly', $cloneKey . '_yearly']);
            $rows = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[$r['plan_code']] = $r;
            if (isset($rows[$newKey . '_monthly']) || isset($rows[$newKey . '_yearly'])) throw new InvalidArgumentException('المفتاح ده مستخدم قبل كده');
            $st = $pdo->prepare('SELECT COUNT(*) FROM plan_pricing_display WHERE plan_key = ?');
            $st->execute([$newKey]);
            if ((int) $st->fetchColumn() > 0) throw new InvalidArgumentException('المفتاح ده مستخدم قبل كده');
            if (!isset($rows[$cloneKey . '_monthly'], $rows[$cloneKey . '_yearly'])) throw new InvalidArgumentException('الباقة اللي هتتنسخ منها مش موجودة');
            $cq = $pdo->prepare("SELECT COUNT(*) FROM subscription_plans WHERE billing_cycle = 'monthly' AND features_json LIKE ?"); $cq->execute(['%"custom":%true%']); $n = (int) $cq->fetchColumn();
            if ($n >= self::MAX_CUSTOM_PLANS) throw new InvalidArgumentException('وصلت الحد الأقصى للباقات الجديدة (' . self::MAX_CUSTOM_PLANS . ')');

            foreach (['monthly', 'yearly'] as $cycle) {
                $src = $rows[$cloneKey . '_' . $cycle];
                $f = json_decode((string) ($src['features_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($f)) throw new RuntimeException('Invalid billing feature configuration');
                $f['custom'] = true;
                unset($src['id']);
                $src['plan_code'] = $newKey . '_' . $cycle;
                $src['name'] = $name;
                $src['is_active'] = 0;
                $src['features_json'] = json_encode($f, JSON_THROW_ON_ERROR);
                unset($src['created_at'], $src['updated_at']);
                $cols = array_keys($src);
                $pdo->prepare('INSERT INTO subscription_plans (' . implode(',', array_map(fn ($c) => '`' . $c . '`', $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($src));
            }
            $st = $pdo->prepare('SELECT * FROM plan_pricing_display WHERE plan_key = ?');
            $st->execute([$cloneKey]);
            $disp = $st->fetch(PDO::FETCH_ASSOC);
            if ($disp) {
                unset($disp['id'], $disp['created_at'], $disp['updated_at']);
                $disp['plan_key'] = $newKey;
                $disp['name'] = $name;
                $disp['is_active'] = 0;
                $cols = array_keys($disp);
                $pdo->prepare('INSERT INTO plan_pricing_display (' . implode(',', array_map(fn ($c) => '`' . $c . '`', $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($disp));
            }
            $pdo->prepare('INSERT INTO plan_change_audit (plan_key, actor_id, changes_json) VALUES (?,?,?)')
                ->execute([$newKey, $actorId, json_encode(['created' => ['from' => null, 'to' => 'نسخة من ' . $cloneKey . ' (مقفولة للبيع)']], JSON_UNESCAPED_UNICODE)]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $newKey;
    }

    /** حذف باقة اتعملت من الأدمن، بس لو مفيش ولا اشتراك (حتى منتهي) مربوط بيها */
    public static function deleteCustomPlan(Database $db, string $planKey, ?int $actorId): void
    {
        $pdo = $db->getConnection();
        self::ensureAuditTable($db);
        $pdo->beginTransaction();
        try {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $st = $pdo->prepare('SELECT id, features_json FROM subscription_plans WHERE plan_code IN (?,?)' . $lock);
            $st->execute([$planKey . '_monthly', $planKey . '_yearly']);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 2) throw new InvalidArgumentException('الباقة مش موجودة');
            foreach ($rows as $r) {
                $f = json_decode((string) $r['features_json'], true);
                if (!is_array($f) || empty($f['custom'])) throw new InvalidArgumentException('الباقات الأساسية مبتتحذفش، اقفلها من «مفعّلة»');
            }
            $ids = array_map(fn ($r) => (int) $r['id'], $rows);
            $st = $pdo->prepare('SELECT COUNT(*) FROM subscriptions WHERE plan_id IN (?,?)');
            $st->execute($ids);
            if ((int) $st->fetchColumn() > 0) throw new InvalidArgumentException('عليها اشتراكات (حتى المنتهية)، اقفلها من «مفعّلة» بدل الحذف');
            $pdo->prepare('DELETE FROM subscription_plans WHERE id IN (?,?)')->execute($ids);
            $pdo->prepare('DELETE FROM plan_pricing_display WHERE plan_key = ?')->execute([$planKey]);
            $pdo->prepare('INSERT INTO plan_change_audit (plan_key, actor_id, changes_json) VALUES (?,?,?)')
                ->execute([$planKey, $actorId, json_encode(['deleted' => ['from' => 'موجودة', 'to' => 'اتحذفت']], JSON_UNESCAPED_UNICODE)]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function featureQuota($value): int
    {
        if ($value===true || $value===-1 || $value==='-1') return -1;
        if ($value===false || $value===null) return 0;
        return self::quota($value);
    }

    public static function features(array $row): array
    {
        $raw = json_decode((string) ($row['features_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($raw) || ($raw !== [] && array_keys($raw) === range(0, count($raw)-1))) throw new RuntimeException('Invalid billing features');
        $features = $raw;
        foreach (['ai_analysis'=>'ai_analysis_limit','chat_credits'=>'ai_message_limit','review_credits'=>'review_auto_reply_limit'] as $key=>$column) {
            $features[$key] = self::quota($row[$column] ?? 0);
        }
        $tier=preg_replace('/_(monthly|yearly)$/D','',(string)($row['plan_code']??$row['plan_name']??'starter'));
        $tier=isset(self::LEGACY_ALLOWANCES[$tier]) ? $tier : 'starter';
        $defaults=self::LEGACY_ALLOWANCES[$tier];
        $defaults['multiple_websites']=$row['websites_limit']??0;
        $defaults['team_members']=$row['team_members_limit']??0;
        $defaults['ai_assistant_messages']=$row['ai_message_limit']??$row['chat_credits']??0;
        foreach (array_merge(['competitor_analysis'=>0],$defaults) as $key=>$value) {
            $features[$key]=self::featureQuota(array_key_exists($key,$raw)?$raw[$key]:$value);
        }
        foreach (['whatsapp_bot','auto_pilot','advanced_analytics'] as $key) {
            $v = $raw[$key] ?? false;
            if (!in_array($v, [true,false,1,0,'1','0'], true)) throw new RuntimeException('Invalid billing flag');
            $features[$key] = $v === true || $v === 1 || $v === '1';
        }
        ksort($features);
        return $features;
    }

    public static function quota($value): int
    {
        if (!is_scalar($value) || !preg_match('/^\d+$/D', (string) $value) || (float) $value > 2147483647) throw new InvalidArgumentException('Invalid plan allowance');
        return (int) $value;
    }

    public static function price($value): float
    {
        if (!is_scalar($value) || !is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > 1000000000) throw new InvalidArgumentException('Invalid plan price');
        return (float) $value;
    }

    public static function currency($value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Z]{3}$/D', $value)) throw new InvalidArgumentException('Invalid plan currency');
        return $value;
    }

    public static function read(?Database $db = null): array
    {
        $pdo = ($db ?: Database::getInstance())->getConnection();
        $rows = $pdo->query('SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $labels = [];
        try {
            foreach ($pdo->query('SELECT plan_key, name, sort_order, id FROM plan_pricing_display ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC) as $r) $labels[$r['plan_key']] = $r;
        } catch (Throwable $e) { /* Labels are optional; prices never fall back to marketing data. */ }
        return self::fromRows($rows, $labels);
    }

    public static function fromRows(array $rows, array $labels = []): array
    {
        $groups = []; $invalid = [];
        foreach ($rows as $row) {
            if (!preg_match('/^([a-z0-9-]+)_(monthly|yearly)$/D', (string) ($row['plan_code'] ?? ''), $m)) continue;
            $key=$m[1]; $cycle=$m[2];
            if (($row['billing_cycle'] ?? '') !== $cycle || isset($groups[$key][$cycle])) $invalid[$key]=true;
            $groups[$key][$cycle]=$row;
        }
        $plans = [];
        foreach ($groups as $key=>$cycles) {
            try {
                // The legacy pricing UI shares one feature list between both cycles.
                // Do not advertise a missing or incompatible cycle as a zero-price offer.
                if (isset($invalid[$key]) || !isset($cycles['monthly'],$cycles['yearly'])) continue;
                $m=$cycles['monthly']; $y=$cycles['yearly'];
                $currency=self::currency($m['currency']);
                if ($currency !== self::currency($y['currency'])) continue;
                $features=self::features($m);
                if ($features !== self::features($y)) continue;
                $plans[$key]=['id'=>$key,'db_id'=>$labels[$key]['id']??null,'name'=>$labels[$key]['name']??$m['name']??$key,
                    'price_monthly'=>self::price($m['price']),'price_yearly'=>self::price($y['price']),
                    'currency'=>$currency,'currency_symbol'=>$currency.' ', 'features'=>$features,
                    'billing_ids'=>['monthly'=>(int)$m['id'],'yearly'=>(int)$y['id']]];
            } catch (Throwable $e) {
                if (class_exists('Logger')) Logger::warning('Billing plan omitted: invalid configuration', ['plan'=>$key]);
            }
        }
        uksort($plans, fn($a,$b)=>(int)($labels[$a]['sort_order']??999)<=>(int)($labels[$b]['sort_order']??999) ?: strcmp($a,$b));
        return $plans;
    }

    /** Called inside the admin transaction; errors roll back the display update too. */
    public static function syncDisplay(Database $db, array $plan, array $extra = []): void
    {
        $key=(string)($plan['plan_key']??'');
        if (!preg_match('/^[a-z0-9-]+$/D',$key)) throw new InvalidArgumentException('Invalid plan key');
        $pdo=$db->getConnection();
        $sql='SELECT * FROM subscription_plans WHERE plan_code IN (?, ?)';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $sql.=' FOR UPDATE';
        $stmt=$pdo->prepare($sql);$stmt->execute([$key.'_monthly',$key.'_yearly']);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)!==2) throw new RuntimeException('Both billing cycles must exist before updating a plan');
        $currency=self::currency((string)($plan['currency']??''));
        foreach ($rows as $row) {
            $cycle=str_ends_with($row['plan_code'],'_yearly')?'yearly':'monthly';
            if ($row['billing_cycle']!==$cycle) throw new RuntimeException('Billing cycle mismatch');
            $f=json_decode((string)($row['features_json']??'{}'),true,512,JSON_THROW_ON_ERROR);
            if (!is_array($f) || ($f!==[]&&array_keys($f) === range(0, count($f)-1))) throw new RuntimeException('Invalid billing feature configuration');
            foreach (['competitor_analysis','multiple_websites'] as $field) $f[$field]=self::quota($plan[$field]??0);
            foreach (['whatsapp_bot','auto_pilot','advanced_analytics'] as $field) {
                $v=$plan[$field]??0;
                if (!in_array($v,[0,1,'0','1',true,false],true)) throw new InvalidArgumentException('Invalid feature flag');
                $f[$field]=$v===1||$v==='1'||$v===true;
            }
            foreach (['ai_assistant_messages','website_generations','website_items','whatsapp_marketing_messages'] as $field) if (array_key_exists($field,$extra)) $f[$field]=self::featureQuota($extra[$field]);
            foreach (array_keys(self::SEO_LIMITS) as $field) if (array_key_exists($field,$extra)) $f[$field]=self::seoLimit($field,$extra[$field]);
            $active=$plan['is_active']??0;
            if (!in_array($active,[0,1,'0','1',true,false],true)) throw new InvalidArgumentException('Invalid active flag');
            $stmt=$pdo->prepare('UPDATE subscription_plans SET name=?, price=?, currency=?, ai_analysis_limit=?, ai_message_limit=?, review_auto_reply_limit=?, websites_limit=?, is_active=?, features_json=? WHERE id=?');
            $stmt->execute([(string)$plan['name'],self::price($plan['price_'.$cycle]),$currency,self::quota($plan['ai_analysis']),self::quota($plan['chat_credits']),self::quota($plan['review_credits']),self::quota($plan['multiple_websites']??0), (int)(bool)$active,json_encode($f,JSON_THROW_ON_ERROR),(int)$row['id']]);
        }
    }
}
