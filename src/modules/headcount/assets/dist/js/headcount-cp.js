/* Headcount CP Scripts */

(function () {
    'use strict';

    // Initialize when DOM is ready
    if (typeof Craft !== 'undefined') {
        Craft.Headcount = Craft.Headcount || {};

        /**
         * Plan editor gateway sync
         */
        Craft.Headcount.PlanEditor = Garnish.Base.extend({
            init: function (settings) {
                this.settings = settings || {};
            },
        });

        /**
         * Access rule type selector
         */
        Craft.Headcount.AccessRuleTypeSelector = Garnish.Base.extend({
            $typeSelect: null,
            $targetFields: null,

            init: function (typeSelectId) {
                this.$typeSelect = document.getElementById(typeSelectId);
                if (this.$typeSelect) {
                    this.$typeSelect.addEventListener('change', this.onTypeChange.bind(this));
                    this.onTypeChange();
                }
            },

            onTypeChange: function () {
                var type = this.$typeSelect ? this.$typeSelect.value : '';
                document.querySelectorAll('[data-target-type]').forEach(function (el) {
                    el.style.display = el.dataset.targetType === type ? '' : 'none';
                });
            },
        });
    }
})();
