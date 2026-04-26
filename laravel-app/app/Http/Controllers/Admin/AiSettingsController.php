<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GlobalSetting;
use Illuminate\Http\Request;

/**
 * Platform-wide AI provider configuration. Super-admin only.
 *
 * The platform pays the LLM bill, so the provider, model and API key live in
 * one place (global_settings) and apply to every tenant. Tenants get insights
 * automatically; they don't need to bring their own key.
 */
class AiSettingsController extends Controller
{
    /** Keys exposed in the AI settings UI. Stored in global_settings. */
    private const FIELDS = ['AI_PROVIDER', 'AI_MODEL', 'AI_API_KEY'];

    /** Allowed provider values; the AIAdvisorService also accepts 'claude' as an alias. */
    public const PROVIDERS = [
        'anthropic' => 'Anthropic (Claude)',
        'openai'    => 'OpenAI (GPT)',
    ];

    /** Suggested defaults so the form is never blank for a new install. */
    public const MODEL_SUGGESTIONS = [
        'anthropic' => 'claude-sonnet-4-20250514',
        'openai'    => 'gpt-4o-mini',
    ];

    public function show()
    {
        $values = [];
        foreach (self::FIELDS as $k) {
            $row = GlobalSetting::where('key', $k)->first();
            // Never echo secrets back into the form.
            $values[$k] = $row && !GlobalSetting::shouldEncrypt($k) ? (string) $row->value : '';
            $values["__has_{$k}"] = $row !== null && $row->value !== null && $row->value !== '';
        }

        return view('admin.settings.ai', [
            'values'     => $values,
            'providers'  => self::PROVIDERS,
            'modelHints' => self::MODEL_SUGGESTIONS,
            'active'     => 'ai_settings',
        ]);
    }

    public function save(Request $request)
    {
        $request->validate([
            'AI_PROVIDER' => ['required', 'in:anthropic,openai'],
            'AI_MODEL'    => ['nullable', 'string', 'max:191'],
            'AI_API_KEY'  => ['nullable', 'string'],
        ]);

        $changed = [];
        foreach (self::FIELDS as $k) {
            if (!$request->has($k)) continue;
            $val = trim((string) $request->input($k));

            // Leave the secret untouched when the field was submitted blank.
            if (GlobalSetting::shouldEncrypt($k) && $val === '') {
                continue;
            }

            $row = GlobalSetting::firstOrNew(['key' => $k]);
            $row->value = $val;
            $row->save();
            $changed[] = $k;
        }

        AuditLog::record('global.ai_settings.update', [
            'target'  => 'ai_settings',
            'payload' => ['keys' => $changed],
        ]);

        return back()->with('flash', [
            'type' => 'success',
            'msg'  => 'AI provider settings saved (' . count($changed) . ' field' . (count($changed) === 1 ? '' : 's') . ' updated).',
        ]);
    }
}
