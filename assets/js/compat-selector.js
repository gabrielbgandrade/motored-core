(function(){'use strict';
var c=window.motoredCompatCfg||{},b=(c.restBase||'').replace(/\/$/,''),t=c.i18n||{},bc;if(!b)return;
function j(u){return fetch(u,{credentials:'same-origin'}).then(function(r){if(!r.ok)throw 0;return r.json();});}
function fill(s,l,ph,vf,tf){
s.textContent='';var o=document.createElement('option');o.value='';o.textContent=ph||'';s.appendChild(o);
for(var i=0;i<l.length;i++){o=document.createElement('option');o.value=vf(l[i]);o.textContent=tf(l[i]);s.appendChild(o);}
s.disabled=!l.length;
}
document.querySelectorAll('.motored-compat-selector').forEach(function(f){
var q=function(r){return f.querySelector('[data-role="'+r+'"]');},bs=q('brand'),ms=q('model'),ys=q('year'),vw=q('variant-wrap'),vs=q('variant'),bt=q('submit'),vr=[],sl='',bSeq=0,mSeq=0;
if(!bs||!ms||!ys||!bt)return;
function rst(){vr=[];sl='';fill(ys,[],t.yearPlaceholder,String,String);if(vw&&vs){vw.hidden=true;vs.textContent='';}bt.disabled=true;}
(bc=bc||j(b+'/compat/brands')).then(function(d){fill(bs,d.brands||[],t.brandPlaceholder,function(x){return x.id;},function(x){return x.name;});}).catch(function(){});
bs.addEventListener('change',function(){var seq=++bSeq;++mSeq;fill(ms,[],t.modelPlaceholder,String,String);rst();if(bs.value)j(b+'/compat/models?brand_id='+encodeURIComponent(bs.value)).then(function(d){if(seq!==bSeq)return;fill(ms,d.models||[],t.modelPlaceholder,function(x){return x.id;},function(x){return x.name;});}).catch(function(){});});
ms.addEventListener('change',function(){var seq=++mSeq;rst();if(ms.value)j(b+'/compat/years?model_id='+encodeURIComponent(ms.value)).then(function(d){if(seq!==mSeq)return;vr=d.versions||[];var s={},u=[];for(var i=0;i<vr.length;i++)if(!s[vr[i].years]){s[vr[i].years]=1;u.push(vr[i]);}fill(ys,u,t.yearPlaceholder,function(x){return x.years;},function(x){return x.years;});}).catch(function(){});});
ys.addEventListener('change',function(){
sl='';bt.disabled=true;if(vw)vw.hidden=true;if(!ys.value)return;
var m=vr.filter(function(v){return v.years===ys.value;});
if(m.length===1){sl=m[0].slug;bt.disabled=false;}
else if(m.length>1&&vw&&vs){vw.hidden=false;fill(vs,m,t.varPlaceholder,function(x){return x.slug;},function(x){return x.variant||x.years;});}
});
if(vs)vs.addEventListener('change',function(){sl=vs.value;bt.disabled=!sl;});
f.addEventListener('submit',function(e){
e.preventDefault();if(!sl)return;bt.disabled=true;
j(b+'/compat/bike/'+encodeURIComponent(sl)).then(function(p){
var sv=window.motoredGarageSave?window.motoredGarageSave(p):Promise.resolve();
return sv.then(function(){if(f.dataset.mode==='garage'){bt.disabled=false;}else{var u=f.dataset.shopUrl||c.shopUrl||'/';location.href=u+(u.indexOf('?')>-1?'&':'?')+'moto='+encodeURIComponent(p.slug);}});
}).catch(function(){bt.disabled=false;});
});
});
})();
