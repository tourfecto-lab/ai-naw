<?php

/**
 * Tourfecto - Review Request Model
 * @version 1.0.0
 */
class ReviewRequest extends Model
{
    protected $table = 'review_requests';

    protected $fillable = [
        'user_id', 'website_id', 'guest_name', 'guest_phone', 'channel', 'guest_email',
        'service_end_date', 'delay_hours', 'review_link', 'destination_platform', 'status', 'scheduled_send_at',
        'sent_at', 'reminded_at', 'error_message', 'source', 'crm_deal_id',
        'matched_review_id', 'reviewed_at', 'created_at', 'attempts',
        'booking_id', 'track_token', 'clicked_at', 'click_count',
    ];

    /**
     * تايم لاين حقيقي مبني على الأعمدة الفعلية الموجودة بس - أي خطوة
     * مالهاش تاريخ حقيقي (مثلاً delivered/opened/clicked مش متوفرين من
     * مزود واتساب/إيميل الحاليين) بيتم تجاهلها تمامًا، مش عرضها فاضية.
     * @return array<int, array{event:string, at:?string}>
     */
    public function buildTimeline(): array
    {
        $timeline = [];

        $push = function (string $event, $at) use (&$timeline) {
            if (!empty($at)) {
                $timeline[] = ['event' => $event, 'at' => $at];
            }
        };

        $push('created', $this->getAttribute('created_at'));
        $push('scheduled', $this->getAttribute('scheduled_send_at'));
        $push('sent', $this->getAttribute('sent_at'));
        $push('reminded', $this->getAttribute('reminded_at'));
        $push('clicked', $this->getAttribute('clicked_at'));
        $push('reviewed', $this->getAttribute('reviewed_at'));

        if ($this->getAttribute('status') === 'failed' && $this->getAttribute('error_message')) {
            $push('failed', $this->getAttribute('sent_at') ?: $this->getAttribute('scheduled_send_at'));
        }
        if ($this->getAttribute('status') === 'opted_out') {
            // ما فيش عمود opted_out_at حاليًا؛ نعرض الحدث من غير وقت دقيق
            // بدل ما نخترع تاريخ، عشان نلتزم بقاعدة "لا تخترع بيانات".
            $timeline[] = ['event' => 'opted_out', 'at' => null];
        }

        usort($timeline, function ($a, $b) {
            if ($a['at'] === null) {
                return 1;
            }
            if ($b['at'] === null) {
                return -1;
            }
            return strtotime($a['at']) <=> strtotime($b['at']);
        });

        return $timeline;
    }
}
