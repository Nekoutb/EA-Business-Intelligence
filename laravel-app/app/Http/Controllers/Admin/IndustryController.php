<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Industry;
use App\Models\IndustryModule;
use Illuminate\Http\Request;

class IndustryController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $query = Industry::withCount('tenants');
        if ($q !== '') {
            $query->where(fn ($w) => $w->where('key', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"));
        }

        $industries = $query->orderBy('sort_order')->paginate(25)->withQueryString();

        return view('admin.industries.index', [
            'industries' => $industries,
            'q'          => $q,
            'active'     => 'industries',
        ]);
    }

    public function create()
    {
        return view('admin.industries.create', [
            'active' => 'industries',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'key'         => ['required', 'string', 'max:64', 'alpha_dash', 'unique:industries,key'],
            'name'        => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'icon'        => ['nullable', 'string'],
            'sort_order'  => ['nullable', 'integer'],
            'active'      => ['nullable', 'boolean'],
        ]);

        Industry::create([
            'key'         => $data['key'],
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'icon'        => $data['icon'] ?? null,
            'sort_order'  => $data['sort_order'] ?? 0,
            'active'      => $data['active'] ?? true,
        ]);

        return redirect('/admin/industries')->with('flash', [
            'type' => 'success',
            'msg'  => "Industry '{$data['name']}' created.",
        ]);
    }

    public function edit(Industry $industry)
    {
        return view('admin.industries.edit', [
            'industry' => $industry,
            'active'   => 'industries',
        ]);
    }

    public function update(Request $request, Industry $industry)
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'icon'        => ['nullable', 'string'],
            'sort_order'  => ['nullable', 'integer'],
            'active'      => ['nullable', 'boolean'],
        ]);

        $industry->update($data);

        return redirect("/admin/industries/{$industry->id}/edit")->with('flash', [
            'type' => 'success',
            'msg'  => "Industry updated.",
        ]);
    }

    public function editModules(Industry $industry)
    {
        $allModules = collect(config('modules.modules', []))->keyBy('key');
        $industryModules = $industry->modules()->get()->keyBy('module_key');

        $modules = $allModules->map(function ($module, $key) use ($industryModules) {
            $im = $industryModules->get($key);
            return [
                'key'                => $key,
                'label'              => $module['label'],
                'enabled_by_default' => $im?->enabled_by_default ?? false,
                'required'           => $im?->required ?? false,
            ];
        });

        return view('admin.industries.edit-modules', [
            'industry' => $industry,
            'modules'  => $modules,
            'active'   => 'industries',
        ]);
    }

    public function updateModules(Request $request, Industry $industry)
    {
        $data = $request->validate([
            'modules'              => ['array'],
            'modules.*.key'        => ['string'],
            'modules.*.default'    => ['nullable', 'boolean'],
            'modules.*.required'   => ['nullable', 'boolean'],
        ]);

        $industry->modules()->delete();

        foreach ($data['modules'] ?? [] as $mod) {
            if (!empty($mod['key'])) {
                IndustryModule::create([
                    'industry_id'        => $industry->id,
                    'module_key'         => $mod['key'],
                    'enabled_by_default' => $mod['default'] ?? false,
                    'required'           => $mod['required'] ?? false,
                ]);
            }
        }

        return redirect("/admin/industries/{$industry->id}/edit-modules")->with('flash', [
            'type' => 'success',
            'msg'  => "Module bundle updated.",
        ]);
    }

    public function destroy(Industry $industry)
    {
        if ($industry->tenants()->exists()) {
            return back()->withErrors(['message' => 'Cannot delete industry with associated tenants.']);
        }

        $industry->delete();

        return redirect('/admin/industries')->with('flash', [
            'type' => 'success',
            'msg'  => "Industry deleted.",
        ]);
    }
}
