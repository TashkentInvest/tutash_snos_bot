<div wire:poll.20s style="display:inline-flex;align-items:center;margin-inline-end:.5rem;">
    <a href="{{ $url }}" title="Tasdiqlanmagan tekshiruvlar: {{ $count }}"
       class="snos-bell {{ $count ? 'snos-bell-on' : '' }}" aria-label="Tasdiqlanmagan tekshiruvlar">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" width="24" height="24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
        </svg>
        @if ($count)
            <span class="snos-bell-badge">{{ $count > 99 ? '99+' : $count }}</span>
        @endif
    </a>
    <style>
        .snos-bell { position: relative; display: inline-flex; padding: .4rem; border-radius: 9999px; color: inherit; }
        .snos-bell-on { color: #f59e0b; animation: snosBell 1.4s ease-in-out infinite; }
        .snos-bell-badge { position: absolute; top: -2px; right: -4px; min-width: 18px; height: 18px; padding: 0 4px; border-radius: 9999px;
            background: #ef4444; color: #fff; font-size: 11px; font-weight: 700; line-height: 18px; text-align: center; animation: snosPulse 1.4s ease-in-out infinite; }
        @keyframes snosPulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, .7); } 50% { box-shadow: 0 0 0 7px rgba(239, 68, 68, 0); } }
        @keyframes snosBell { 0%, 100% { transform: rotate(0); } 10%, 30% { transform: rotate(-14deg); } 20%, 40% { transform: rotate(14deg); } 50% { transform: rotate(0); } }
    </style>
</div>
