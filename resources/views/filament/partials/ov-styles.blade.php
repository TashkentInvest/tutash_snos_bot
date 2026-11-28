{{-- Bosh sahifa va «Dizayn-kod boti» sahifalaridagi kartochkalar uslubi (bir xil ko'rinish) --}}
    <style>
        .ov{display:flex;flex-direction:column;gap:1rem;--ov-card:#fff;--ov-border:#e5e7eb;--ov-muted:#64748b;--ov-text:#0f172a;--ov-track:#eef0f3}
        .dark .ov{--ov-card:rgb(24 24 27);--ov-border:rgba(255,255,255,.1);--ov-muted:#9ca3af;--ov-text:#f4f4f5;--ov-track:rgba(255,255,255,.08)}
        .ov-card{background:var(--ov-card);border:1px solid var(--ov-border);border-radius:.875rem;box-shadow:0 1px 2px rgba(15,23,42,.04);padding:1.1rem 1.25rem;color:var(--ov-text)}
        .ov-grid{display:grid;gap:1rem}
        .ov-k{grid-template-columns:repeat(4,minmax(0,1fr))}
        .ov-dz{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:.75rem;margin-top:.9rem;padding-top:.8rem;border-top:1px solid var(--ov-border)}
        @media (max-width:1280px){.ov-dz{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media (max-width:640px){.ov-dz{grid-template-columns:repeat(2,minmax(0,1fr))}}
        .ov-mid{grid-template-columns:minmax(0,1.85fr) minmax(0,1fr)}
        .ov-low{grid-template-columns:repeat(3,minmax(0,1fr))}
        @media (max-width:1280px){.ov-k{grid-template-columns:repeat(2,minmax(0,1fr))}.ov-mid,.ov-low{grid-template-columns:minmax(0,1fr)}}
        @media (max-width:640px){.ov-k{grid-template-columns:minmax(0,1fr)}.ov-bar{grid-template-columns:6.5rem minmax(0,1fr) 3.2rem}}
        .ov-label{font-weight:600;font-size:.95rem}
        .ov-big{font-size:2.15rem;font-weight:800;line-height:1.1;letter-spacing:-.02em}
        .ov-muted{color:var(--ov-muted);font-size:.8rem}
        .ov-track{height:.45rem;border-radius:999px;background:var(--ov-track);overflow:hidden;flex:1}
        .ov-track>span{display:block;height:100%;border-radius:999px}
        .ov-h{font-size:1.05rem;font-weight:700;margin-bottom:.9rem}
        .ov-bar{display:grid;grid-template-columns:8.5rem minmax(0,1fr) 3.6rem;align-items:center;gap:.75rem;font-size:.85rem;padding:.22rem 0;color:inherit}
        .ov-bar:hover .ov-track>span{filter:brightness(1.12)}
        .ov-ico{width:2.6rem;height:2.6rem;border-radius:.7rem;display:flex;align-items:center;justify-content:center;flex:none}
        .ov a{color:inherit;text-decoration:none}
        .ov-row{display:flex;justify-content:space-between;gap:.75rem;padding:.3rem 0;border-bottom:1px solid var(--ov-border);font-size:.85rem}
        .ov-row:last-child{border-bottom:0}
    </style>
