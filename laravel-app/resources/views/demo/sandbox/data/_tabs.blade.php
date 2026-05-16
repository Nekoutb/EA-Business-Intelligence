@php $tab = $tab ?? 'invoices'; @endphp
<div class="flex gap-1 border-b border-stone-200 mb-5">
    @foreach ([
        ['invoices',  '/demo/sandbox/data/invoices',  'Invoices'],
        ['journals',  '/demo/sandbox/data/journals',  'Journal entries'],
        ['payments',  '/demo/sandbox/data/payments',  'Payments'],
        ['customers', '/demo/sandbox/data/customers', 'Customers'],
    ] as [$slug, $url, $label])
        <a href="{{ $url }}"
           class="px-4 py-2.5 text-[12px] font-semibold border-b-2 -mb-[1px] transition
                  {{ $tab === $slug
                      ? 'border-amber-500 text-amber-700'
                      : 'border-transparent text-slate-500 hover:text-slate-900' }}">
            {{ $label }}
        </a>
    @endforeach
</div>
