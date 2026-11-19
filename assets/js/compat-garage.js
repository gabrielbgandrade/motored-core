(function(){'use strict';
var K='motored_garage_v1',E='motored:garage',c=window.motoredCompatCfg||{},t=c.i18n||{},b=c.restBase||'',PR={oem:3,alt:2,equiv:1},D=document,B='motored-compat-badge',ts=Date.now,M={};
function gI(s,k){try{return(s?sessionStorage:localStorage).getItem(k);}catch(e){return M[s+k]||null;}}
function sI(s,k,v){M[s+k]=v;try{(s?sessionStorage:localStorage).setItem(k,v);}catch(e){}}
function rI(k){delete M['0'+k];try{localStorage.removeItem(k);}catch(e){}}
function rd(){try{return JSON.parse(gI(0,K))||{active:'',bikes:[],profiles:{}};}catch(e){return{active:'',bikes:[],profiles:{}};}}
function wr(s){sI(0,K,JSON.stringify(s));D.dispatchEvent(new Event(E));}
function mk(g,cl,tx){var e=D.createElement(g);if(cl)e.className=cl;if(tx!=null)e.textContent=tx;return e;}
function bm(m,tx){return mk('p',B+' '+B+'--'+m,tx);}
function lnk(p,u,tx){var a=mk('a',0,tx);a.href=u;p.appendChild(a);return p;}
if(/motored_garage_logout=1/.test(D.cookie)){rI(K);delete M['1mg_s'];try{sessionStorage.removeItem('mg_s');}catch(e){}D.cookie='motored_garage_logout=;Max-Age=0;path=/';}
function chk(q,l,s,cs){
for(var w=0,st=!!c.strict,a=[[q.l,l],[q.s,s],[q.c,cs]],i=0;i<3;i++){var rq=a[i][0],v=a[i][1];if(rq!=null&&rq!==''){if(v==null||v===''){if(st)return null;w=1;}else if(i<2?v<rq:v!==rq)return null;}}
return w;
}
function ev(ft,pf){
for(var p=ft.p,ps=[],m='',w=0,fr=[],i=0,nm,ax,mp,sz,o,k;i<2;i++){
nm=i?'rear':'front';ax=i?'r':'f';mp=pf[nm]||{};sz=ft[ax];o=sz&&mp[sz];
if((p===nm||p==='both'||p==='kit')&&o){
k=chk(o,ft[ax+'l'],ft[ax+'s'],ft.c);
if(k!==null){ps.push(nm);if(!m||(p==='kit'?PR[o.t]<PR[m]:PR[o.t]>PR[m]))m=o.t;w=w||k;if(o.f&&fr.indexOf(o.f)<0)fr.push(o.f);}
}
}
if(p==='kit'&&ps.length<2)return{ok:0};
return ps.length?{ok:1,ps:p==='kit'?['kit']:ps,m:m,w:!!w,fr:fr.join(', ')}:{ok:0};
}
function act(){var s=rd();return s.profiles&&s.profiles[s.active]||null;}
function drawM(){
var pf=act(),sh=c.shopUrl||'/';
D.querySelectorAll('.motored-fit-marker').forEach(function(n){
var sg=n.dataset.ctx==='single',h=sg?n.closest('.motored-compat-banner'):n.parentNode,ol=h&&h.querySelector('.'+B),ft,rs;
if(!h)return;if(ol)ol.remove();try{ft=JSON.parse(n.dataset.motoredFit);}catch(e){return;}
if(!pf){if(sg)h.appendChild(lnk(bm('empty'),sh,t.chooseBike));return;}
rs=ev(ft,pf);
if(rs.ok){
var pt=rs.ps.length===2?t.front+' / '+t.rear:t[rs.ps[0]],el=bm('ok',t.compatWith.replace('%s',pf.label)+' ('+pt+' · '+t[rs.m]+(rs.fr?': '+t.equivNote+' '+rs.fr:'')+')'+(rs.w?' — '+t.verifySpecs:''));
if(sg)h.appendChild(el);else h.insertBefore(el,n.nextSibling);
}else if(sg&&c.showIncomp){
h.appendChild(lnk(bm('no',t.incompatWith.replace('%s',pf.label)+' — '),sh+(sh.indexOf('?')>-1?'&':'?')+'moto='+encodeURIComponent(pf.slug),t.seeCompat));
}
});
}
function api(mt,pt,bd){
if(!c.loggedIn||!b)return Promise.resolve();
var o={method:mt,credentials:'same-origin',headers:{'X-WP-Nonce':c.nonce||''}};
if(bd){o.headers['Content-Type']='application/json';o.body=JSON.stringify(bd);}
return fetch(b+pt,o).then(function(r){if(!r.ok)throw 0;return r.json();}).then(function(d){
if(d&&d.bikes){var p=d.profiles||{};for(var k in p)p[k].ts=ts();wr({active:d.active||'',bikes:d.bikes,profiles:p});}
return d;
});
}
function put(pf,add){
var s=rd();pf.ts=ts();s.profiles=s.profiles||{};s.profiles[pf.slug]=pf;
if(add){s.bikes=s.bikes.filter(function(x){return x.slug!==pf.slug;}).concat({slug:pf.slug,label:pf.label}).slice(-c.maxBikes||-5);s.active=pf.slug;}
wr(s);
}
window.motoredGarageActive=act;window.motoredGarageApi={rd:rd,wr:wr,api:api};
window.motoredGarageSave=function(pf){put(pf,1);return api('POST','/garage',{action:'add',slug:pf.slug}).catch(function(){});};
D.addEventListener(E,drawM);drawM();var a=act(),cur=rd();
if(b&&c.loggedIn&&!gI(1,'mg_s')){api('POST','/garage',{action:'merge',bikes:cur.bikes,active:cur.active}).then(function(){sI(1,'mg_s','1');}).catch(function(){});}
else if(a&&b&&(!a.ts||ts()-a.ts>432e5)){fetch(b+'/compat/bike/'+encodeURIComponent(a.slug)).then(function(r){return r.ok?r.json():0;}).then(function(p){p&&p.slug&&put(p);}).catch(function(){});}
})();
