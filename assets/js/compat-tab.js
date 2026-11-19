(function(){'use strict';
var C=window.motoredCompatCfg||{},T=C.i18n||{};
document.querySelectorAll('[data-compat-list]').forEach(function(root){
var inp=root.querySelector('[data-role="compat-search"]'),grid=root.querySelector('[data-role="compat-grid"]'),tog=root.querySelector('[data-role="compat-toggle"]'),gar=root.querySelector('.motored-compat-tab__garage'),hasMore=root.dataset.hasMore==='1',url=root.dataset.url||'',exp=false,loaded=false;
function rows(){return grid?Array.prototype.slice.call(grid.querySelectorAll('[data-brand-row]')):[];}
function applyFilter(){
var q=inp?inp.value.trim().toLowerCase():'';
rows().forEach(function(li){
if(q){li.hidden=(li.dataset.search||'').indexOf(q)<0;}
else{li.hidden=!exp&&li.dataset.extra==='1';}
});
if(tog)tog.hidden=!!q;
}
function hydrateFull(){
if(!hasMore||loaded||!url||!grid)return Promise.resolve();
loaded=true;
return fetch(url,{credentials:'same-origin'}).then(function(r){if(!r.ok)throw 0;return r.json();}).then(function(d){
var list=d&&d.motos||[],byB={},pm={front:T.front||'front',rear:T.rear||'rear'};
list.forEach(function(m){(byB[m.brand]=byB[m.brand]||[]).push(m);});
grid.textContent='';
Object.keys(byB).sort().forEach(function(bn,idx){
var li=document.createElement('li'),sp1=document.createElement('span'),sp2=document.createElement('span'),sTx=[bn.toLowerCase()],slg=[],pts=[];
li.className='motored-compat-list__brand';li.dataset.brandRow='';li.dataset.extra=idx>=6?'1':'0';
byB[bn].forEach(function(m){
slg.push(m.slug||'');
sTx.push((m.model+' '+(m.aliases||'')+' '+(m.variant||'')+' '+m.years).toLowerCase());
var pt=(m.positions&&m.positions.length>=2&&T.kit)?T.kit:(m.positions||[]).map(function(x){return pm[x]||x;}).join(' / ');
var ml=m.match==='equiv'?(T.equiv||'Equivalente')+(m.equiv_from?' ('+m.equiv_from+')':''):(T[m.match]||m.match);
pts.push((m.model+(m.variant?' '+m.variant:''))+' ('+[m.years,pt,ml].filter(Boolean).join(' · ')+')');
});
li.dataset.search=sTx.join(' ');li.dataset.slugs=slg.join(',');
sp1.className='motored-compat-list__brand-name';sp1.textContent=bn+': ';
sp2.className='motored-compat-list__models';sp2.textContent=pts.join('; ');
li.appendChild(sp1);li.appendChild(sp2);grid.appendChild(li);
});
hasMore=false;applyFilter();drawMine();
}).catch(function(){loaded=false;});
}
function drawMine(){
if(!gar)return;
gar.textContent='';
var pf=window.motoredGarageActive?window.motoredGarageActive():null;
if(!pf)return;
var hit=rows().some(function(li){return(','+(li.dataset.slugs||'')+',').indexOf(','+pf.slug+',')>-1;});
var p=document.createElement('p');p.className='motored-compat-list__mine';
p.textContent=(hit?(T.compatWith||''):(T.incompatWith||'')).replace('%s',pf.label);
gar.appendChild(p);
}
if(tog){
tog.addEventListener('click',function(){
exp=!exp;tog.setAttribute('aria-expanded',exp?'true':'false');
if(exp&&hasMore){hydrateFull();}else{applyFilter();}
});
}
if(inp){
inp.addEventListener('input',function(){
if(hasMore&&inp.value.trim().length>=2){hydrateFull();}else{applyFilter();}
});
}
drawMine();
document.addEventListener('motored:garage',drawMine);
});
})();
