<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Models\Demo\DemoCommandAudit;
use App\Services\Demo\DemoCommandExecutor;
use App\Services\Demo\DemoCommandParser;
use Illuminate\Http\Request;

/**
 * Natural-language console — accepts free-text commands, parses them via
 * Claude into structured operations, applies them to the demo tables,
 * and shows the audit trail back to the visitor.
 */
class DemoConsoleController extends Controller
{
    public function index(Request $request)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $audit = DemoCommandAudit::where('tenant_id', $tenant->id)
            ->orderByDesc('applied_at')
            ->limit(50)
            ->get();
        return view('demo.sandbox.console', [
            'audit'  => $audit,
            'tenant' => $tenant,
            'active' => 'console',
        ]);
    }

    public function run(Request $request, DemoCommandParser $parser, DemoCommandExecutor $executor)
    {
        $data = $request->validate([
            'command' => ['required', 'string', 'max:2000'],
        ]);
        $tenant = $request->attributes->get('demo_tenant');

        $op = $parser->parse($tenant, $data['command']);
        $result = $executor->execute($tenant, $op, $data['command']);

        return redirect('/demo/sandbox/console')->with('flash', [
            'type' => $result['success'] ? 'success' : 'error',
            'msg'  => $result['message'],
        ]);
    }
}
