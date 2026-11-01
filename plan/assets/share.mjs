import {renderPublic,esc} from './render.mjs';
document.querySelector('#print').addEventListener('click',()=>window.print());
const token=location.hash.slice(1);
// Fragments stay out of HTTP URLs, access logs and referrers. Do not persist the token.
try {
    if(!/^[a-f0-9]{64}$/.test(token)) throw Error('Lenkja er ugyldig eller manglar tilgangsnøkkel.');
    const res=await fetch('api/index.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'public',token}),cache:'no-store',credentials:'omit'});
    const payload=await res.json(); if(!res.ok) throw Error(payload.error||'Kunne ikkje opne planen.');
    document.querySelector('#shared').innerHTML=renderPublic(payload.data);
} catch(e) { document.querySelector('#shared').innerHTML=`<h1>Planen er ikkje tilgjengeleg</h1><p>${esc(e.message)}</p><p class="muted">Be den som delte planen, om ei oppdatert lenkje.</p>`; }
