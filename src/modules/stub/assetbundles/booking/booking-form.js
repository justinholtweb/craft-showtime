(function () {
    'use strict';

    class StubBookingForm {
        constructor(el) {
            this.el = el;
            this.currentStep = 0;
            this.totalSteps = 6;
            this.data = {
                serviceId: null,
                serviceName: '',
                serviceDuration: 0,
                servicePrice: 0,
                serviceCurrency: 'USD',
                providerId: null,
                providerName: '',
                date: null,
                time: null,
                timeDisplay: '',
                startDateTimeUtc: null,
                firstName: '',
                lastName: '',
                email: '',
                phone: '',
                customerNotes: '',
                bookingId: null,
                referenceNumber: '',
                requiresPayment: false,
            };
            this.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
            this.calendarYear = new Date().getFullYear();
            this.calendarMonth = new Date().getMonth() + 1;
            this.availableDates = [];
            this.csrfToken = this.el.dataset.csrf || '';
            this.stripeKey = this.el.dataset.stripeKey || '';
            this.stripe = null;
            this.stripeElements = null;

            this.init();
        }

        init() {
            this.bindServiceCards();
            this.updateProgress();
        }

        // Navigation
        goToStep(step) {
            this.currentStep = step;
            this.el.querySelectorAll('.stub-step').forEach((s, i) => {
                s.classList.toggle('active', i === step);
            });
            this.updateProgress();
        }

        nextStep() {
            this.goToStep(this.currentStep + 1);
        }

        prevStep() {
            if (this.currentStep > 0) {
                this.goToStep(this.currentStep - 1);
            }
        }

        updateProgress() {
            this.el.querySelectorAll('.stub-progress-step').forEach((s, i) => {
                s.classList.toggle('active', i <= this.currentStep);
            });
        }

        // Step 1: Services
        bindServiceCards() {
            this.el.querySelectorAll('.stub-service-card').forEach(card => {
                card.addEventListener('click', () => {
                    this.el.querySelectorAll('.stub-service-card').forEach(c => c.classList.remove('selected'));
                    card.classList.add('selected');
                    this.data.serviceId = parseInt(card.dataset.id);
                    this.data.serviceName = card.dataset.name;
                    this.data.serviceDuration = parseInt(card.dataset.duration);
                    this.data.servicePrice = parseFloat(card.dataset.price);
                    this.data.serviceCurrency = card.dataset.currency;
                    this.loadProviders();
                });
            });
        }

        // Step 2: Providers
        async loadProviders() {
            this.nextStep();
            const container = this.el.querySelector('#stub-providers');
            container.innerHTML = '<div class="stub-loading"><span class="stub-spinner"></span></div>';

            const resp = await this.fetchJson(`/actions/stub/availability/get-providers?serviceId=${this.data.serviceId}`);
            if (!resp.providers || resp.providers.length === 0) {
                container.innerHTML = '<p>No providers available for this service.</p>';
                return;
            }

            // Built as DOM nodes rather than an HTML string: provider names and bios are
            // authored in the control panel by anyone with `stub:manageProviders`, and this
            // form is public, so a name like `" onmouseover="…` interpolated into an
            // attribute was stored XSS against every visitor. textContent and dataset encode
            // correctly for text and attribute contexts alike.
            container.replaceChildren(...resp.providers.map(p => {
                const card = document.createElement('div');
                card.className = 'stub-provider-card';
                card.dataset.id = p.id;
                card.dataset.name = p.name;

                const name = document.createElement('div');
                name.className = 'stub-provider-name';
                name.textContent = p.name;
                card.append(name);

                if (p.bio) {
                    const bio = document.createElement('div');
                    bio.className = 'stub-provider-bio';
                    bio.textContent = p.bio;
                    card.append(bio);
                }

                card.addEventListener('click', () => {
                    container.querySelectorAll('.stub-provider-card').forEach(c => c.classList.remove('selected'));
                    card.classList.add('selected');
                    this.data.providerId = parseInt(card.dataset.id);
                    this.data.providerName = card.dataset.name;
                    this.loadCalendar();
                });

                return card;
            }));
        }

        // Step 3: Date & Time
        async loadCalendar() {
            this.nextStep();
            await this.renderCalendar();
        }

        async renderCalendar() {
            const container = this.el.querySelector('#stub-calendar');
            container.innerHTML = '<div class="stub-loading"><span class="stub-spinner"></span></div>';

            const resp = await this.fetchJson(
                `/actions/stub/availability/get-dates?serviceId=${this.data.serviceId}&providerId=${this.data.providerId}&year=${this.calendarYear}&month=${this.calendarMonth}&timezone=${this.timezone}`
            );
            this.availableDates = resp.dates || [];

            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];
            const dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

            const firstDay = new Date(this.calendarYear, this.calendarMonth - 1, 1).getDay();
            const daysInMonth = new Date(this.calendarYear, this.calendarMonth, 0).getDate();

            let html = '<div class="stub-calendar">';
            html += '<div class="stub-calendar-header">';
            html += `<button type="button" id="stub-prev-month">&larr;</button>`;
            html += `<strong>${monthNames[this.calendarMonth - 1]} ${this.calendarYear}</strong>`;
            html += `<button type="button" id="stub-next-month">&rarr;</button>`;
            html += '</div>';
            html += '<div class="stub-calendar-grid">';

            dayLabels.forEach(d => {
                html += `<div class="stub-calendar-day-label">${d}</div>`;
            });

            for (let i = 0; i < firstDay; i++) {
                html += '<div class="stub-calendar-day other-month"></div>';
            }

            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${this.calendarYear}-${String(this.calendarMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const isAvailable = this.availableDates.includes(dateStr);
                const isSelected = this.data.date === dateStr;
                const cls = ['stub-calendar-day'];
                if (isAvailable) cls.push('available');
                if (isSelected) cls.push('selected');
                html += `<div class="${cls.join(' ')}" data-date="${dateStr}">${day}</div>`;
            }

            html += '</div></div>';
            html += '<div id="stub-slots"></div>';

            container.innerHTML = html;

            // Bind navigation
            container.querySelector('#stub-prev-month').addEventListener('click', () => {
                this.calendarMonth--;
                if (this.calendarMonth < 1) { this.calendarMonth = 12; this.calendarYear--; }
                this.renderCalendar();
            });

            container.querySelector('#stub-next-month').addEventListener('click', () => {
                this.calendarMonth++;
                if (this.calendarMonth > 12) { this.calendarMonth = 1; this.calendarYear++; }
                this.renderCalendar();
            });

            // Bind date clicks
            container.querySelectorAll('.stub-calendar-day.available').forEach(dayEl => {
                dayEl.addEventListener('click', () => {
                    container.querySelectorAll('.stub-calendar-day').forEach(d => d.classList.remove('selected'));
                    dayEl.classList.add('selected');
                    this.data.date = dayEl.dataset.date;
                    this.loadSlots();
                });
            });

            // If date was previously selected, reload slots
            if (this.data.date && this.availableDates.includes(this.data.date)) {
                this.loadSlots();
            }
        }

        async loadSlots() {
            const slotsContainer = this.el.querySelector('#stub-slots');
            slotsContainer.innerHTML = '<div class="stub-loading"><span class="stub-spinner"></span></div>';

            const resp = await this.fetchJson(
                `/actions/stub/availability/get-slots?serviceId=${this.data.serviceId}&providerId=${this.data.providerId}&date=${this.data.date}&timezone=${this.timezone}`
            );

            const slots = resp.slots || [];
            if (slots.length === 0) {
                slotsContainer.innerHTML = '<p>No available times on this date.</p>';
                return;
            }

            // Server-formatted strings, but built as nodes for the same reason as the
            // provider cards — nothing from a response gets interpolated into markup.
            const slotsWrapper = document.createElement('div');
            slotsWrapper.className = 'stub-slots';

            slotsWrapper.append(...slots.map(s => {
                const slot = document.createElement('div');
                slot.className = 'stub-slot';
                slot.dataset.time = s.time;
                slot.dataset.utc = s.utc;
                slot.dataset.display = s.display;
                slot.textContent = s.display;

                slot.addEventListener('click', () => {
                    slotsContainer.querySelectorAll('.stub-slot').forEach(el => el.classList.remove('selected'));
                    slot.classList.add('selected');
                    this.data.time = slot.dataset.time;
                    this.data.timeDisplay = slot.dataset.display;
                    this.data.startDateTimeUtc = slot.dataset.utc;

                    // Enable next button
                    const btn = this.el.querySelector('#stub-datetime-next');
                    if (btn) btn.disabled = false;
                });

                return slot;
            }));

            slotsContainer.replaceChildren(slotsWrapper);
        }

        // Step 4: Customer Info
        validateInfo() {
            let valid = true;
            const fields = ['firstName', 'lastName', 'email'];
            const requirePhone = this.el.dataset.requirePhone === '1';
            if (requirePhone) fields.push('phone');

            fields.forEach(f => {
                const input = this.el.querySelector(`[name="stub-${f}"]`);
                const errorEl = input?.parentElement?.querySelector('.stub-error');
                if (!input.value.trim()) {
                    valid = false;
                    if (errorEl) errorEl.textContent = 'This field is required.';
                } else if (f === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value)) {
                    valid = false;
                    if (errorEl) errorEl.textContent = 'Please enter a valid email.';
                } else {
                    if (errorEl) errorEl.textContent = '';
                }
            });

            if (valid) {
                this.data.firstName = this.el.querySelector('[name="stub-firstName"]').value.trim();
                this.data.lastName = this.el.querySelector('[name="stub-lastName"]').value.trim();
                this.data.email = this.el.querySelector('[name="stub-email"]').value.trim();
                this.data.phone = this.el.querySelector('[name="stub-phone"]')?.value.trim() || '';
                this.data.customerNotes = this.el.querySelector('[name="stub-customerNotes"]')?.value.trim() || '';
            }

            return valid;
        }

        // Step 5: Submit & Payment
        async submitBooking() {
            const body = {
                serviceId: this.data.serviceId,
                providerId: this.data.providerId,
                startDateTime: this.data.startDateTimeUtc,
                timezone: this.timezone,
                email: this.data.email,
                firstName: this.data.firstName,
                lastName: this.data.lastName,
                phone: this.data.phone,
                customerNotes: this.data.customerNotes,
            };

            // Forward any honeypot field present in the form (server controls the field name).
            this.el.querySelectorAll('[aria-hidden="true"] input').forEach(input => {
                if (input.name && !(input.name in body)) {
                    body[input.name] = input.value;
                }
            });

            const resp = await this.fetchJson('/actions/stub/booking-form/submit', {
                method: 'POST',
                body,
            });

            if (!resp.success) {
                alert(resp.error || 'An error occurred.');
                return;
            }

            this.data.bookingId = resp.bookingId;
            this.data.referenceNumber = resp.referenceNumber;
            this.data.paymentToken = resp.paymentToken;
            this.data.requiresPayment = resp.requiresPayment;

            if (resp.requiresPayment) {
                this.initPayment();
            } else {
                this.showConfirmation();
            }
        }

        async initPayment() {
            if (!this.stripeKey) {
                this.showConfirmation();
                return;
            }

            this.goToStep(4);

            if (!this.stripe) {
                this.stripe = Stripe(this.stripeKey);
            }

            const resp = await this.fetchJson('/actions/stub/payment/create-intent', {
                method: 'POST',
                body: {
                    bookingId: this.data.bookingId,
                    paymentToken: this.data.paymentToken,
                },
            });

            if (!resp.clientSecret) {
                alert('Payment initialization failed.');
                return;
            }

            const appearance = { theme: 'stripe' };
            this.stripeElements = this.stripe.elements({ clientSecret: resp.clientSecret, appearance });
            const paymentElement = this.stripeElements.create('payment');
            paymentElement.mount('#stub-payment-element');
        }

        async confirmPayment() {
            if (!this.stripe || !this.stripeElements) return;

            const btn = this.el.querySelector('#stub-pay-btn');
            btn.disabled = true;
            btn.textContent = 'Processing...';

            const { error } = await this.stripe.confirmPayment({
                elements: this.stripeElements,
                confirmParams: {
                    return_url: window.location.href + '?stub_booking=' + this.data.referenceNumber,
                },
            });

            if (error) {
                const errorEl = this.el.querySelector('#stub-payment-errors');
                if (errorEl) errorEl.textContent = error.message;
                btn.disabled = false;
                btn.textContent = 'Pay';
            }
        }

        showConfirmation() {
            this.goToStep(5);
            const container = this.el.querySelector('#stub-confirmation');
            if (container) {
                container.querySelector('.stub-ref').textContent = this.data.referenceNumber;
                container.querySelector('#stub-confirm-service').textContent = this.data.serviceName;
                container.querySelector('#stub-confirm-provider').textContent = this.data.providerName;
                container.querySelector('#stub-confirm-date').textContent = this.data.date;
                container.querySelector('#stub-confirm-time').textContent = this.data.timeDisplay;
            }
        }

        // Utilities
        async fetchJson(url, options = {}) {
            const headers = {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            };

            let fetchOptions = { headers };

            if (options.method === 'POST') {
                headers['Content-Type'] = 'application/json';
                const body = options.body || {};
                body[window.Craft?.csrfTokenName || 'CRAFT_CSRF_TOKEN'] = this.csrfToken;
                fetchOptions.method = 'POST';
                fetchOptions.body = JSON.stringify(body);
            }

            const response = await fetch(url, fetchOptions);
            return response.json();
        }

    }

    // Auto-init
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.stub-booking-form').forEach(el => {
            const form = new StubBookingForm(el);

            // Wire up buttons
            el.querySelectorAll('[data-stub-action]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const action = btn.dataset.stubAction;
                    switch (action) {
                        case 'prev': form.prevStep(); break;
                        case 'next-datetime':
                            if (form.data.date && form.data.time) form.nextStep();
                            break;
                        case 'submit-info':
                            if (form.validateInfo()) form.submitBooking();
                            break;
                        case 'pay':
                            form.confirmPayment();
                            break;
                    }
                });
            });

            // Check for return from Stripe
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('redirect_status') === 'succeeded') {
                form.goToStep(5);
                const ref = urlParams.get('stub_booking') || '';
                const refEl = el.querySelector('.stub-ref');
                if (refEl) refEl.textContent = ref;
            }
        });
    });
})();
