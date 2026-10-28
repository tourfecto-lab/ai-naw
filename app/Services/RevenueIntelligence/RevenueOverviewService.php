<?php

/**
 * Tourfecto - Revenue Overview Service
 * @version 1.0.0
 *
 * يغطي:
 *  - Section 1: REVENUE OVERVIEW (Total/Recurring/One-time/Growth/Trend/By Period/By Source)
 *  - Section 7: REVENUE BY SOURCE
 *  - Section 8: REVENUE BY PRODUCT/SERVICE (Tourfecto لا يملك جدول
 *    منتجات/خدمات مرتبط بالإيراد فعليًا - نرجع "Not enough data" بدل
 *    اختراع بيانات، بدل ما نعيد استخدام "source" كبديل مضلل لـ"منتج").
 */
class RevenueOverviewService
{
    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    /** يحوّل period (daily/weekly/monthly/quarterly/yearly) لعدد أيام الفترة المطلوبة. */
    public static function periodToDays(string $period): int
    {
        switch ($period) {
            case 'daily': return 1;
            case 'weekly': return 7;
            case 'monthly': return 30;
            case 'quarterly': return 90;
            case 'yearly': return 365;
            default: return 30;
        }
    }

    /**
     * نظرة عامة كاملة على الإيرادات لفترة معيّنة + مقارنة بالفترة السابقة
     * لنفس الطول (Revenue Growth).
     */
    public function getOverview(int $userId, string $period = 'monthly'): array
    {
        $days = self::periodToDays($period);
        $now = new DateTime('now');
        $currentEnd = clone $now;
        $currentStart = (clone $now)->modify("-{$days} days");
        $previousEnd = clone $currentStart;
        $previousStart = (clone $currentStart)->modify("-{$days} days");

        $currentTotals = $this->gateway->getRevenueTotals($userId, $currentStart->format('Y-m-d H:i:s'), $currentEnd->format('Y-m-d H:i:s'));
        $previousTotals = $this->gateway->getRevenueTotals($userId, $previousStart->format('Y-m-d H:i:s'), $previousEnd->format('Y-m-d H:i:s'));

        if($currentTotals['mixed_currency']||$previousTotals['mixed_currency']||($currentTotals['currency']&&$previousTotals['currency']&&$currentTotals['currency']!==$previousTotals['currency'])) {
            return ['period'=>$period,'range'=>['from'=>$currentStart->format('Y-m-d'),'to'=>$currentEnd->format('Y-m-d')],'total_revenue'=>null,'average_revenue'=>null,'previous_period_revenue'=>null,'growth_percent'=>null,'growth_trend'=>'unknown','revenue_records_count'=>$currentTotals['count'],'recurring_revenue'=>null,'one_time_revenue'=>null,'revenue_by_source'=>[],'daily_trend'=>[],'marketing_spend'=>null,'roas'=>null,'has_data'=>$currentTotals['count']>0,'currency'=>null,'totals_by_currency'=>$currentTotals['totals_by_currency'],'mixed_currency_warning'=>'Currencies cannot be combined or compared without verified exchange rates. Totals are shown separately by currency.'];
        }
        $growthPct = null;
        if ($previousTotals['total'] > 0) {
            $growthPct = round((($currentTotals['total'] - $previousTotals['total']) / $previousTotals['total']) * 100, 2);
        }
        if ($previousTotals['total'] <= 0) {
            $growthPct = null;
        }

        $bySource = $this->gateway->getRevenueBySource($userId, $currentStart->format('Y-m-d H:i:s'), $currentEnd->format('Y-m-d H:i:s'));
        $dailySeries = $this->gateway->getDailyRevenueSeries($userId, $currentStart->format('Y-m-d H:i:s'), $currentEnd->format('Y-m-d H:i:s'));
        $spendTotal = $this->gateway->getMarketingSpendTotal($userId, $currentStart->format('Y-m-d H:i:s'), $currentEnd->format('Y-m-d H:i:s'));

        $recurring = $this->getRecurringRevenue($userId);
        // MRR is a monthly run rate, not cash received in this reporting period.
        $oneTime = null;

        $average = $currentTotals['count'] > 0 ? round($currentTotals['total'] / $currentTotals['count'], 2) : null;

        return [
            'period' => $period,
            'range' => ['from' => $currentStart->format('Y-m-d'), 'to' => $currentEnd->format('Y-m-d')],
            'total_revenue' => round($currentTotals['total'], 2),
            'revenue_records_count' => $currentTotals['count'],
            'average_revenue' => $average,
            'previous_period_revenue' => round($previousTotals['total'], 2),
            'growth_percent' => $growthPct,
            'growth_note' => $previousTotals['total'] <= 0 ? 'Percentage growth is unavailable when the previous period is zero or negative.' : null,
            'one_time_revenue_note' => 'Cash revenue is not split using monthly recurring run rate.',
            'growth_trend' => $growthPct === null ? 'unknown' : ($growthPct > 0.5 ? 'up' : ($growthPct < -0.5 ? 'down' : 'flat')),
            'recurring_revenue' => $recurring,
            'one_time_revenue' => $currentTotals['count'] > 0 ? $oneTime : null,
            'revenue_by_source' => array_map(static function ($r) {
                return ['source' => $r['source'], 'total' => round((float) $r['total'], 2), 'count' => (int) $r['count']];
            }, $bySource),
            'daily_trend' => $dailySeries,
            'marketing_spend' => $spendTotal===null?null:round($spendTotal,2),
            'roas' => $spendTotal > 0 ? round($currentTotals['total'] / $spendTotal, 2) : null,
            'has_data' => $currentTotals['count'] > 0,
            'currency' => $currentTotals['currency'],
            'spend_currency_warning'=>$spendTotal===null?'Spending currency is unknown or cannot be compared with revenue; ROAS is unavailable.':null,
            'mixed_currency_warning' => $currentTotals['mixed_currency']
                ? 'Revenue records use more than one currency in this period. total_revenue is a raw sum across currencies (no exchange-rate conversion is available) and should not be treated as accurate until this is resolved.'
                : null,
        ];
    }

    /**
     * Recurring Revenue من جدول subscriptions الحقيقي *عندما تتوفر بيانات*.
     * لا نخترع MRR لو الجدول غير موجود/فاضي.
     */
    public function getRecurringRevenue(int $userId): array
    {
        $rows = $this->gateway->getCustomerRecurringRevenue($userId);
        if ($rows === null) return ['available'=>false,'reason'=>'Customer subscriptions are not configured','monthly_recurring_revenue'=>null,'active_subscriptions'=>null,'source'=>'customer_subscriptions'];
        $totals=[];
        foreach ($rows as $row) {
            $currency=strtoupper((string)$row['currency']);
            $totals[$currency]=round(($totals[$currency]??0)+(float)$row['mrr'],2);
        }
        return ['available'=>true,'monthly_recurring_revenue'=>count($totals)===1?reset($totals):(count($totals)===0?0:null),'active_subscriptions'=>count($rows),'totals_by_currency'=>$totals,'currency'=>count($totals)===1?array_key_first($totals):null,'source'=>'customer_subscriptions','reason'=>count($totals)>1?'Currencies are shown separately':null];
    }

    /** Section 7: Revenue by Source بمقارنة نمو كل مصدر مقابل الفترة السابقة. */
    public function getRevenueBySourceWithGrowth(int $userId, string $period = 'monthly'): array
    {
        $days = self::periodToDays($period);
        $now = new DateTime('now');
        $currentStart = (clone $now)->modify("-{$days} days");
        $previousStart = (clone $currentStart)->modify("-{$days} days");

        $ct=$this->gateway->getRevenueTotals($userId,$currentStart->format('Y-m-d H:i:s'),$now->format('Y-m-d H:i:s'));$pt=$this->gateway->getRevenueTotals($userId,$previousStart->format('Y-m-d H:i:s'),$currentStart->format('Y-m-d H:i:s'));
        if($ct['mixed_currency']||$pt['mixed_currency']||($ct['currency']&&$pt['currency']&&$ct['currency']!==$pt['currency']))return ['has_data'=>false,'message'=>'Currency comparison unavailable','sources'=>[]];
        $current = $this->indexBySource($this->gateway->getRevenueBySource($userId, $currentStart->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')));
        $previous = $this->indexBySource($this->gateway->getRevenueBySource($userId, $previousStart->format('Y-m-d H:i:s'), $currentStart->format('Y-m-d H:i:s')));

        if (empty($current)) {
            return ['has_data' => false, 'message' => 'Not enough data', 'sources' => []];
        }

        $out = [];
        foreach ($current as $source => $data) {
            $prevTotal = $previous[$source]['total'] ?? 0.0;
            $growth = $prevTotal > 0 ? round((($data['total'] - $prevTotal) / $prevTotal) * 100, 2) : null;
            $out[] = [
                'source' => $source,
                'revenue' => round($data['total'], 2),
                'customers' => null, // لا يوجد ربط عميل<->سجل إيراد حاليًا (rev_revenue_records بدون contact_id)
                'conversion' => null, // Attribution حقيقي غير متاح - لن نخترعه
                'revenue_growth_percent' => $growth,
            ];
        }
        usort($out, static function ($a, $b) {
            return $b['revenue'] <=> $a['revenue'];
        });

        return ['has_data' => true, 'sources' => $out];
    }

    /**
     * Section 8: الإيراد حسب المنتج/الخدمة (G2).
     * كان يرجع "Not enough data" صراحة لعدم وجود كتالوج منتجات مرتبط.
     * من 2026-08-29 أضفنا بُعد المنتج الاختياري (product_name/category)
     * على rev_revenue_records، فيتجمع الإيراد هنا حسب المنتج أولًا، ثم
     * التصنيف، مع fallback آمن للمصدر للمسجلات اللي مالهاش بُعد منتج.
     * لو مفيش أي سجل ببيانات منتج → نرجع "Not enough data" بنفس الصدق.
     */
    public function getRevenueByProduct(int $userId): array
    {
        $from = date('Y-m-01 00:00:00', strtotime('-6 months'));
        $rows = $this->gateway->getRevenueRecordsWithProduct($userId, $from, date('Y-m-d 23:59:59'));

        $groups = [];
        $hasProductData = false;
        $totalRevenue = 0.0;

        foreach ($rows as $r) {
            $product = trim((string) ($r['product_name'] ?? ''));
            $category = trim((string) ($r['category'] ?? ''));
            $dimension = 'product';
            $label = $product;
            if ($label === '') {
                $label = $category;
                $dimension = $category !== '' ? 'category' : 'source';
            }
            if ($label === '') {
                $label = (string) ($r['source'] ?? 'manual');
            }
            if ($dimension !== 'source') {
                $hasProductData = true;
            }

            $key = $dimension . ':' . $label;
            if (!isset($groups[$key])) {
                $groups[$key] = ['label' => $label, 'dimension' => $dimension, 'revenue' => 0.0, 'count' => 0];
            }
            $groups[$key]['revenue'] += (float) ($r['amount'] ?? 0);
            $groups[$key]['count']++;
            $totalRevenue += (float) ($r['amount'] ?? 0);
        }

        if (empty($groups)) {
            return [
                'has_data' => false,
                'message' => 'Not enough data for reliable Revenue by Product/Service. No revenue records in the last 6 months carry a product dimension.',
            ];
        }

        $out = array_values($groups);
        usort($out, static function ($a, $b) {
            return $b['revenue'] <=> $a['revenue'];
        });
        foreach ($out as $i => $item) {
            $out[$i]['share_percent'] = $totalRevenue > 0 ? round(($item['revenue'] / $totalRevenue) * 100, 1) : 0.0;
        }

        return [
            'has_data' => $hasProductData,
            'products' => $out,
            'total_revenue' => round($totalRevenue, 2),
            'note' => $hasProductData
                ? 'Revenue grouped by optional product_name/category on rev_revenue_records (records without a product dimension fall back to source).'
                : 'No product dimension recorded yet — records are shown grouped by source only. Set product/category when adding revenue for full Product views.',
        ];
    }

    private function indexBySource(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['source']] = ['total' => (float) $r['total'], 'count' => (int) $r['count']];
        }
        return $out;
    }
}
