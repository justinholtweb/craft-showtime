<?php

namespace justinholtweb\headcount\services;

use Craft;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\records\CouponRecord;
use yii\base\Component;

class Coupons extends Component
{
    public function getCouponById(int $id): ?CouponRecord
    {
        return CouponRecord::findOne($id);
    }

    public function getCouponByCode(string $code): ?CouponRecord
    {
        return CouponRecord::findOne(['code' => $code]);
    }

    public function getAllCoupons(): array
    {
        return CouponRecord::find()->orderBy('dateCreated DESC')->all();
    }

    public function saveCoupon(CouponRecord $coupon): bool
    {
        return $coupon->save();
    }

    public function deleteCouponById(int $id): bool
    {
        $record = CouponRecord::findOne($id);
        if (!$record) {
            return false;
        }

        $record->delete();
        return true;
    }

    public function validateCoupon(string $code, ?int $planId = null): array
    {
        $coupon = $this->getCouponByCode($code);

        if (!$coupon) {
            return ['valid' => false, 'error' => 'Coupon not found.'];
        }

        if (!$coupon->enabled) {
            return ['valid' => false, 'error' => 'Coupon is no longer active.'];
        }

        if ($coupon->expiresAt && strtotime($coupon->expiresAt) < time()) {
            return ['valid' => false, 'error' => 'Coupon has expired.'];
        }

        if ($coupon->maxUses && $coupon->currentUses >= $coupon->maxUses) {
            return ['valid' => false, 'error' => 'Coupon usage limit reached.'];
        }

        if ($planId && $coupon->planIds) {
            $applicablePlans = json_decode($coupon->planIds, true);
            if (!empty($applicablePlans) && !in_array($planId, $applicablePlans)) {
                return ['valid' => false, 'error' => 'Coupon is not valid for this plan.'];
            }
        }

        return [
            'valid' => true,
            'coupon' => $coupon,
            'discountType' => $coupon->discountType,
            'discountAmount' => (float)$coupon->discountAmount,
        ];
    }

    public function incrementUsage(string $code): void
    {
        Craft::$app->db->createCommand()
            ->update('{{%headcount_coupons}}', [
                'currentUses' => new \yii\db\Expression('[[currentUses]] + 1'),
            ], ['code' => $code])
            ->execute();
    }

    public function syncCouponToStripe(CouponRecord $coupon): void
    {
        if ($coupon->stripeCouponId) {
            return; // Already synced
        }

        $settings = Headcount::getInstance()->getSettings();
        if (!$settings->stripeEnabled) {
            return;
        }

        try {
            $stripeCoupon = Headcount::getInstance()->stripe->createCoupon(
                $coupon->code,
                $coupon->discountType,
                (float)$coupon->discountAmount,
                $settings->defaultCurrency
            );

            $coupon->stripeCouponId = $stripeCoupon->id;
            $coupon->save();
        } catch (\Throwable $e) {
            Craft::error('Failed to sync coupon to Stripe: ' . $e->getMessage(), 'headcount');
        }
    }
}
