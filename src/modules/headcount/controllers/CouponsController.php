<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\records\CouponRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class CouponsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('headcount-manageCoupons');
        return true;
    }

    public function actionIndex(): Response
    {
        $coupons = Headcount::getInstance()->coupons->getAllCoupons();

        return $this->renderTemplate('headcount/coupons/index', [
            'coupons' => $coupons,
        ]);
    }

    public function actionEdit(?int $couponId = null, ?CouponRecord $coupon = null): Response
    {
        if ($coupon === null) {
            if ($couponId !== null) {
                $coupon = Headcount::getInstance()->coupons->getCouponById($couponId);
                if (!$coupon) {
                    throw new NotFoundHttpException('Coupon not found');
                }
            } else {
                $coupon = new CouponRecord();
            }
        }

        $isNew = !$coupon->id;

        $plans = Headcount::getInstance()->plans->getAllPlans();
        $planOptions = [];
        foreach ($plans as $plan) {
            $planOptions[] = ['label' => $plan->name, 'value' => $plan->id];
        }

        return $this->renderTemplate('headcount/coupons/edit', [
            'coupon' => $coupon,
            'isNew' => $isNew,
            'title' => $isNew ? Craft::t('headcount', 'New Coupon') : $coupon->code,
            'planOptions' => $planOptions,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $couponId = $request->getBodyParam('couponId');

        if ($couponId) {
            $coupon = Headcount::getInstance()->coupons->getCouponById($couponId);
            if (!$coupon) {
                throw new NotFoundHttpException('Coupon not found');
            }
        } else {
            $coupon = new CouponRecord();
        }

        $coupon->code = $request->getBodyParam('code', $coupon->code);
        $coupon->description = $request->getBodyParam('description', $coupon->description);
        $coupon->discountType = $request->getBodyParam('discountType', $coupon->discountType ?? 'percent');
        $coupon->discountAmount = (float)$request->getBodyParam('discountAmount', $coupon->discountAmount);
        $coupon->maxUses = $request->getBodyParam('maxUses') ?: null;
        $coupon->expiresAt = $request->getBodyParam('expiresAt') ?: null;
        $coupon->enabled = (bool)$request->getBodyParam('enabled', $coupon->enabled ?? true);

        $planIds = $request->getBodyParam('planIds');
        $coupon->planIds = $planIds ? json_encode($planIds) : null;

        if (!Headcount::getInstance()->coupons->saveCoupon($coupon)) {
            return $this->asFailure(
                Craft::t('headcount', 'Couldn\'t save coupon.'),
                ['coupon' => $coupon]
            );
        }

        // Sync to Stripe
        Headcount::getInstance()->coupons->syncCouponToStripe($coupon);

        return $this->asSuccess(
            Craft::t('headcount', 'Coupon saved.'),
            ['coupon' => $coupon],
            'headcount/coupons/' . $coupon->id
        );
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $couponId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        Headcount::getInstance()->coupons->deleteCouponById($couponId);

        return $this->asSuccess(Craft::t('headcount', 'Coupon deleted.'));
    }
}
