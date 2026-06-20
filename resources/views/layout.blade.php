<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'LRC Handicapping')</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.css">
    <style>
        body { background: #f7f8fa; }
        .pipeline-container { max-width: 1400px; margin: 0 auto; padding: 1.5rem; }
        .console-output {
            background: #1e1e1e; color: #d4d4d4;
            font-family: 'SF Mono', 'Monaco', 'Courier New', monospace;
            font-size: 13px; line-height: 1.6;
            padding: 1rem; border-radius: 4px;
            max-height: 400px; overflow-y: auto;
            white-space: pre-wrap; word-break: break-all;
        }
        .console-output .log-info { color: #4ec9b0; }
        .console-output .log-warn { color: #dcdcaa; }
        .console-output .log-error { color: #f44747; }
        .console-output .log-debug { color: #569cd6; }
        .phase-card { transition: all 0.2s; }
        .phase-card.active { border-color: #2185d0 !important; box-shadow: 0 0 0 1px #2185d0; }
        .phase-card.completed { border-left: 4px solid #21ba45; }
        .event-row { cursor: pointer; }
        .event-row:hover { background: #f0f7ff; }
    </style>
</head>
<body>
<div class="pipeline-container">
    @yield('content')
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/semantic-ui@2.5.0/dist/semantic.min.js"></script>
@yield('scripts')
</body>
</html>
