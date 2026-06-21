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
        <div class="ui right floated basic button" onclick="openEventBrowser(null);">
            <i class="list icon"></i> Browse Events
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

{{-- Hidden CSV file inputs for browse buttons --}}
<input type="file" id="browse-csv-long"   accept=".csv" style="display:none" onchange="handleCsvBrowse(this, 'long')">
<input type="file" id="browse-csv-short"  accept=".csv" style="display:none" onchange="handleCsvBrowse(this, 'short')">
<input type="file" id="browse-csv-junior" accept=".csv" style="display:none" onchange="handleCsvBrowse(this, 'junior')">

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
                        <a class="active item" data-tab="path"><i class="folder open icon"></i> Browse Local</a>
                        <a class="item" data-tab="upload"><i class="upload icon"></i> Upload & Keep</a>
                    </div>
                    <div class="ui bottom attached tab segment active" data-tab="path">
                        <input type="file" id="parse-file" name="file" accept=".txt">
                    </div>
                    <div class="ui bottom attached tab segment" data-tab="upload">
                        <input type="file" id="parse-upload" name="upload" accept=".txt">
                    </div>
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
            <p>Run once per event/division. MemberCreator uses the <b>event distance</b>, not the CSV distance. Split the parsed CSV by distance first, then resolve each.</p>
        </div>
        <div class="ui form">
            <div class="ui secondary menu" style="margin-bottom:1rem">
                <div class="item" style="padding-left:0">
                    <button class="ui tiny primary button" id="btn-auto-detect" onclick="autoDetectResolve()">
                        <i class="magic icon"></i> Auto-detect
                    </button>
                </div>
                <div class="item">
                    <button class="ui tiny basic button" onclick="openEventBrowser('long')">
                        <i class="search icon"></i> Browse Events
                    </button>
                </div>
            </div>
            <input type="hidden" id="resolve-csv-path" value="">
            <div class="three fields">
                <div class="field">
                    <label>Long Course Event ID</label>
                    <div class="ui action input">
                        <input type="number" id="resolve-event-long" placeholder="e.g. 1871">
                        <button class="ui icon button" onclick="openEventBrowser('long')" tabindex="-1"><i class="search icon"></i></button>
                    </div>
                </div>
                <div class="field">
                    <label>Short Course Event ID</label>
                    <div class="ui action input">
                        <input type="number" id="resolve-event-short" placeholder="e.g. 1872">
                        <button class="ui icon button" onclick="openEventBrowser('short')" tabindex="-1"><i class="search icon"></i></button>
                    </div>
                </div>
                <div class="field">
                    <label>Junior Event ID</label>
                    <div class="ui action input">
                        <input type="number" id="resolve-event-junior" placeholder="e.g. 1873">
                        <button class="ui icon button" onclick="openEventBrowser('junior')" tabindex="-1"><i class="search icon"></i></button>
                    </div>
                </div>
            </div>
            <div class="three fields">
                <div class="field">
                    <label>Long Course CSV</label>
                    <div class="ui action input">
                        <input type="text" id="resolve-csv-long" placeholder="Auto-filled after Parse" readonly>
                        <button class="ui icon button" onclick="browseCsv('long')" tabindex="-1"><i class="folder open icon"></i></button>
                    </div>
                </div>
                <div class="field">
                    <label>Short Course CSV</label>
                    <div class="ui action input">
                        <input type="text" id="resolve-csv-short" placeholder="Auto-filled after Parse" readonly>
                        <button class="ui icon button" onclick="browseCsv('short')" tabindex="-1"><i class="folder open icon"></i></button>
                    </div>
                </div>
                <div class="field">
                    <label>Junior CSV</label>
                    <div class="ui action input">
                        <input type="text" id="resolve-csv-junior" placeholder="Auto-filled after Parse" readonly>
                        <button class="ui icon button" onclick="browseCsv('junior')" tabindex="-1"><i class="folder open icon"></i></button>
                    </div>
                </div>
            </div>
            <div class="field" style="margin-top:0.5rem">
                <label style="display:inline;margin-right:1rem">Mode</label>
                <select class="ui dropdown" id="resolve-mode" style="display:inline-block;width:auto">
                    <option value="skip">Skip unknowns</option>
                    <option value="interactive">Interactive</option>
                </select>
            </div>
            <div style="margin-top:0.75rem; display:flex; gap:0.5rem; align-items:center">
                <button class="ui primary button resolve-single-btn" data-target="long" data-endpoint="/run/resolve">
                    <i class="play icon"></i> Resolve Long
                </button>
                <button class="ui primary button resolve-single-btn" data-target="short" data-endpoint="/run/resolve">
                    <i class="play icon"></i> Resolve Short
                </button>
                <button class="ui primary button resolve-single-btn" data-target="junior" data-endpoint="/run/resolve">
                    <i class="play icon"></i> Resolve Junior
                </button>
                <div style="flex:1"></div>
                <button class="ui green button resolve-all-btn" data-endpoint="/run/resolve" data-phase="2">
                    <i class="step forward icon"></i> Resolve All 3
                </button>
            </div>
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
    <div class="header">
        <span id="events-modal-title">Browse Events</span>
        <div class="ui right floated small action input" style="margin-left:auto;width:320px">
            <input type="date" id="event-search-date" style="width:130px" onchange="searchEvents(event)" placeholder="Date">
            <input type="text" id="event-search-q" placeholder="Venue..." onkeyup="searchEvents(event)" style="width:130px">
            <select class="ui compact dropdown" id="event-search-div" onchange="searchEvents(event)" style="min-width:5em">
                <option value="">All divs</option>
                <option value="1">Div 1</option>
                <option value="2">Div 2</option>
                <option value="3">Div 3</option>
            </select>
            <button class="ui button" onclick="searchEvents(event)"><i class="search icon"></i></button>
        </div>
    </div>
    <div class="content" style="max-height:70vh;overflow-y:auto">
        <table class="ui celled striped table">
            <thead>
                <tr><th>ID</th><th>Date</th><th>Div</th><th>Distance</th><th>Venue</th><th>Entries</th><th></th></tr>
            </thead>
            <tbody id="events-modal-body">
                <tr><td colspan="7" class="center aligned">Loading...</td></tr>
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

    // Generic run-btn handler (phases 1, 3, 4, 5, 6)
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
                // Auto-fill Resolve CSV fields from split results (phase 1)
                if (phase == 1 && resp.splits && !resp.splits.error) {
                    var splits = resp.splits;
                    if (splits.long)   { $('#resolve-csv-long').val(splits.long);   appendConsole('$ Long CSV: '   + splits.long   + ' (' + (splits.counts&&splits.counts.long||'?')   + ' rows)', 'info'); }
                    if (splits.short)  { $('#resolve-csv-short').val(splits.short);  appendConsole('$ Short CSV: '  + splits.short  + ' (' + (splits.counts&&splits.counts.short||'?')  + ' rows)', 'info'); }
                    if (splits.junior) { $('#resolve-csv-junior').val(splits.junior); appendConsole('$ Junior CSV: ' + splits.junior + ' (' + (splits.counts&&splits.counts.junior||'?') + ' rows)', 'info'); }
                }
            }
        }).fail(function(xhr) {
            appendConsole('AJAX error: ' + (xhr.responseText || 'unknown'), 'error');
        }).always(function() {
            $btn.removeClass('loading disabled');
        });
    });

    // Individual Resolve Long / Short / Junior buttons
    $('.resolve-single-btn').click(function() {
        var target = $(this).data('target');
        var eventId = $('#resolve-event-' + target).val();
        var csv     = $('#resolve-csv-' + target).val();
        var mode    = $('#resolve-mode').val();
        var label   = target.charAt(0).toUpperCase() + target.slice(1);

        if (!eventId || !csv) {
            appendConsole('$ Fill in event ID and CSV for ' + label + ' first.', 'warn');
            return;
        }

        var $btn = $(this);
        $btn.addClass('loading disabled');
        appendConsole('$ Resolving ' + label + ' (event ' + eventId + ')...', 'debug');

        $.ajax({
            url: '/run/resolve',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            dataType: 'json',
            data: { event_id: eventId, csv: csv, mode: mode },
        }).done(function(resp) {
            if (resp.output) appendConsole(resp.output, 'info');
            if (resp.error) appendConsole(resp.error, 'error');
            appendConsole('$ [' + label + '] Exit: ' + resp.exit_code, resp.exit_code === 0 ? 'info' : 'warn');
        }).fail(function(xhr) {
            appendConsole('AJAX error: ' + (xhr.responseText || 'unknown'), 'error');
        }).always(function() {
            $btn.removeClass('loading disabled');
        });
    });

    // Resolve All 3 button
    $('.resolve-all-btn').click(function() {
        var ops = collectFormData(2); // returns array of {event_id, csv, mode, label}
        if (!ops || ops.length === 0) {
            appendConsole('$ Fill in at least one event ID + CSV pair first.', 'warn');
            return;
        }
        var $btn = $(this);
        $btn.addClass('loading disabled');
        chainResolveOperations(ops, $btn);
    });
});

function collectFormData(phase) {
    switch(phase) {
        case 1:
            var parseMode = $('#parse-tabs .active.item').data('tab');
            var fileInput = (parseMode === 'upload') ? $('#parse-upload')[0] : $('#parse-file')[0];
            if (!fileInput.files[0]) {
                alert('Select a file first');
                return null;
            }
            var formData = new FormData();
            formData.append('file', fileInput.files[0]);
            formData.append('name', $('#parse-name').val());
            return formData;
        case 2:
            // Collect all 3 resolve operations; each must have event_id + csv
            var longId = $('#resolve-event-long').val();
            var shortId = $('#resolve-event-short').val();
            var juniorId = $('#resolve-event-junior').val();
            var longCsv = $('#resolve-csv-long').val();
            var shortCsv = $('#resolve-csv-short').val();
            var mode = $('#resolve-mode').val();
            // Return array of {event_id, csv, mode} for chained execution
            var ops = [];
            if (longId && longCsv) ops.push({ event_id: longId, csv: longCsv, mode: mode, label: 'Long Course' });
            if (shortId && shortCsv) ops.push({ event_id: shortId, csv: shortCsv, mode: mode, label: 'Short Course' });
            if (juniorId && $('#resolve-csv-junior').val()) ops.push({ event_id: juniorId, csv: $('#resolve-csv-junior').val(), mode: mode, label: 'Junior' });
            if (ops.length === 0) { alert('Fill in at least one event ID + CSV pair'); return null; }
            return ops;
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

// ─── Chain resolve operations ─────────────────────────────────────────────────

function chainResolveOperations(ops, $btn) {
    appendConsole('$ Running ' + ops.length + ' resolve operation(s)...', 'debug');
    (function next() {
        if (ops.length === 0) {
            appendConsole('$ All resolve operations complete.', 'info');
            $btn.removeClass('loading disabled');
            return;
        }
        var op = ops.shift();
        appendConsole('$ Resolving ' + op.label + ' (event ' + op.event_id + ')...', 'debug');
        $.ajax({
            url: '/run/resolve',
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            dataType: 'json',
            data: { event_id: op.event_id, csv: op.csv, mode: op.mode },
        }).done(function(resp) {
            if (resp.output) appendConsole(resp.output, 'info');
            if (resp.error) appendConsole(resp.error, 'error');
            appendConsole('$ [' + op.label + '] Exit: ' + resp.exit_code, resp.exit_code === 0 ? 'info' : 'warn');
            next();
        }).fail(function(xhr) {
            appendConsole('AJAX error on ' + op.label + ': ' + (xhr.responseText || 'unknown'), 'error');
            next();
        });
    })();
}

// ─── Event browser ────────────────────────────────────────────────────────────

var _eventBrowserTarget = null; // 'long', 'short', or 'junior'

function openEventBrowser(target) {
    _eventBrowserTarget = target;
    var title = target === 'long' ? 'Long Course Events' : target === 'short' ? 'Short Course Events' : target === 'junior' ? 'Junior Events' : 'Browse Events';
    $('#events-modal-title').text(title);
    $('#event-search-q').val('');
    $('#event-search-date').val('');
    $('#event-search-div').val('');
    $('#events-modal').modal('show');
    fetchEvents();
}

function fetchEvents() {
    $('#events-modal-body').html('<tr><td colspan="7" class="center aligned">Loading...</td></tr>');
    var q = $('#event-search-q').val().trim();
    var date = $('#event-search-date').val();
    var div = $('#event-search-div').val();
    var params = [];
    if (q) params.push('q=' + encodeURIComponent(q));
    if (date) params.push('date=' + encodeURIComponent(date));
    if (div) params.push('division=' + encodeURIComponent(div));
    var url = '/api/events' + (params.length ? '?' + params.join('&') : '');
    $.getJSON(url, function(resp) {
        if (resp.error) {
            $('#events-modal-body').html('<tr><td colspan="7" class="red text">Error: ' + escapeHtml(resp.error) + '</td></tr>');
            return;
        }
        renderEventRows(resp.events || []);
    }).fail(function() {
        $('#events-modal-body').html('<tr><td colspan="7" class="red text">Failed to load events.</td></tr>');
    });
}

function searchEvents(e) {
    // Trigger on Enter key in text fields, or any change in dropdowns/date
    if (e && e.key && e.key !== 'Enter' && e.target.id !== 'event-search-date' && e.type !== 'change') return;
    fetchEvents();
}

function renderEventRows(events) {
    if (events.length === 0) {
        $('#events-modal-body').html('<tr><td colspan="7" class="center aligned">No events found.</td></tr>');
        return;
    }
    var html = '';
    events.forEach(function(ev) {
        html += '<tr>' +
            '<td>' + ev.id + '</td>' +
            '<td>' + ev.eventDate + '</td>' +
            '<td>' + ev.division + '</td>' +
            '<td>' + ev.distance + 'km</td>' +
            '<td>' + (ev.venue || '—') + '</td>' +
            '<td>' + (ev.entries || 0) + '</td>' +
            '<td><button class="ui mini compact button" onclick="selectEvent(' + ev.id + ')">Use</button></td>' +
            '</tr>';
    });
    $('#events-modal-body').html(html);
}

function selectEvent(id) {
    if (!_eventBrowserTarget) return;
    $('#events-modal').modal('hide');
    $('#resolve-event-' + _eventBrowserTarget).val(id);
}

// ─── CSV file browser ────────────────────────────────────────────────────────

function browseCsv(target) {
    $('#browse-csv-' + target).click();
}

function handleCsvBrowse(input, target) {
    if (!input.files[0]) return;
    // Extract filename and search for it in known locations
    var filename = input.files[0].name;
    appendConsole('$ Selected: ' + filename, 'debug');
    // Try to resolve to full relative path from project root
    // Check storage/app/handicapping/ and registrations/ dirs
    $.post('/api/resolve-csv-path', { filename: filename, _token: $('meta[name="csrf-token"]').attr('content') },
        function(resp) {
            if (resp.path) {
                $('#resolve-csv-' + target).val(resp.path);
                appendConsole('$ CSV set to: ' + resp.path, 'info');
            } else {
                appendConsole('$ Could not locate ' + filename + ' — please enter path manually.', 'warn');
            }
        }, 'json'
    ).fail(function() {
        appendConsole('$ Could not locate ' + filename + ' — please enter path manually.', 'warn');
    });
}

function autoDetectResolve() {
    var csvPath = $('#resolve-csv-path').val();
    if (!csvPath) {
        // Try to find the last parsed CSV from registrations/
        var files = [];
        try {
            // Use the parse name if provided, otherwise use a glob pattern
            var name = $('#parse-name').val().trim();
            // The parse output goes to storage/app/handicapping/
            // If we don't know the name, we can't auto-detect
            if (!name) {
                appendConsole('$ Auto-detect: enter an Identity Name in Phase 1 first, then run Parse.', 'warn');
                return;
            }
            csvPath = 'storage/app/handicapping/' + name + '.csv';
        } catch(e) {}
    }

    if (!csvPath) {
        appendConsole('$ Auto-detect: run Parse first to generate a CSV.', 'warn');
        return;
    }

    appendConsole('$ Auto-detecting events from: ' + csvPath, 'debug');
    $('#btn-auto-detect').addClass('loading disabled');

    $.post('/api/events/auto-detect',
        { csv_path: csvPath, _token: $('meta[name="csrf-token"]').attr('content') },
        function(resp) {
            $('#btn-auto-detect').removeClass('loading disabled');
            if (resp.error) {
                appendConsole('$ Auto-detect error: ' + resp.error, 'error');
                return;
            }
            var events = resp.events || [];
            var distances = resp.distances || [];
            appendConsole('$ Found distances: ' + distances.join('km, ') + 'km', 'info');

            if (events.length === 0) {
                appendConsole('$ No matching events found for those distances.', 'warn');
                return;
            }

            // Group events by distance
            var byDist = {};
            events.forEach(function(ev) {
                var d = parseFloat(ev.distance);
                if (!byDist[d]) byDist[d] = [];
                byDist[d].push(ev);
            });

            // Auto-fill fields: pick best match per distance
            // Convention: 8km+ = long, 2-5km = short, <2km = junior
            Object.keys(byDist).forEach(function(dist) {
                var evList = byDist[dist];
                if (evList.length === 0) return;
                var ev = evList[0]; // most recent
                var d = parseFloat(dist);
                var csvPathForDist = csvPath; // user should split; auto-detect just fills event IDs
                if (d >= 5) {
                    $('#resolve-event-long').val(ev.id);
                    if (!$('#resolve-csv-long').val()) $('#resolve-csv-long').val(csvPath);
                    appendConsole('$ Long Course → Event ' + ev.id + ' (' + ev.eventDate + ', ' + ev.distance + 'km)', 'info');
                } else if (d >= 1.6) {
                    $('#resolve-event-short').val(ev.id);
                    if (!$('#resolve-csv-short').val()) $('#resolve-csv-short').val(csvPath);
                    appendConsole('$ Short Course → Event ' + ev.id + ' (' + ev.eventDate + ', ' + ev.distance + 'km)', 'info');
                } else {
                    $('#resolve-event-junior').val(ev.id);
                    appendConsole('$ Junior → Event ' + ev.id + ' (' + ev.eventDate + ', ' + ev.distance + 'km)', 'info');
                }
            });
        }, 'json'
    ).fail(function(xhr) {
        $('#btn-auto-detect').removeClass('loading disabled');
        appendConsole('$ Auto-detect failed: ' + (xhr.responseText || 'unknown'), 'error');
    });
}
</script>

<meta name="csrf-token" content="{{ csrf_token() }}">
@endsection
