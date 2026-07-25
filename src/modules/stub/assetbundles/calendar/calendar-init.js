(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var calendarEl = document.getElementById('stub-calendar-view');
        if (!calendarEl) return;

        var providerFilter = document.getElementById('stub-provider-filter');

        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'timeGridWeek',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay',
            },
            slotMinTime: '06:00:00',
            slotMaxTime: '22:00:00',
            nowIndicator: true,
            allDaySlot: false,
            height: 'auto',
            events: function (fetchInfo, successCallback, failureCallback) {
                var url = Craft.getActionUrl('stub/calendar/events', {
                    start: fetchInfo.startStr,
                    end: fetchInfo.endStr,
                    providerId: providerFilter ? providerFilter.value : '',
                });

                fetch(url, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) { successCallback(data.events || []); })
                    .catch(function (err) { failureCallback(err); });
            },
            eventClick: function (info) {
                if (info.event.url) {
                    window.location.href = info.event.url;
                    info.jsEvent.preventDefault();
                }
            },
        });

        calendar.render();

        if (providerFilter) {
            providerFilter.addEventListener('change', function () {
                calendar.refetchEvents();
            });
        }
    });
})();
