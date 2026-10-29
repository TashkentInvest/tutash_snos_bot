<div wire:poll.60s style="display:inline-flex;align-items:center;margin-inline-end:.25rem;">
    @if ($count)
        <a href="{{ $url }}" class="snos-unreg"
           title="{{ "Mas’uli botda ro‘yxatdan o‘tmagan tumanlar ({$count}):\n".implode("\n", $names) }}"
           aria-label="Ro‘yxatdan o‘tmagan tumanlar: {{ $count }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" width="22" height="22">
                <path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 10.374 21c-2.331 0-4.512-.645-6.374-1.766Z"/>
            </svg>
            <span class="snos-unreg-text">Ro‘yxatdan o‘tmagan: {{ $count }}</span>
        </a>
    @endif
    <style>
        .snos-unreg { display: inline-flex; align-items: center; gap: .35rem; padding: .3rem .6rem; border-radius: 9999px;
            font-size: .8rem; font-weight: 600; color: #b91c1c; background: rgba(239, 68, 68, .12); }
        .dark .snos-unreg { color: #fca5a5; background: rgba(239, 68, 68, .18); }
        .snos-unreg:hover { background: rgba(239, 68, 68, .22); }
        @media (max-width: 640px) { .snos-unreg-text { display: none; } }
    </style>
</div>
