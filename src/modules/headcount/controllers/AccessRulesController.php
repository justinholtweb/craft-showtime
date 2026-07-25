<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\AccessRule;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class AccessRulesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('headcount-manageAccessRules');
        return true;
    }

    public function actionIndex(): Response
    {
        $rules = Headcount::getInstance()->gating->getAllRules();

        return $this->renderTemplate('headcount/access-rules/index', [
            'rules' => $rules,
        ]);
    }

    public function actionEdit(?int $ruleId = null, ?AccessRule $rule = null): Response
    {
        if ($rule === null) {
            if ($ruleId !== null) {
                $rule = Headcount::getInstance()->gating->getRuleById($ruleId);
                if (!$rule) {
                    throw new NotFoundHttpException('Access rule not found');
                }
            } else {
                $rule = new AccessRule();
            }
        }

        $isNew = !$rule->id;

        $plans = Headcount::getInstance()->plans->getAllPlans();
        $planOptions = [];
        foreach ($plans as $plan) {
            $planOptions[] = ['label' => $plan->name, 'value' => $plan->id];
        }

        // What's gateable, and how each of those can be scoped. Entries come from
        // Headcount; everything else is registered by whoever owns that element type.
        //
        // Element type and scope are chosen with a single control whose value is
        // "<elementType>|<scope>". Two separate selects would need the second one's options
        // rebuilt in JS on every change of the first — and a scope key like `all` means
        // different things under different element types, so the pair is the real answer
        // anyway.
        $targets = Headcount::getInstance()->gating->getGateTargets();

        $gateOptions = [];
        foreach ($targets as $class => $target) {
            $gateOptions[] = ['optgroup' => $target->label];

            foreach ($target->scopes as $scope => $definition) {
                $gateOptions[] = [
                    'label' => $definition['label'],
                    'value' => self::gateKey($class, $scope),
                ];
            }
        }

        return $this->renderTemplate('headcount/access-rules/edit', [
            'rule' => $rule,
            'isNew' => $isNew,
            'targets' => $targets,
            'gateOptions' => $gateOptions,
            'gateKey' => self::gateKey($rule->elementType, $rule->type),
            'targetElement' => $rule->targetId
                ? Craft::$app->getElements()->getElementById($rule->targetId)
                : null,
            'title' => $isNew ? Craft::t('headcount', 'New Access Rule') : $rule->name,
            'planOptions' => $planOptions,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $ruleId = $request->getBodyParam('ruleId');

        if ($ruleId) {
            $rule = Headcount::getInstance()->gating->getRuleById($ruleId);
            if (!$rule) {
                throw new NotFoundHttpException('Access rule not found');
            }
        } else {
            $rule = new AccessRule();
        }

        $rule->name = $request->getBodyParam('name', $rule->name);

        $gate = (string)$request->getBodyParam('gate', '');
        if (str_contains($gate, '|')) {
            [$rule->elementType, $rule->type] = explode('|', $gate, 2);
        }

        $rule->targetId = $this->_postedTargetId($rule);
        $rule->planIds = $request->getBodyParam('planIds') ?: null;
        $rule->behavior = $request->getBodyParam('behavior', $rule->behavior);
        $rule->redirectUrl = $request->getBodyParam('redirectUrl', $rule->redirectUrl);
        $rule->teaserLength = $request->getBodyParam('teaserLength') ?: null;
        $rule->sortOrder = (int)$request->getBodyParam('sortOrder', $rule->sortOrder);
        $rule->enabled = (bool)$request->getBodyParam('enabled', $rule->enabled);

        if (!Headcount::getInstance()->gating->saveRule($rule)) {
            return $this->asFailure(
                Craft::t('headcount', 'Couldn\'t save access rule.'),
                ['rule' => $rule]
            );
        }

        return $this->asSuccess(
            Craft::t('headcount', 'Access rule saved.'),
            ['rule' => $rule],
            'headcount/access-rules/' . $rule->id
        );
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ruleId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        Headcount::getInstance()->gating->deleteRuleById($ruleId);

        return $this->asSuccess(Craft::t('headcount', 'Access rule deleted.'));
    }

    /**
     * The form field name identifying one element-type + scope pair.
     */
    public static function gateKey(string $elementType, string $scope): string
    {
        return $elementType . '|' . $scope;
    }

    /**
     * The target ID belonging to the scope the editor actually has selected.
     *
     * The editor renders one target input per scope and hides the rest — and a hidden
     * input still posts. When they all shared the name `targetId`, whichever appeared last
     * in the DOM won, so saving a "section" rule quietly stored the empty ID from the
     * "category" field and the rule matched nothing. Keying them by scope and reading back
     * the selected one is what makes the form mean what it shows.
     */
    private function _postedTargetId(AccessRule $rule): ?int
    {
        $target = Headcount::getInstance()->gating->getGateTarget($rule->elementType);

        if ($target === null || !$target->scopeNeedsTarget($rule->type)) {
            return null;
        }

        $posted = Craft::$app->getRequest()->getBodyParam('targetIds', []);
        $value = $posted[self::gateKey($rule->elementType, $rule->type)] ?? null;

        // Element-select fields post an array of IDs; we want the one.
        if (is_array($value)) {
            $value = reset($value);
        }

        return $value ? (int)$value : null;
    }
}
