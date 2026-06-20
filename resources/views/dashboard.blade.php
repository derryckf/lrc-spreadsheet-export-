@extends('layout')

@section('title', 'LRC Handicapping Pipeline')

@section('content')

{{-- ═══ Header ═══ --}}
<div class="ui segments">
    <div class="ui segment clearing">
        <h1 class="ui left floated header">
            <i class="stopwatch icon"></i>
            <div class="content">LRC Handicapping Pipeline</div>
        </h1>
        <div class="ui right floated basic button" onclick="$('#events-modal').modal('show');">
            <i class="list icon"></i> Recent Events
        </div>
    </div>
</div>

{{-- ═══ Pipeline Steps ═══ --}}
<div class="ui six top attached steps">
    <div class="step phase-tab" data-phase="1" style="cursor:pointer">
        <i class="file icon blue"></i>
        <div class="content"><div class="title">1. Parse</div><div class="description">TXT → CSV</div></div>
    </div>
    <div class="step phase-tab" data-phase="2" style="cursor:pointer">
        <i class="address book icon blue"></i>
        <div class="content"><div class="title">2. Resolve</div><div class="description">Identity match</div></div>
    </div>
    <div class="step phase-tab" data-phase="3" style="cursor:pointer">
        <i class="ticket icon blue"></i>
        <div class="content"><div class="title">3. Inject</div><div class="description">Season passes</div></div>
    </div>
    <div class="step phase-tab" data-phase="4" style="cursor:pointer">
        <i class="calculator icon blue"></i>
        <div class="content"><div class="title">4. Process</div><div class="description">Compute stats</div></div>
    </div>
    <div class="step phase-tab" data-phase="5" style="cursor:pointer">
        <i class="file excel outline icon blue"></i>
        <div class="content"><div class="title">5. Export</div><div class="description">XLSX → Drive</div></div>
    </div>
    <div class="step phase-tab" data-phase="6" style="cursor:pointer">
        <i class="upload icon blue"></i>
        <div class="content"><div class="title">6. Import</div><div class="description">Read results</div></div>
    </div>
</div>

{{-- ═══ Phase Panels ═══ --}}
<div class="ui attached segment" id="phase-panels">

    {{-- Phase 1: Parse --}}
    <div class="phase-panel" id="panel-1">
        <h3 class="ui header"><i class="file icon"></i> Parse Webscorer TXT</h3>
        <div class="ui form">
            <div class="two fields">
                <div class="field">
                    <label>Registration File</label>
                    <div class="ui top attached tabular menu" id="parse-tabs">
                        <a class="active item" data-tab="path"><i class="folder icon"></i> Local Path</a>
                        <a class="item" data-tab="upload"><i class="upload icon"></i> Upload File</a>
                    </div>
                    <div class="ui bottom attached tab segment active" data-tab="path">
                        <input type="text" id="parse-file" placeholder="registrations/LRC 2026 Race N - Venue.txt">
                    </div>
                    <div class="ui bottom attached tab segment" data-tab="upload">
                        <input type="file" id="parse-upload" accept=".txt">
                    </div>
                </div>
                <div class="field">
                    <label>Identity Name (optional)</label>
                    <input type="text" id="parse-name" placeholder="raceN_venue">
                </div>
            </div>
            <button class="ui primary button run-btn" data-endpoint="/run/parse" data-phase="1">
                <i class="play icon"></i> Parse
            </button>
        </div>
    </div>

    {{-- Phase 2: Resolve --}}
    <div class="phase-panel" id="panel-2" style="display:none">
        <h3 class="ui header"><i class="address book icon"></i> Resolve Identities</h3>
        <div class="ui message info">
            <p>Run once per event/division. MemberCreator uses the <b>event distance</b>, not the CSV distance.</p>
        </div>
        <div class="ui form">
            <div class="three fields">
                <div class="field">
                    <label>Event ID</label>
                    <input type="number" id="resolve-event-id" placeholder="e.g. 1871">
                </div>
                <div class="field">
                    <label>CSV File</label>
                    <input type="text" id="resolve-csv" placeholder="path/to/long_course.csv">
                </div>
                <div class="field">
                    <label>Mode</label>
                    <select class="ui dropdown" id="resolve-mode">
                        <option value="skip">Skip unknowns</option>
                        <option value="interactive">Interactive</option>
                    </select>
                </div>
            </div>
            <button class="ui primary button run-btn" data-endpoint="/run/resolve" data-phase="2">
                <i class="play icon"></i> Resolve
            </button>
        </div>
    </div>

    {{-- Phase 3: Inject --}}
    <div class="phase-panel" id="panel-3" style="display:none">
        <h3 class="ui header"><i class="ticket icon"></i> Inject Season-Pass Holders</h3>
        <div class="ui message info">
            <p>Adds everyEvent members who didn't register via Webscorer. Run <b>after</b> resolve, <b>before</b> process.</p>
        </div>
        <div class="ui form">
            <div class="two fields">
                <div class="field">
                    <label>Event IDs (space or comma separated)</label>
                    <input type="text" id="inject-event-ids" placeholder="e.g. 1871 1872 1873">
                </div>
                <div class="field">
                    <label>Season (optional)</label>
                    <input type="number" id="inject-season" placeholder="2026" value="2026">
                </div>
            </div>
            <button class="ui primary button run-btn" data-endpoint="/run/inject" data-phase="3">
                <i class="play icon"></i> Inject
            </button>
        </div>
    </div>

    {{-- Phase 4: Process --}}
    <div class="phase-panel" id="panel-4" style="display:none">
        <h3 class="ui header"><i class="calculator icon"></i> Process Stats</h3>
        <div class="ui form">
            <div class="field">
                <label>Event ID</label>
                <input type="number" id="process-event-id" placeholder="e.g. 1871">
            </div>
            <button class="ui primary button run-btn" data-endpoint="/run/process" data-phase="4">
                <i class="play icon"></i> Process
            </button>
            <button class="ui basic button" onclick="quickProcess()">
                <i class="magic icon"></i> Process 3 events
            </button>
        </div>
    </div>

    {{-- Phase 5: Export --}}
    <div class="phase-panel" id="panel-5" style="display:none">
        <h3 class="ui header"><i class="file excel outline icon"></i> Export Spreadsheet</h3>
        <div class="ui form">
            <div class="two fields">
                <div class="field">
                    <label>Event ID (Long Course)</label>
                    <input type="number" id="export-event-id" placeholder="e.g. 1871">
                </div>
                <div class="field">
                    <label>Upload to Drive</label>
                    <div class="ui toggle checkbox">
                        <input type="checkbox" id="export-gdrive" checked>
                        <label>Google Drive</label>
                    </div>
                </div>
            </div>
            <button class="ui primary button run-btn" data-endpoint="/run/export" data-phase="5">
                <i class="play icon"></i> Export
            </button>
        </div>
    </div>

    {{-- Phase 6: Import --}}
    <div class="phase-panel" id="panel-6" style="display:none">
        <h3 class="ui header"><i class="upload icon"></i> Import Results</h3>
        <div class="ui form">
            <div class="two fields">
                <div class="field">
                    <label>Event ID (Long Course)</label>
                    <input type="number" id="import-event-id" placeholder="e.g. 1871">
                </div>
                <div class="field">
                    <label>Download from Drive</label>
                    <div class="ui toggle checkbox">
                        <input type="checkbox" id="import-gdrive" checked>
                        <label>Google Drive</label>
                    </div>
                </div>
            </div>
            <button class="ui primary button run-btn" data-endpoint="/run/import" data-phase="6">
                <i class="play icon"></i> Import
            </button>
        </div>
    </div>

</div>

{{-- ═══ Output Console ═══ --}}
<div class="ui bottom attached segment" style="margin-top: 1.5rem;">
    <h4 class="ui header">
        <i class="terminal icon"></i>
        Output Console
        <button class="ui tiny basic right floated button" onclick="clearConsole()">
            <i class="eraser icon"></i> Clear
        </button>
    </h4>
    <div class="console-output" id="console">
        <span class="log-debug">$ Ready. Click a phase above to begin.</span>
    </div>
</div>

{{-- ═══ Events Modal ═══ --}}
<div class="ui modal" id="events-modal">
    <i class="close icon"></i>
    <div class="header">Recent Events</div>
    <div class="content">
        <table class="ui celled striped table">
            <thead>
                <tr><th>ID</th><th>Date</th><th>Div</th><th>Distance</th><th>Venue</th><th>Entries</th></tr>
            </thead>
            <tbody>
                @foreach($events as $ev)
                <tr class="event-row" onclick="lookupEntries({{ $ev->id }})">
                    <td>{{ $ev->id }}</td>
                    <td>{{ $ev->eventDate }}</td>
                    <td>{{ $ev->division }}</td>
                    <td>{{ $ev->distance }}km</td>
                    <td>{{ $ev->venue ?? '—' }}</td>
                    <td>{{ $ev->entries ?? 0 }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@endsection

@section('scripts')
<script>
$(function() {
    $('.ui.dropdown').dropdown();
    $('.ui.checkbox').checkbox();
    $('#parse-tabs .item').tab();

    // Phase tab switching
    $('.phase-tab').click(function() {
        var phase = $(this).data('phase');
        $('.phase-tab').removeClass('active');
        $(this).addClass('active');
        $('.phase-panel').hide();
        $('#panel-' + phase).fadeIn(150);
    });
    $('.phase-tab').first().trigger('click');

    // Run button handler
    $('.run-btn').click(function() {
        var endpoint = $(this).data('endpoint');
        var phase = $(this).data('phase');
        var result = collectFormData(phase);
        if (result === null) return;

        var $btn = $(this);
        $btn.addClass('loading disabled');
        appendConsole('$ Running phase ' + phase + '...', 'debug');

        var ajaxOpts = {
            url: endpoint,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            dataType: 'json',
        };

        if (result instanceof FormData) {
            ajaxOpts.data = result;
            ajaxOpts.processData = false;
            ajaxOpts.contentType = false;
        } else {
            ajaxOpts.data = result;
        }

        $.ajax(ajaxOpts).done(function(resp) {
            if (resp.output) appendConsole(resp.output, 'info');
            if (resp.error) appendConsole(resp.error, 'error');
            appendConsole('$ Exit code: ' + resp.exit_code, resp.exit_code === 0 ? 'info' : 'warn');
            if (resp.exit_code === 0) {
                $btn.closest('.phase-panel').find('.ui.header').append('<i class="green check icon"></i>');
            }
        }).fail(function(xhr) {
            appendConsole('AJAX error: ' + (xhr.responseText || 'unknown'), 'error');
        }).always(function() {
            $btn.removeClass('loading disabled');
        });
    });
});

function collectFormData(phase) {
    switch(phase) {
        case 1:
            var parseMode = $('#parse-tabs .active.item').data('tab');
            if (parseMode === 'upload') {
                if (!$('#parse-upload')[0].files[0]) { alert('Select a file to upload'); return null; }
                var formData = new FormData();
                formData.append('upload', $('#parse-upload')[0].files[0]);
                formData.append('name', $('#parse-name').val());
                return formData;
            }
            if (!$('#parse-file').val()) { alert('Enter a file path'); return null; }
            return { file: $('#parse-file').val(), name: $('#parse-name').val() };
        case 2:
            if (!$('#resolve-event-id').val() || !$('#resolve-csv').val()) { alert('Enter event ID and CSV path'); return null; }
            return { event_id: $('#resolve-event-id').val(), csv: $('#resolve-csv').val(), mode: $('#resolve-mode').val() };
        case 3:
            if (!$('#inject-event-ids').val()) { alert('Enter event IDs'); return null; }
            return { event_ids: $('#inject-event-ids').val(), season: $('#inject-season').val() };
        case 4:
            if (!$('#process-event-id').val()) { alert('Enter event ID'); return null; }
            return { event_id: $('#process-event-id').val() };
        case 5:
            if (!$('#export-event-id').val()) { alert('Enter event ID'); return null; }
            return { event_id: $('#export-event-id').val(), gdrive: $('#export-gdrive').is(':checked') };
        case 6:
            if (!$('#import-event-id').val()) { alert('Enter event ID'); return null; }
            return { event_id: $('#import-event-id').val(), gdrive: $('#import-gdrive').is(':checked') };
    }
    return null;
}

function quickProcess() {
    var ids = prompt('Enter 3 event IDs (long, short, junior):', '1871 1872 1873');
    if (!ids) return;
    var arr = ids.split(/[\s,]+/).filter(function(v) { return v.trim(); });
    var chain = arr.slice();
    (function next() {
        if (chain.length === 0) { appendConsole('$ All events processed.', 'info'); return; }
        var id = chain.shift();
        appendConsole('$ Processing event ' + id + '...', 'debug');
        $.post('/run/process', { event_id: id, _token: $('meta[name="csrf-token"]').attr('content') })
            .done(function(resp) {
                if (resp.output) appendConsole(resp.output, 'info');
                next();
            }).fail(function(xhr) { appendConsole('Error: ' + xhr.responseText, 'error'); });
    })();
}

function appendConsole(text, level) {
    level = level || 'info';
    var cls = 'log-' + level;
    var lines = text.split('\n');
    var html = lines.map(function(l) {
        if (l.match(/\bERROR\b/i)) cls = 'log-error';
        else if (l.match(/\bWARN/i)) cls = 'log-warn';
        return '<span class="' + cls + '">' + escapeHtml(l) + '</span>';
    }).join('\n');
    $('#console').append(html + '\n');
    $('#console').scrollTop($('#console')[0].scrollHeight);
}

function clearConsole() {
    $('#console').html('<span class="log-debug">$ Console cleared.</span>\n');
}

function lookupEntries(eventId) {
    $.get('/event/' + eventId + '/entries', function(resp) {
        var html = '<table class="ui very compact celled table"><thead><tr>' +
            '<th>Name</th><th>regNo</th><th>Pace</th><th>Time</th><th>Method</th><th>daysSince</th><th>lastWin</th></tr></thead><tbody>';
        resp.entries.forEach(function(e) {
            html += '<tr><td>' + e.firstName + ' ' + e.lastName + '</td><td>' + e.regNo + '</td>' +
                '<td>' + (e.expectedPace || '—') + '</td><td>' + (e.expectedTime || '—') + '</td>' +
                '<td>' + (e.method || '—') + '</td><td>' + (e.daysSince ?? '—') + '</td>' +
                '<td>' + (e.lastWin ?? '—') + '</td></tr>';
        });
        html += '</tbody></table>';
        $('#events-modal .content').html('<h4>Event ' + resp.event.id + ' — ' +
            resp.event.eventDate + ' (Div ' + resp.event.division + ', ' + resp.event.distance + 'km)</h4>' + html);
    });
}

function escapeHtml(s) {
    return s.replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}
</script>

<meta name="csrf-token" content="{{ csrf_token() }}">
@endsection
