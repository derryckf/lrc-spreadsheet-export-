<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\Process\Process;

class PipelineController extends Controller
{
    private function cliBasePath(): string
    {
        return base_path('cli.php');
    }

    /**
     * Run a CLI command and return output + exit code.
     */
    private function runCli(array $args): array
    {
        $cmd = array_merge(['php', $this->cliBasePath()], $args);
        $process = new Process($cmd, base_path());
        $process->setTimeout(300);

        // Inherit DB env vars so cli.php can connect
        $process->run(null, [
            'DB_HOST'     => config('database.connections.mysql.host'),
            'DB_PORT'     => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => config('database.connections.mysql.database'),
            'DB_USERNAME' => config('database.connections.mysql.username'),
            'DB_PASSWORD' => config('database.connections.mysql.password'),
        ]);

        // Strip ANSI colour codes for clean output
        $output = preg_replace('/\033\[[0-9;]*m/', '', $process->getOutput());

        return [
            'output'    => $output,
            'error'     => preg_replace('/\033\[[0-9;]*m/', '', $process->getErrorOutput()),
            'exit_code' => $process->getExitCode(),
        ];
    }

    /**
     * Dashboard — pipeline overview.
     */
    public function index(): View
    {
        $recentEvents = $this->getRecentEvents();
        return view('dashboard', ['events' => $recentEvents]);
    }

    /**
     * Phase 1: webscorer:parse
     * Accepts either a local file path (text) or a file upload.
     */
    public function runParse(Request $request): JsonResponse
    {
        $filePath = null;

        // Handle file upload
        if ($request->hasFile('upload')) {
            $uploaded = $request->file('upload');
            $dest = base_path('registrations/' . $uploaded->getClientOriginalName());
            $uploaded->move(dirname($dest), basename($dest));
            $filePath = 'registrations/' . $uploaded->getClientOriginalName();
        } elseif ($request->filled('file')) {
            $filePath = $request->input('file');
        }

        if (!$filePath) {
            return response()->json([
                'error' => 'No file provided — enter a path or upload a file.',
                'exit_code' => 1,
            ], 422);
        }

        $args = ['webscorer:parse', $filePath];
        if ($request->filled('name')) {
            $args[] = '--name=' . $request->input('name');
        }

        return response()->json($this->runCli($args));
    }

    /**
     * Phase 2: webscorer:resolve
     */
    public function runResolve(Request $request): JsonResponse
    {
        $request->validate([
            'event_id' => 'required|integer',
            'csv'      => 'required|string',
            'mode'     => 'nullable|in:skip,interactive',
        ]);

        $args = ['webscorer:resolve', (string)$request->integer('event_id'), $request->input('csv')];
        $args[] = $request->input('mode', 'skip') === 'interactive' ? '--interactive' : '--skip-unknowns';

        return response()->json($this->runCli($args));
    }

    /**
     * Phase 3: event:inject-season-pass
     */
    public function runInject(Request $request): JsonResponse
    {
        $request->validate([
            'event_ids' => 'required|string',
            'season'    => 'nullable|integer',
        ]);

        $args = ['event:inject-season-pass'];
        foreach (preg_split('/[\s,]+/', trim($request->input('event_ids'))) as $id) {
            if (ctype_digit($id)) {
                $args[] = $id;
            }
        }
        if ($request->filled('season')) {
            $args[] = '--season=' . $request->integer('season');
        }

        return response()->json($this->runCli($args));
    }

    /**
     * Phase 4: handicapper:process
     */
    public function runProcess(Request $request): JsonResponse
    {
        $request->validate([
            'event_id' => 'required|integer',
        ]);

        return response()->json($this->runCli(['handicapper:process', (string)$request->integer('event_id')]));
    }

    /**
     * Phase 5: handicapper:export
     */
    public function runExport(Request $request): JsonResponse
    {
        $request->validate([
            'event_id' => 'required|integer',
            'gdrive'   => 'nullable|boolean',
        ]);

        $args = ['handicapper:export', (string)$request->integer('event_id'), '--all-divisions'];
        if ($request->boolean('gdrive')) {
            $args[] = '--gdrive';
        }

        return response()->json($this->runCli($args));
    }

    /**
     * Phase 6: handicapper:import
     */
    public function runImport(Request $request): JsonResponse
    {
        $request->validate([
            'event_id' => 'required|integer',
            'gdrive'   => 'nullable|boolean',
        ]);

        $args = ['handicapper:import', (string)$request->integer('event_id')];
        if ($request->boolean('gdrive')) {
            $args[] = '--gdrive';
        }

        return response()->json($this->runCli($args));
    }

    /**
     * List recent events (for dropdowns/reference).
     */
    public function events(): JsonResponse
    {
        return response()->json($this->getRecentEvents(20));
    }

    /**
     * Show entries for an event.
     */
    public function eventEntries(int $id): JsonResponse
    {
        $entries = DB::select(<<<'SQL'
            SELECT ee.member_id, m.firstName, m.lastName, m.regNo,
                   ee.expectedPace, ee.expectedTime, ee.method,
                   ee.daysSince, ee.lastWin, ee.paid, ee.handicap
            FROM eventEntry ee
            JOIN member m ON ee.member_id = m.id
            WHERE ee.event_id = ?
            ORDER BY m.lastName, m.firstName
        SQL, [$id]);

        $event = DB::selectOne('SELECT id, eventDate, division, distance, venue_id FROM event WHERE id = ?', [$id]);

        return response()->json(['event' => $event, 'entries' => $entries]);
    }

    /**
     * Get recent events from DB.
     */
    private function getRecentEvents(int $limit = 6): array
    {
        try {
            return DB::select(<<<'SQL'
                SELECT e.id, e.eventDate, e.division, e.distance, v.name AS venue,
                       (SELECT COUNT(*) FROM eventEntry ee WHERE ee.event_id = e.id) AS entries
                FROM event e
                LEFT JOIN venue v ON e.venue_id = v.id
                ORDER BY e.eventDate DESC, e.division
                LIMIT ?
            SQL, [$limit]);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
