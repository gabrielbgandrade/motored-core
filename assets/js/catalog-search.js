(function(){'use strict';
var C=window.motoredFiltersCfg||{},B=(C.restBase||'').replace(/\/$/,''),T=C.i18n||{},M={},S_KEY='motored_sizes_'+(C.sizesVer||'1'),szP,brP;
if(!B)return;
function gS(k){try{return localStorage.getItem(k);}catch(e){return M[k]||null;}}
function sS(k,v){M[k]=v;try{localStorage.setItem(k,v);}catch(e){}}
function getJ(u){return fetch(u,{credentials:'same-origin'}).then(function(r){if(!r.ok)throw 0;return r.json();});}
function loadSizes(){
if(szP)return szP;
var raw=gS(S_KEY);
if(raw){try{var p=JSON.parse(raw);if(Array.isArray(p)){szP=Promise.resolve(p);return szP;}}catch(e){}}
szP=getJ(B+'/filters/sizes?v='+encodeURIComponent(C.sizesVer||'1')).then(function(d){var l=d&&d.sizes||[];sS(S_KEY,JSON.stringify(l));return l;}).catch(function(){return[];});
return szP;
}
function fill(s,l,ph,cur,dis){
s.textContent='';
var o=document.createElement('option');o.value='';o.textContent=ph||'';s.appendChild(o);
var ok=0;
for(var i=0;i<l.length;i++){o=document.createElement('option');o.value=l[i].v;o.textContent=l[i].l;if(l[i].v===cur)ok=1;s.appendChild(o);}
s.value=ok?cur:'';s.disabled=!!dis||!l.length;
}
function uniq(list,vk,lk){
var m={},o=[];
for(var i=0;i<list.length;i++){var v=list[i][vk];if(v!==null&&v!==''&&!m[v]){m[v]=1;o.push({v:String(v),l:String(list[i][lk]||v)});}}
return o;
}
document.querySelectorAll('[data-motored-search]').forEach(function(root){
var shop=root.dataset.shopUrl||C.shopUrl||'/';
// Tabs switching
var tabs=root.querySelectorAll('[data-tab]'),panels=root.querySelectorAll('[data-panel]');
tabs.forEach(function(tb){
tb.addEventListener('click',function(){
var tg=tb.dataset.tab;
tabs.forEach(function(x){var on=x===tb;x.classList.toggle('is-active',on);x.setAttribute('aria-selected',on?'true':'false');});
panels.forEach(function(p){var on=p.dataset.panel===tg;p.classList.toggle('is-active',on);p.hidden=!on;});
});
});
// Measure cascade (any order, imperial no-profile aware, rim-only supported)
var sf=root.querySelector('[data-role="size-form"]');
if(sf){
var ws=sf.querySelector('[data-role="width"]'),ps=sf.querySelector('[data-role="profile"]'),rs=sf.querySelector('[data-role="rim"]'),sb=sf.querySelector('[data-role="size-submit"]'),all=[];
function syncSizes(){
var w=ws.value,p=ps.value,r=rs.value;
var forW=all.filter(function(x){return(!p||x.p===p)&&(!r||x.r===r);});
var forP=all.filter(function(x){return(!w||x.w===w)&&(!r||x.r===r);});
var forR=all.filter(function(x){return(!w||x.w===w)&&(!p||x.p===p);});
fill(ws,uniq(forW,'w','wl'),T.widthPlaceholder,w,false);
var pOpts=uniq(forP,'p','pl');
var noProf=w&&!pOpts.length;
fill(ps,pOpts,noProf?T.noProfile:T.profilePlaceholder,noProf?'':p,noProf);
fill(rs,uniq(forR,'r','rl'),T.rimPlaceholder,r,false);
sb.disabled=!(rs.value||(ws.value&&rs.value));
}
loadSizes().then(function(l){all=l;syncSizes();});
ws.addEventListener('change',syncSizes);
ps.addEventListener('change',syncSizes);
rs.addEventListener('change',syncSizes);
sf.addEventListener('submit',function(e){
e.preventDefault();
var q=[];
if(ws.value){q.push('filter_ancho='+encodeURIComponent(ws.value),'query_type_ancho=or');}
if(ps.value&&!ps.disabled){q.push('filter_perfil='+encodeURIComponent(ps.value),'query_type_perfil=or');}
if(rs.value){q.push('filter_rodado='+encodeURIComponent(rs.value),'query_type_rodado=or');}
if(!q.length)return;
location.href=shop+(shop.indexOf('?')>-1?'&':'?')+q.join('&');
});
}
// Motorcycle cascade (reuses Compatibility REST + Mi Garage)
var mf=root.querySelector('[data-role="moto-form"]');
if(mf){
var bs=mf.querySelector('[data-role="brand"]'),ms=mf.querySelector('[data-role="model"]'),ys=mf.querySelector('[data-role="year"]'),vw=mf.querySelector('[data-role="variant-wrap"]'),vs=mf.querySelector('[data-role="variant"]'),mb=mf.querySelector('[data-role="moto-submit"]'),vr=[],sl='',bSeq=0,mSeq=0;
function rstM(){vr=[];sl='';fill(ys,[],T.yearPlaceholder,'',true);if(vw&&vs){vw.hidden=true;vs.textContent='';}mb.disabled=true;}
(brP=brP||getJ(B+'/compat/brands')).then(function(d){fill(bs,(d.brands||[]).map(function(x){return{v:String(x.id),l:x.name};}),T.brandPlaceholder,'',false);}).catch(function(){});
bs.addEventListener('change',function(){var q=++bSeq;++mSeq;fill(ms,[],T.modelPlaceholder,'',true);rstM();if(bs.value)getJ(B+'/compat/models?brand_id='+encodeURIComponent(bs.value)).then(function(d){if(q!==bSeq)return;fill(ms,(d.models||[]).map(function(x){return{v:String(x.id),l:x.name};}),T.modelPlaceholder,'',false);}).catch(function(){});});
ms.addEventListener('change',function(){var q=++mSeq;rstM();if(ms.value)getJ(B+'/compat/years?model_id='+encodeURIComponent(ms.value)).then(function(d){if(q!==mSeq)return;vr=d.versions||[];var s={},u=[];for(var i=0;i<vr.length;i++)if(!s[vr[i].years]){s[vr[i].years]=1;u.push({v:vr[i].years,l:vr[i].years});}fill(ys,u,T.yearPlaceholder,'',false);}).catch(function(){});});
ys.addEventListener('change',function(){
sl='';mb.disabled=true;if(vw)vw.hidden=true;if(!ys.value)return;
var m=vr.filter(function(v){return v.years===ys.value;});
if(m.length===1){sl=m[0].slug;mb.disabled=false;}
else if(m.length>1&&vw&&vs){vw.hidden=false;fill(vs,m.map(function(x){return{v:x.slug,l:x.variant||x.years};}),T.varPlaceholder,'',false);}
});
if(vs)vs.addEventListener('change',function(){sl=vs.value;mb.disabled=!sl;});
mf.addEventListener('submit',function(e){
e.preventDefault();if(!sl)return;mb.disabled=true;
getJ(B+'/compat/bike/'+encodeURIComponent(sl)).then(function(p){
var sv=window.motoredGarageSave?window.motoredGarageSave(p):Promise.resolve();
return sv.then(function(){location.href=shop+(shop.indexOf('?')>-1?'&':'?')+'moto='+encodeURIComponent(p.slug);});
}).catch(function(){mb.disabled=false;});
});
}
// Smart search autocomplete (250ms debounce, min 2 chars, Enter submits native WC search)
var sm=root.querySelector('[data-role="smart-form"]');
if(sm){
var inp=sm.querySelector('[data-role="smart-input"]'),lst=sm.querySelector('[data-role="smart-list"]'),tmr,sSeq=0;
function closeSug(){lst.hidden=true;lst.textContent='';inp.setAttribute('aria-expanded','false');}
inp.addEventListener('input',function(){
clearTimeout(tmr);
var q=inp.value.trim();
if(q.length<2){closeSug();return;}
tmr=setTimeout(function(){
var seq=++sSeq;
getJ(B+'/filters/suggest?q='+encodeURIComponent(q)).then(function(d){
if(seq!==sSeq)return;
var items=d&&d.items||[];
lst.textContent='';
if(!items.length){closeSug();return;}
items.forEach(function(it){
var li=document.createElement('li'),a=document.createElement('a'),tx=document.createElement('span'),mt=document.createElement('span');
a.href=it.url;tx.textContent=it.label;mt.className='motored-search__suggest-meta';mt.textContent=it.meta||'';
a.appendChild(tx);a.appendChild(mt);
if(it.type==='moto'&&it.slug&&window.motoredGarageSave){
a.addEventListener('click',function(ev){
ev.preventDefault();
getJ(B+'/compat/bike/'+encodeURIComponent(it.slug)).then(function(p){return window.motoredGarageSave(p);}).catch(function(){}).then(function(){location.href=it.url;});
});
}
li.appendChild(a);lst.appendChild(li);
});
lst.hidden=false;inp.setAttribute('aria-expanded','true');
}).catch(closeSug);
},250);
});
document.addEventListener('click',function(e){if(!sm.contains(e.target))closeSug();});
inp.addEventListener('keydown',function(e){if(e.key==='Escape')closeSug();});
}
});
})();
