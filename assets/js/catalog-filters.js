(function(){'use strict';
document.querySelectorAll('[data-motored-filters]').forEach(function(box){
var base=box.dataset.baseUrl||'/',moto=box.dataset.moto||'',pos=box.dataset.pos||'',apply=box.querySelector('[data-role="apply"]'),extra={},tmr;
try{extra=JSON.parse(box.dataset.extra||'{}')||{};}catch(e){}
// Accessible accordion toggles
box.querySelectorAll('.motored-acc__btn').forEach(function(btn){
btn.addEventListener('click',function(){
var open=btn.getAttribute('aria-expanded')==='true',p=document.getElementById(btn.getAttribute('aria-controls')||''),ic=btn.querySelector('.motored-acc__icon');
btn.setAttribute('aria-expanded',open?'false':'true');
if(p)p.hidden=open;
if(ic)ic.textContent=open?'+':'−';
});
});
function buildUrl(){
var params=[],byFacet={};
for(var k in extra){if(extra[k])params.push(encodeURIComponent(k)+'='+encodeURIComponent(extra[k]));}
if(moto){params.push('moto='+encodeURIComponent(moto));if(pos)params.push('pos='+encodeURIComponent(pos));}
box.querySelectorAll('.motored-opt.is-active').forEach(function(a){
var f=a.dataset.facet,s=a.dataset.slug;if(!f||!s)return;
byFacet[f]=byFacet[f]||[];if(byFacet[f].indexOf(s)<0)byFacet[f].push(s);
});
for(var fk in byFacet){
if(fk==='oferta'){params.push('oferta=1');}
else if(fk==='product_brand'){params.push('product_brand='+byFacet[fk].map(encodeURIComponent).join(','));}
else{params.push('filter_'+encodeURIComponent(fk)+'='+byFacet[fk].map(encodeURIComponent).join(','),'query_type_'+encodeURIComponent(fk)+'=or');}
}
return params.length?base+(base.indexOf('?')>-1?'&':'?')+params.join('&'):base;
}
function go(){location.href=buildUrl();}
if(apply)apply.addEventListener('click',go);
box.querySelectorAll('.motored-opt').forEach(function(a){
a.addEventListener('click',function(e){
if(e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
e.preventDefault();
var on=!a.classList.contains('is-active');
a.classList.toggle('is-active',on);
a.setAttribute('aria-pressed',on?'true':'false');
clearTimeout(tmr);
if(window.innerWidth<768&&apply){apply.hidden=false;}
else{tmr=setTimeout(go,700);}
});
});
});
})();
